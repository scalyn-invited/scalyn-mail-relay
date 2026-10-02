<?php
/**
 * Postmark transactional Email API adapter.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Postmark;

use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

defined( 'ABSPATH' ) || exit;

/** Bounded, live-server-only adapter; never retries or interprets acceptance as delivery. */
class PostmarkProvider implements ProviderInterface {
	private const MAX_RECIPIENTS = 50;
	private const MAX_BODY_BYTES = 1048576;
	private const MAX_FILE_BYTES = 4194304;
	private const MAX_FILES      = 10;
	private const MAX_JSON_BYTES = 8388608;
	private const SAFE_HEADERS   = array( 'x-priority', 'x-mailer', 'list-unsubscribe' );
	private const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

	/** Provider identity. */
	public function get_id(): string {
		return 'postmark'; }

	/** Public label. */
	public function get_label(): string {
		return 'Postmark API'; }

	/** Supported content features, not tracking. */
	public function get_capabilities(): array {
		return array( 'html', 'attachments' ); }

	/**
	 * Validates configuration locally; no network calls.
	 *
	 * @param array $config Decrypted transport settings.
	 * @return ValidationResult Fixed safe field messages.
	 */
	public function validate_config( array $config ): ValidationResult {
		$errors = array();
		$key    = $config['api_key'] ?? null;
		$email  = $config['from_email'] ?? null;
		$name   = $config['from_name'] ?? '';
		if ( ! is_string( $key ) || 'POSTMARK_API_TEST' === $key || strlen( $key ) < 16 || strlen( $key ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $key ) ) {
			$errors['api_key'] = 'A Live Server API token is required, not an Account API token or test token.';
		}
		if ( ! is_string( $email ) || strlen( $email ) > 254 || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['from_email'] = 'A valid verified sender address is required.';
		}
		if ( ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name ) ) {
			$errors['from_name'] = 'The sender name is invalid.';
		}
		return new ValidationResult( array() === $errors, $errors );
	}

	/**
	 * Checks token access and Live server type without sending a message.
	 *
	 * @param array $config Decrypted transport settings.
	 * @return ConnectionResult Credential-free evidence.
	 */
	public function test_connection( array $config ): ConnectionResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new ConnectionResult( false, 'Postmark configuration is incomplete or invalid.' );
		}
		$response = $this->request( '/server', $config['api_key'] );
		if ( $response['live'] ) {
			return new ConnectionResult( true, 'Postmark authenticated a Live Server token. No email was sent; sender authorization and delivery remain unverified.' );
		}
		return new ConnectionResult( false, 'Postmark Live Server verification failed. Check the Server API token, account access and server type. Sandbox servers are unsupported; no email was sent.' );
	}

	/**
	 * Sends one message after validating content and confirming a Live server.
	 *
	 * @param MailMessage $message Prepared message.
	 * @param array       $config Decrypted transport settings.
	 * @return SendResult Normalized acknowledgement, rejection or uncertainty.
	 */
	public function send( MailMessage $message, array $config ): SendResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new SendResult( false, 'postmark', null, null, 'Postmark configuration is incomplete or invalid.', false, TransportFailureCategory::CONFIG );
		}
		try {
			$payload = $this->prepare( $message, $config );
		} catch ( \InvalidArgumentException $error ) {
			return new SendResult( false, 'postmark', null, null, $error->getMessage(), false, TransportFailureCategory::CONFIG );
		} catch ( \Throwable $error ) {
			return new SendResult( false, 'postmark', null, null, 'Message preparation failed before sending.', false, TransportFailureCategory::CONFIG );
		}
		// Check on every attempt: a stored verification flag cannot establish current server type.
		$server = $this->request( '/server', $config['api_key'] );
		if ( ! $server['live'] ) {
			return new SendResult( false, 'postmark', null, null, 'Postmark Live Server verification failed before submission. Check the Server API token and server type. No email was submitted.', false, TransportFailureCategory::CONFIG );
		}
		$response = $this->request( '/email', $config['api_key'], $payload );
		$code     = $response['code'];
		if ( 200 === $code && 0 === $response['error'] && null !== $response['id'] ) {
			return new SendResult( true, 'postmark', $response['id'], '200', 'Message accepted by Postmark; delivery is unconfirmed.' );
		}
		$rejected = $code >= 400 && $code < 500 && 408 !== $code;
		return new SendResult( false, 'postmark', null, $code ? (string) $code : null, $this->safe_status( $code, $response['error'] ), false, $rejected ? TransportFailureCategory::PROVIDER_REJECTION : TransportFailureCategory::UNKNOWN, array(), ! $rejected );
	}

	/**
	 * Constructs a bounded Postmark payload. Rejected input never reaches HTTP.
	 *
	 * @param MailMessage $message Prepared message.
	 * @param array       $config Validated sender configuration.
	 * @return array Provider payload.
	 * @throws \InvalidArgumentException When the message exceeds supported formats or limits.
	 */
	private function prepare( MailMessage $message, array $config ): array {
		if ( ! preg_match( self::UUID_PATTERN, $message->uuid ) ) {
			throw new \InvalidArgumentException( 'The message identifier is invalid.' );
		}
		$from = $this->address( $message->from );
		if ( strtolower( $from['email'] ) !== strtolower( $config['from_email'] ) ) {
			throw new \InvalidArgumentException( 'The message sender does not match the configured Postmark sender.' );
		}
		if ( ! isset( $from['name'] ) && '' !== ( $config['from_name'] ?? '' ) ) {
			$from['name'] = $config['from_name'];
		}
		if ( array() === $message->to || count( $message->to ) > self::MAX_RECIPIENTS ) {
			throw new \InvalidArgumentException( 'The recipient count is unsupported.' );
		}
		$recipients = array(
			'to'  => array(),
			'cc'  => array(),
			'bcc' => array(),
		);
		foreach ( $message->to as $raw ) {
			$recipients['to'][] = $this->address( $raw );
		}
		$reply_to = null;
		$headers  = array();
		$seen     = array();
		foreach ( $message->headers as $header ) {
			if ( ! is_string( $header ) || strlen( $header ) > 2048 || preg_match( '/[\r\n\x00]/', $header )
				|| ! preg_match( '/^([A-Za-z][A-Za-z0-9-]*):[ \t]*(.+)$/D', $header, $parts ) ) {
				throw new \InvalidArgumentException( 'A message header is unsupported or invalid.' );
			}
			$name  = strtolower( $parts[1] );
			$value = trim( $parts[2] );
			if ( in_array( $name, array( 'cc', 'bcc' ), true ) ) {
				foreach ( explode( ',', $value ) as $item ) {
					$recipients[ $name ][] = $this->address( trim( $item ) );
				}
			} elseif ( 'reply-to' === $name && null === $reply_to ) {
				$reply_to = $this->address( $value );
			} elseif ( in_array( $name, self::SAFE_HEADERS, true ) && ! isset( $seen[ $name ] ) && strlen( $value ) <= 998 ) {
				$headers[ $parts[1] ] = $value;
				$seen[ $name ]        = true;
			} else {
				throw new \InvalidArgumentException( 'A message header is unsupported or repeated.' );
			}
		}
		$count = count( $recipients['to'] ) + count( $recipients['cc'] ) + count( $recipients['bcc'] );
		if ( $count > self::MAX_RECIPIENTS ) {
			throw new \InvalidArgumentException( 'The recipient count is unsupported.' );
		}
		if ( ! in_array( $message->content_type, array( 'text/plain', 'text/html' ), true )
			|| '' === $message->body || strlen( $message->body ) > self::MAX_BODY_BYTES
			|| '' === $message->subject || strlen( $message->subject ) > 998
			|| preg_match( '/[\r\n\x00]/', $message->subject ) ) {
			throw new \InvalidArgumentException( 'The message content or subject is unsupported.' );
		}
		$payload = array(
			'From'          => $this->format_address( $from ),
			'To'            => implode( ', ', array_map( array( $this, 'format_address' ), $recipients['to'] ) ),
			'Subject'       => $message->subject,
			'text/html' === $message->content_type ? 'HtmlBody' : 'TextBody' => $message->body,
			'Metadata'      => array( 'scalyn_message_uuid' => $message->uuid ),
			'MessageStream' => 'outbound',
			'TrackOpens'    => false,
			'TrackLinks'    => 'None',
		);
		foreach ( array(
			'cc'  => 'Cc',
			'bcc' => 'Bcc',
		) as $role => $field ) {
			if ( $recipients[ $role ] ) {
				$payload[ $field ] = implode( ', ', array_map( array( $this, 'format_address' ), $recipients[ $role ] ) );
			}
		}
		if ( null !== $reply_to ) {
			$payload['ReplyTo'] = $this->format_address( $reply_to );
		}
		foreach ( $headers as $name => $value ) {
			$payload['Headers'][] = array(
				'Name'  => $name,
				'Value' => $value,
			);
		}
		$files = $this->attachments( $message->attachments );
		if ( $files ) {
			$payload['Attachments'] = $files;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Strict JSON_THROW_ON_ERROR rejects malformed UTF-8 rather than replacing message content.
		$json = json_encode( $payload, JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new \InvalidArgumentException( 'The message exceeds the Postmark adapter size limit.' );
		}
		return $payload;
	}

	/**
	 * Parses the explicit address subset supported by MailMessage.
	 *
	 * @param mixed $raw Address in plain or display-name format.
	 * @return array Email and optional display name.
	 * @throws \InvalidArgumentException When the address format is unsupported.
	 */
	private function address( mixed $raw ): array {
		if ( ! is_string( $raw ) || strlen( $raw ) > 454 || preg_match( '/[\r\n\x00",]/', $raw ) ) {
			throw new \InvalidArgumentException( 'An email address is invalid or unsupported.' );
		}
		$raw = trim( $raw );
		if ( preg_match( '/^([^<>]+?)\s*<([^<>\s]+)>$/D', $raw, $parts ) ) {
			$email = $parts[2];
			$name  = trim( $parts[1] );
		} else {
			$email = $raw;
			$name  = '';
		}
		if ( strlen( $email ) > 254 || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $name ) > 200 ) {
			throw new \InvalidArgumentException( 'An email address is invalid or unsupported.' );
		}
		return '' === $name ? array( 'email' => $email ) : array(
			'email' => $email,
			'name'  => $name,
		);
	}

	/**
	 * Reads bounded local attachments. Only filename and base64 content leave PHP.
	 *
	 * @param array $paths Local absolute paths.
	 * @return array Provider attachment records.
	 * @throws \InvalidArgumentException When a file is invalid, unreadable or oversized.
	 */
	private function attachments( array $paths ): array {
		if ( count( $paths ) > self::MAX_FILES ) {
			throw new \InvalidArgumentException( 'Too many attachments were provided.' );
		}
		$result = array();
		$total  = 0;
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || strlen( $path ) > 4096 || ! preg_match( '~^(?:[A-Za-z]:[\\\\/]|/)~', $path ) ) {
				throw new \InvalidArgumentException( 'An attachment path is unsupported.' );
			}
			$real = realpath( $path );
			$size = false !== $real && is_file( $real ) && is_readable( $real ) ? filesize( $real ) : false;
			if ( false === $size || 0 >= $size || $size > self::MAX_FILE_BYTES || $total + $size > self::MAX_FILE_BYTES ) {
				throw new \InvalidArgumentException( 'An attachment is unreadable or exceeds the size limit.' );
			}
			$filename = basename( $path );
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._ -]{0,199}$/D', $filename ) ) {
				throw new \InvalidArgumentException( 'An attachment filename is unsupported.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Read a validated local file with a strict byte cap; this is not an HTTP request.
			$bytes = file_get_contents( $real, false, null, 0, self::MAX_FILE_BYTES + 1 );
			if ( false === $bytes || strlen( $bytes ) !== $size ) {
				throw new \InvalidArgumentException( 'An attachment could not be read safely.' );
			}
			$total   += $size;
			$result[] = array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Postmark requires base64-encoded attachment bytes.
				'Content'     => base64_encode( $bytes ),
				'Name'        => $filename,
				'ContentType' => 'application/octet-stream',
			);
		}
		return $result;
	}


	/**
	 * Formats a previously validated address.
	 *
	 * @param array $address Validated mailbox.
	 * @return string Mailbox with safely quoted optional display name.
	 */
	private function format_address( array $address ): string {
		return isset( $address['name'] ) ? '"' . addcslashes( $address['name'], '\\"' ) . '" <' . $address['email'] . '>' : $address['email'];
	}

	/**
	 * Discards raw responses, including tokens returned by GET /server.
	 *
	 * @param string     $path Fixed internal route.
	 * @param string     $key Server token.
	 * @param array|null $payload Null for non-sending verification.
	 * @return array Allowlisted observations only.
	 */
	private function request( string $path, #[\SensitiveParameter] string $key, ?array $payload = null ): array {
		$unknown = array(
			'code'  => 0,
			'error' => null,
			'id'    => null,
			'live'  => false,
		);
		try {
			$args = array(
				'method'              => null === $payload ? 'GET' : 'POST',
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 16384,
				'headers'             => array(
					'X-Postmark-Server-Token' => $key,
					'Accept'                  => 'application/json',
					'Content-Type'            => 'application/json',
				),
			);
			if ( null !== $payload ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Reject malformed UTF-8 rather than altering message content.
				$args['body'] = json_encode( $payload, JSON_THROW_ON_ERROR );
				if ( strlen( $args['body'] ) > self::MAX_JSON_BYTES ) {
					return $unknown;
				}
			}
			$response = $this->http( $path, $args );
			if ( ! is_array( $response ) ) {
				return $unknown;
			}
			$code = $response['response']['code'] ?? 0;
			$code = is_numeric( $code ) && $code >= 100 && $code <= 599 ? (int) $code : 0;
			$body = $response['body'] ?? '';
			$data = is_string( $body ) && strlen( $body ) <= 16384 ? json_decode( $body, true, 8 ) : null;
			$data = is_array( $data ) ? $data : array();
			return array(
				'code'  => $code,
				'error' => is_int( $data['ErrorCode'] ?? null ) ? $data['ErrorCode'] : null,
				'id'    => is_string( $data['MessageID'] ?? null ) && preg_match( self::UUID_PATTERN, $data['MessageID'] ) ? $data['MessageID'] : null,
				'live'  => '/server' === $path && 200 === $code && is_int( $data['ID'] ?? null ) && $data['ID'] > 0 && 'Live' === ( $data['DeliveryType'] ?? null ) && ! isset( $data['ErrorCode'] ),
			);
		} catch ( \Throwable $error ) {
			return $unknown;
		}
	}

	/**
	 * Fixed HTTPS boundary, isolated for offline tests.
	 *
	 * @param string $path Internal endpoint.
	 * @param array  $args Bounded HTTP arguments.
	 * @return mixed WordPress HTTP response.
	 */
	protected function http( string $path, array $args ): mixed {
		return wp_safe_remote_request( 'https://api.postmarkapp.com' . $path, $args );
	}

	/**
	 * Produces fixed remediation without copying raw provider messages.
	 *
	 * @param int      $code HTTP status.
	 * @param int|null $error Postmark error code.
	 * @return string Operator guidance.
	 */
	private function safe_status( int $code, ?int $error ): string {
		if ( $code < 400 || $code >= 500 || 408 === $code ) {
			return 'Postmark acceptance is unconfirmed. Check provider activity before retrying. No automatic retry was attempted.';
		}
		if ( 401 === $code || 403 === $code ) {
			return 'Postmark refused authorization. Check the Server API token and account restrictions. No automatic retry was attempted.';
		}
		if ( 429 === $code ) {
			return 'Postmark rejected the request because of rate limiting. Wait and inspect provider activity before a manual retry.';
		}
		return match ( $error ) {
			406 => 'Postmark rejected an inactive recipient. Review suppressions with the account owner; do not blindly retry.',
			412, 413 => 'Postmark rejected the request because the account is pending approval or restricted. Review account status.',
			default => 'Postmark rejected the request. Check sender authorization, supported content, recipient restrictions and account allowance. No automatic retry was attempted.',
		};
	}
}
