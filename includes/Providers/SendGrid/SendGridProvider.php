<?php
/**
 * SendGrid Mail Send adapter.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\SendGrid;

use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

defined( 'ABSPATH' ) || exit;

/** Sends one prepared message per request through the fixed Mail Send endpoint. */
class SendGridProvider implements ProviderInterface {

	private const ENDPOINT       = 'https://api.sendgrid.com/v3/mail/send';
	private const MAX_RECIPIENTS = 100;
	private const MAX_BODY_BYTES = 1048576;
	private const MAX_FILE_BYTES = 4194304;
	private const MAX_FILES      = 10;
	private const MAX_JSON_BYTES = 8388608;
	private const SAFE_HEADERS   = array( 'x-priority', 'x-mailer', 'list-unsubscribe' );
	private const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

	/** Identifies this provider without registering it. */
	public function get_id(): string {
		return 'sendgrid';
	}

	/** Human-readable name. */
	public function get_label(): string {
		return 'SendGrid API';
	}

	/** Advertises only implemented, tested message features. */
	public function get_capabilities(): array {
		return array( 'html', 'attachments' );
	}

	/**
	 * Validates transport-ready configuration without network traffic.
	 *
	 * @param array $config Decrypted key and sender identity from approved service.
	 * @return ValidationResult Safe field errors only.
	 */
	public function validate_config( array $config ): ValidationResult {
		$errors = array();
		$key    = $config['api_key'] ?? null;
		$email  = $config['from_email'] ?? null;
		$name   = $config['from_name'] ?? '';
		if ( ! is_string( $key ) || strlen( $key ) < 16 || strlen( $key ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $key ) ) {
			$errors['api_key'] = 'A valid SendGrid API key is required.';
		}
		if ( ! is_string( $email ) || strlen( $email ) > 254 || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['from_email'] = 'A valid verified sender address is required.';
		}
		if ( ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name ) ) {
			$errors['from_name'] = 'The sender name is invalid.';
		}
		return new ValidationResult( array() === $errors, $errors );
	}

	/**
	 * Uses a synthetic sandbox request; 200 validates shape only, never delivery.
	 *
	 * @param array $config Decrypted transport configuration.
	 * @return ConnectionResult Safe validation summary.
	 */
	public function test_connection( array $config ): ConnectionResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new ConnectionResult( false, 'SendGrid configuration is incomplete or invalid.' );
		}
		$sender   = $config['from_email'];
		$probe    = array(
			'personalizations' => array( array( 'to' => array( array( 'email' => $sender ) ) ) ),
			'from'             => array( 'email' => $sender ),
			'subject'          => 'Scalyn Mail Relay sandbox validation',
			'content'          => array(
				array(
					'type'  => 'text/plain',
					'value' => 'Validation only.',
				),
			),
			'mail_settings'    => array( 'sandbox_mode' => array( 'enable' => true ) ),
		);
		$response = $this->request( $probe, $config['api_key'] );
		$code     = $response['code'];
		return 200 === $code
			? new ConnectionResult( true, 'SendGrid accepted the sandbox request format. No email was sent.' )
			: new ConnectionResult( false, $this->safe_status( $code, true, $response['credits_exceeded'] ) );
	}

	/**
	 * Sends a single message and reports only provider acknowledgement.
	 *
	 * @param MailMessage $message Message prepared by the shared dispatcher.
	 * @param array       $config Decrypted transport configuration.
	 * @return SendResult Normalized, credential-free outcome.
	 */
	public function send( MailMessage $message, array $config ): SendResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new SendResult( false, 'sendgrid', null, null, 'SendGrid configuration is incomplete or invalid.', false, TransportFailureCategory::CONFIG );
		}
		try {
			$payload = $this->prepare( $message, $config );
		} catch ( \InvalidArgumentException $error ) {
			return new SendResult( false, 'sendgrid', null, null, $error->getMessage(), false, TransportFailureCategory::CONFIG );
		} catch ( \Throwable $error ) {
			return new SendResult( false, 'sendgrid', null, null, 'Message preparation failed before sending.', false, TransportFailureCategory::UNKNOWN );
		}
		$response = $this->request( $payload, $config['api_key'] );
		$code     = $response['code'];
		if ( 202 === $code ) {
			return new SendResult( true, 'sendgrid', null, '202', 'Message accepted by SendGrid; delivery is unconfirmed.' );
		}
		// Only explicit client rejections establish failure. Timeouts, server errors,
		// redirects and unexpected success codes cannot establish non-acceptance.
		$rejected = $code >= 400 && $code < 500 && 408 !== $code;
		return new SendResult(
			false,
			'sendgrid',
			null,
			0 < $code ? (string) $code : null,
			$this->safe_status( $code, false, $response['credits_exceeded'] ),
			false,
			$rejected ? TransportFailureCategory::PROVIDER_REJECTION : TransportFailureCategory::UNKNOWN,
			array(),
			! $rejected
		);
	}

	/**
	 * Constructs a bounded SendGrid payload. Rejected input never reaches HTTP.
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
			throw new \InvalidArgumentException( 'The message sender does not match the configured SendGrid sender.' );
		}
		if ( ! isset( $from['name'] ) && '' !== $config['from_name'] ) {
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
			} elseif ( in_array( $name, self::SAFE_HEADERS, true ) && ! isset( $headers[ $parts[1] ] ) && strlen( $value ) <= 998 ) {
				$headers[ $parts[1] ] = $value;
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
		$personalization = array( 'to' => $recipients['to'] );
		foreach ( array( 'cc', 'bcc' ) as $role ) {
			if ( $recipients[ $role ] ) {
				$personalization[ $role ] = $recipients[ $role ];
			}
		}
		$payload = array(
			'personalizations'  => array( $personalization ),
			'from'              => $from,
			'subject'           => $message->subject,
			'content'           => array(
				array(
					'type'  => $message->content_type,
					'value' => $message->body,
				),
			),
			'custom_args'       => array( 'scalyn_message_uuid' => $message->uuid ),
			'tracking_settings' => array(
				'click_tracking' => array( 'enable' => false ),
				'open_tracking'  => array( 'enable' => false ),
			),
		);
		if ( null !== $reply_to ) {
			$payload['reply_to'] = $reply_to;
		}
		if ( $headers ) {
			$payload['headers'] = $headers;
		}
		$files = $this->attachments( $message->attachments );
		if ( $files ) {
			$payload['attachments'] = $files;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Strict JSON_THROW_ON_ERROR rejects malformed UTF-8 rather than replacing message content.
		$json = json_encode( $payload, JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new \InvalidArgumentException( 'The message exceeds the SendGrid adapter size limit.' );
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
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- SendGrid requires base64-encoded attachment bytes.
				'content'     => base64_encode( $bytes ),
				'filename'    => $filename,
				'disposition' => 'attachment',
			);
		}
		return $result;
	}

	/**
	 * Calls a fixed endpoint. Only status and an exact allowlisted signal survive.
	 *
	 * @param array  $payload API payload.
	 * @param string $key Mail Send API key.
	 * @return array{code: int, credits_exceeded: bool} Credential-free observations.
	 */
	private function request( array $payload, #[\SensitiveParameter] string $key ): array {
		$unknown = array(
			'code'             => 0,
			'credits_exceeded' => false,
		);
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Strict encoding is required for a bounded provider request.
			$body = json_encode( $payload, JSON_THROW_ON_ERROR );
			if ( strlen( $body ) > self::MAX_JSON_BYTES ) {
				return $unknown;
			}
			$response = $this->post(
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => 1024,
					'headers'             => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					),
					'body'                => $body,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $unknown;
			}
			$code = $this->response_code( $response );
			return array(
				'code'             => $code >= 100 && $code <= 599 ? $code : 0,
				'credits_exceeded' => 401 === $code && $this->credits_exceeded( $response ),
			);
		} catch ( \Throwable $error ) {
			return $unknown;
		}
	}

	/**
	 * Recognizes one documented account restriction without retaining provider text.
	 *
	 * @param mixed $response WordPress HTTP response (bounded at the HTTP boundary).
	 * @return bool Whether the complete, single error exactly matches the allowlist.
	 */
	private function credits_exceeded( mixed $response ): bool {
		$body = is_array( $response ) ? ( $response['body'] ?? null ) : null;
		if ( ! is_string( $body ) || strlen( $body ) > 1024 ) {
			return false;
		}
		$data = json_decode( $body, true, 8 );
		return is_array( $data ) && isset( $data['errors'] ) && is_array( $data['errors'] )
			&& 1 === count( $data['errors'] )
			&& isset( $data['errors'][0] ) && is_array( $data['errors'][0] )
			&& 'Maximum credits exceeded' === ( $data['errors'][0]['message'] ?? null );
	}

	/**
	 * Isolated HTTP boundary for contract tests.
	 *
	 * @param array $args Safe WordPress HTTP arguments.
	 * @return mixed WordPress response.
	 */
	protected function post( array $args ): mixed {
		return wp_safe_remote_post( self::ENDPOINT, $args );
	}

	/**
	 * Reads only the HTTP status.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return int Status code.
	 */
	protected function response_code( mixed $response ): int {
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Maps known HTTP statuses to fixed guidance, never provider content.
	 *
	 * @param int  $code Observed HTTP status or zero.
	 * @param bool $sandbox Whether this was a sandbox probe.
	 * @param bool $credits_exceeded Exact documented account restriction observed.
	 * @return string Safe operator guidance.
	 */
	private function safe_status( int $code, bool $sandbox, bool $credits_exceeded = false ): string {
		if ( $code < 400 || $code >= 500 || 408 === $code ) {
			return $sandbox ? 'Sandbox validation could not confirm a response.' : 'SendGrid acceptance is unconfirmed. Check provider activity before retrying.';
		}
		if ( $credits_exceeded ) {
			return 'SendGrid rejected the request because Email API credits are exhausted (HTTP 401). Check your Email API plan, sending allowance and billing status. No automatic retry was attempted.';
		}
		if ( 401 === $code || 403 === $code ) {
			return 'SendGrid refused the request (HTTP ' . $code . '). Check API key permissions, Email API credits, billing status and account restrictions. This status alone does not identify the cause.';
		}
		if ( 429 === $code ) {
			return 'SendGrid rejected this request because of rate limiting (HTTP 429). Wait for the provider limit to reset and check provider activity before a manual retry. No automatic retry was attempted.';
		}
		return 'SendGrid rejected the request (HTTP ' . $code . '). Check the configured sender, supported message format and provider account restrictions. No automatic retry was attempted.';
	}
}
