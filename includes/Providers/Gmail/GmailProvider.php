<?php
/**
 * Gmail API mail provider using OAuth 2.0 (gmail.send scope only).
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Gmail;

use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends via the Gmail REST API using Bearer token authentication.
 *
 * Required config keys (from SettingsRepository::get_gmail_config()):
 *   - access_token  string  Decrypted OAuth 2.0 Bearer token.
 *   - from_email    string  Authorized Gmail address (sender).
 *   - from_name     string  Optional display name.
 *
 * The provider operates in a single-attempt boundary: no automatic retries,
 * no inferred delivery, and no token refresh side-effects inside send().
 * Token refresh is the caller's responsibility (SettingsRepository::get_gmail_config).
 *
 * @security access_token must never appear in logs, exceptions, or the SendResult.
 */
class GmailProvider implements ProviderInterface {

	private const SEND_URI     = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
	private const TOKENINFO_URI = 'https://oauth2.googleapis.com/tokeninfo';

	/** @inheritDoc */
	public function get_id(): string {
		return 'gmail';
	}

	/** @inheritDoc */
	public function get_label(): string {
		return 'Gmail / Google Workspace';
	}

	/** @inheritDoc */
	public function get_capabilities(): array {
		return array( 'html' );
	}

	/**
	 * Validates that the minimum required configuration is present.
	 *
	 * Makes no network calls.
	 *
	 * @param array<string, mixed> $config Provider configuration from SettingsRepository.
	 * @return ValidationResult
	 */
	public function validate_config( array $config ): ValidationResult {
		$errors = array();
		$token  = $config['access_token'] ?? null;
		$email  = $config['from_email'] ?? null;
		$name   = $config['from_name'] ?? '';

		if ( ! is_string( $token ) || '' === $token ) {
			$errors['access_token'] = 'No Gmail access token is available. Connect your Google account first.';
		}
		if ( ! is_string( $email ) || '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['from_email'] = 'A valid authorized Gmail sender address is required.';
		}
		if ( ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name ) ) {
			$errors['from_name'] = 'The sender name is invalid.';
		}
		return new ValidationResult( array() === $errors, $errors );
	}

	/**
	 * Checks whether the stored access token is accepted by Google without sending mail.
	 *
	 * Calls the Google tokeninfo endpoint, which accepts any valid access token
	 * and returns scope/expiry metadata without requiring additional scopes.
	 *
	 * @param array<string, mixed> $config Provider configuration from SettingsRepository.
	 * @return ConnectionResult
	 */
	public function test_connection( array $config ): ConnectionResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new ConnectionResult( false, 'Gmail configuration is incomplete. Connect a Google account first.', failure_category: TransportFailureCategory::CONFIG );
		}

		try {
			$result = $this->tokeninfo( (string) $config['access_token'] );
		} catch ( \Throwable $error ) {
			return new ConnectionResult( false, 'Could not verify the Gmail token. The token may be expired or revoked. Try reconnecting.', failure_category: TransportFailureCategory::AUTH );
		}

		if ( ! $result['valid'] ) {
			return new ConnectionResult( false, 'Gmail token verification failed. The token may be expired or revoked. Try reconnecting.', failure_category: TransportFailureCategory::AUTH );
		}

		$has_scope = str_contains( $result['scope'] ?? '', 'gmail.send' );
		if ( ! $has_scope ) {
			return new ConnectionResult( false, 'The connected account does not have the gmail.send scope. Reconnect and ensure the Gmail send permission is granted.', failure_category: TransportFailureCategory::AUTH );
		}

		return new ConnectionResult( true, 'Gmail access token accepted. No email was sent; sender authorization and delivery remain unverified.' );
	}

	/**
	 * Sends a mail message through the Gmail API.
	 *
	 * Builds a minimal RFC 2822 message and submits it as a base64url-encoded
	 * raw message via the Gmail REST API. Reports acceptance separately from
	 * inbox delivery.
	 *
	 * @param MailMessage          $message The prepared message to send.
	 * @param array<string, mixed> $config  Provider configuration from SettingsRepository.
	 * @return SendResult
	 */
	public function send( MailMessage $message, array $config ): SendResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new SendResult( false, 'gmail', null, null, 'Gmail configuration is incomplete. Connect a Google account first.', false, TransportFailureCategory::CONFIG );
		}

		$token = (string) $config['access_token'];

		try {
			$raw_message = $this->build_rfc2822( $message, $config );
		} catch ( \InvalidArgumentException $error ) {
			return new SendResult( false, 'gmail', null, null, $error->getMessage(), false, TransportFailureCategory::CONFIG );
		} catch ( \Throwable $error ) {
			return new SendResult( false, 'gmail', null, null, 'Message preparation failed before sending.', false, TransportFailureCategory::CONFIG );
		}

		$response = $this->gmail_send( $token, $raw_message );
		$code     = $response['code'];
		$id       = $this->extract_message_id( $response );

		if ( null !== $id ) {
			return new SendResult( true, 'gmail', $id, (string) $code, 'Message accepted by the Gmail API; delivery is unconfirmed.' );
		}

		if ( 401 === $code || 403 === $code ) {
			return new SendResult( false, 'gmail', null, (string) $code, 'Gmail authorization failed. The token may be expired or the account may lack send permission. Reconnect in Providers.', false, TransportFailureCategory::AUTH );
		}
		if ( $code >= 400 && $code < 500 ) {
			return new SendResult( false, 'gmail', null, (string) $code, 'Gmail rejected the request. Check the authorized sender address and account restrictions. No automatic retry was attempted.', false, TransportFailureCategory::PROVIDER_REJECTION );
		}
		return new SendResult( false, 'gmail', null, $code ? (string) $code : null, 'Gmail acceptance is unconfirmed. Check provider activity before retrying to avoid duplicates. No automatic retry was attempted.', false, TransportFailureCategory::UNKNOWN, array(), true );
	}

	/**
	 * Builds a minimal RFC 2822 message string from a MailMessage.
	 *
	 * Uses CRLF line endings as required by RFC 2822.
	 *
	 * @param MailMessage          $message Prepared message.
	 * @param array<string, mixed> $config  Provider configuration.
	 * @return string Base64url-encoded RFC 2822 message ready for the Gmail API.
	 * @throws \InvalidArgumentException On unsupported content or invalid recipients.
	 */
	private function build_rfc2822( MailMessage $message, array $config ): string {
		if ( ! in_array( $message->content_type, array( 'text/plain', 'text/html' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported message content type.' );
		}
		if ( '' === $message->body ) {
			throw new \InvalidArgumentException( 'Message body is empty.' );
		}
		if ( preg_match( '/[\r\n\x00]/', $message->subject ) ) {
			throw new \InvalidArgumentException( 'Message subject contains invalid characters.' );
		}
		if ( empty( $message->to ) ) {
			throw new \InvalidArgumentException( 'No recipient addresses provided.' );
		}

		$from_email = (string) ( $config['from_email'] ?? '' );
		$from_name  = (string) ( $config['from_name'] ?? '' );

		$to_addresses = array();
		foreach ( $message->to as $recipient ) {
			$addr = trim( (string) $recipient );
			if ( '' === $addr || ! filter_var( $this->extract_email( $addr ), FILTER_VALIDATE_EMAIL ) ) {
				throw new \InvalidArgumentException( 'One or more recipient addresses are not valid.' );
			}
			$to_addresses[] = $addr;
		}

		$crlf      = "\r\n";
		$from_hdr  = '' !== $from_name
			? '=?UTF-8?B?' . base64_encode( $from_name ) . '?= <' . $from_email . '>'
			: $from_email;
		$subj_hdr  = '=?UTF-8?B?' . base64_encode( $message->subject ) . '?=';
		$mime_type = 'text/html' === $message->content_type ? 'text/html' : 'text/plain';

		$headers  = 'MIME-Version: 1.0' . $crlf;
		$headers .= 'From: ' . $from_hdr . $crlf;
		$headers .= 'To: ' . implode( ', ', $to_addresses ) . $crlf;
		$headers .= 'Subject: ' . $subj_hdr . $crlf;
		$headers .= 'Content-Type: ' . $mime_type . '; charset=UTF-8' . $crlf;
		$headers .= 'Content-Transfer-Encoding: base64' . $crlf;
		$headers .= 'X-Scalyn-UUID: ' . $message->uuid . $crlf;

		$raw = $headers . $crlf . chunk_split( base64_encode( $message->body ), 76, $crlf );

		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Posts the encoded message to the Gmail API send endpoint.
	 *
	 * @param string $token       Bearer access token.
	 * @param string $raw_message Base64url-encoded RFC 2822 message.
	 * @return array{code: int, data: array}
	 */
	private function gmail_send( #[\SensitiveParameter] string $token, string $raw_message ): array {
		try {
			$response = $this->http(
				self::SEND_URI,
				array(
					'method'              => 'POST',
					'timeout'             => 15,
					'redirection'        => 0,
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => 32768,
					'headers'             => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					// phpcs:ignore WordPress.WP.AlternativeFunctions -- JSON encoding, no UTF-8 alteration.
					'body' => json_encode( array( 'raw' => $raw_message ), JSON_THROW_ON_ERROR ),
				)
			);
			$code = is_array( $response ) ? ( (int) ( $response['response']['code'] ?? 0 ) ) : 0;
			$body = is_array( $response ) ? ( $response['body'] ?? '' ) : '';
			$data = is_string( $body ) ? json_decode( $body, true, 4 ) : null;
			return array(
				'code' => ( $code >= 100 && $code <= 599 ) ? $code : 0,
				'data' => is_array( $data ) ? $data : array(),
			);
		} catch ( \Throwable $error ) {
			return array( 'code' => 0, 'data' => array() );
		}
	}

	/**
	 * Calls the Google tokeninfo endpoint to verify an access token.
	 *
	 * The tokeninfo endpoint is a public endpoint that accepts an access token
	 * as a query parameter and returns scope/expiry info without additional scopes.
	 *
	 * @param string $token Access token to verify.
	 * @return array{valid: bool, scope: string}
	 * @throws \RuntimeException On network failure.
	 */
	private function tokeninfo( #[\SensitiveParameter] string $token ): array {
		try {
			$response = $this->http(
				self::TOKENINFO_URI . '?access_token=' . rawurlencode( $token ),
				array(
					'method'              => 'GET',
					'timeout'             => 10,
					'redirection'        => 0,
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => 8192,
				)
			);
			$code = is_array( $response ) ? ( (int) ( $response['response']['code'] ?? 0 ) ) : 0;
			$body = is_array( $response ) ? ( $response['body'] ?? '' ) : '';
			$data = is_string( $body ) ? json_decode( $body, true, 4 ) : null;
			if ( 200 !== $code || ! is_array( $data ) ) {
				return array( 'valid' => false, 'scope' => '' );
			}
			return array(
				'valid' => ! isset( $data['error'] ),
				'scope' => is_string( $data['scope'] ?? null ) ? $data['scope'] : '',
			);
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Token verification network failure.' );
		}
	}

	/**
	 * Extracts the message ID from a Gmail API send response.
	 *
	 * The Gmail API returns an `id` field (not `messageId`) on success.
	 * The id is an opaque hex string; we store it as the provider message ID.
	 *
	 * @param array $response HTTP response data.
	 * @return string|null Provider message ID or null if unaccepted.
	 */
	private function extract_message_id( array $response ): ?string {
		if ( 200 !== $response['code'] ) {
			return null;
		}
		$id = $response['data']['id'] ?? null;
		return is_string( $id ) && strlen( $id ) >= 1 && strlen( $id ) <= 255
			&& preg_match( '/^[A-Za-z0-9]+$/D', $id )
			? $id
			: null;
	}

	/**
	 * Extracts the bare email address from an RFC 5321 formatted address.
	 *
	 * @param string $address Raw address, possibly in "Name <email>" format.
	 * @return string Bare email address.
	 */
	private function extract_email( string $address ): string {
		if ( preg_match( '/^.+\s*<([^>]+)>\s*$/', trim( $address ), $matches ) ) {
			return trim( $matches[1] );
		}
		return trim( $address );
	}

	/**
	 * Makes an HTTP request; override in tests to inject a stub.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args WP HTTP API arguments.
	 * @return mixed WP_HTTP response array or WP_Error.
	 */
	protected function http( string $url, array $args ): mixed {
		return wp_safe_remote_request( $url, $args );
	}
}
