<?php
/**
 * SMTP mail provider using WordPress-bundled PHPMailer.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Smtp;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP transport provider powered by WordPress-bundled PHPMailer.
 *
 * Configuration keys (from SettingsRepository::get_smtp_config()):
 *   - host       (string) SMTP server hostname or IP address.
 *   - port       (int)    SMTP port (1–65535).
 *   - encryption (string) 'tls' | 'ssl' | 'none'.
 *   - username   (string) SMTP username (empty string = no authentication).
 *   - password   (string) SMTP password (required when username is present).
 *   - from_name  (string) Optional sender display name.
 *   - from_email (string) Sender RFC-5321 address (required, must be valid).
 *
 * @ssrf-note This provider connects to user-configured SMTP hosts. Private and
 * internal network addresses (RFC 1918) are intentionally not blocked to support
 * legitimate private SMTP relays. Administrators are responsible for configuring
 * trusted hosts only. Do not expose this provider to untrusted user input.
 */
class SmtpProvider implements ProviderInterface {

	/**
	 * Lowercase header names that PHPMailer manages internally.
	 *
	 * These are skipped in add_safe_header() to prevent duplicates and
	 * avoid overriding transport-level behaviour. Cc, Bcc, and Reply-To
	 * require proper address parsing and are documented as a follow-up task.
	 *
	 * @var string[]
	 */
	private const STRUCTURAL_HEADERS = array(
		'from',
		'to',
		'cc',
		'bcc',
		'subject',
		'content-type',
		'mime-version',
		'message-id',
		'date',
		'reply-to',
	);

	/**
	 * URL-scheme prefixes that are never valid in an SMTP hostname/IP field.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_HOST_SCHEMES = array(
		'http://',
		'https://',
		'smtp://',
		'smtps://',
		'ftp://',
		'ftps://',
	);

	/**
	 * Maximum time, in seconds, PHPMailer may spend establishing and holding
	 * the SMTP connection for a single send/connection-test attempt.
	 *
	 * WordPress admin requests are typically bounded by ~30s max_execution_time;
	 * this leaves headroom for WP bootstrap and response rendering around the
	 * SMTP transaction itself, while still tolerating realistic handshake
	 * latency on slower relays. PHPMailer's own default (300s) is unsuitable
	 * for a synchronous admin-facing request.
	 *
	 * @var int
	 */
	private const CONNECTION_TIMEOUT_SECONDS = 15;

	/**
	 * Returns the unique machine-readable provider identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'smtp';
	}

	/**
	 * Returns the human-readable display name for this provider.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'SMTP (PHPMailer)';
	}

	/**
	 * Returns the list of feature flags supported by this provider.
	 *
	 * @return string[]
	 */
	public function get_capabilities(): array {
		return array( 'html', 'attachments' );
	}

	/**
	 * Validates the SMTP configuration.
	 *
	 * Makes no network calls.
	 *
	 * Validation rules:
	 *   - host must be present and free of control characters, whitespace, and URL schemes.
	 *   - port must be in the range 1–65535.
	 *   - from_email must be a syntactically valid email address.
	 *   - username present with empty password is rejected (incomplete auth config).
	 *
	 * @param array<string, mixed> $config Provider configuration from SettingsRepository.
	 * @return ValidationResult
	 */
	public function validate_config( array $config ): ValidationResult {
		$errors = array();

		// --- Host ---
		// Validate against the raw value so that embedded control characters
		// (e.g. NUL, CR, LF) are caught before trim() removes them from the edges.
		$host_raw = (string) ( $config['host'] ?? '' );
		if ( '' === trim( $host_raw ) ) {
			$errors['host'] = 'SMTP host is required.';
		} elseif ( $this->is_host_malformed( $host_raw ) ) {
			$errors['host'] = 'SMTP host contains invalid characters or an unsupported format.';
		}

		// --- Port ---
		$port = (int) ( $config['port'] ?? 0 );
		if ( $port < 1 || $port > 65535 ) {
			$errors['port'] = 'SMTP port must be between 1 and 65535.';
		}

		// --- Sender email ---
		$from_email = (string) ( $config['from_email'] ?? '' );
		if ( '' === $from_email ) {
			$errors['from_email'] = 'Sender email address is required.';
		} elseif ( false === filter_var( $from_email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['from_email'] = 'Sender email address is not valid.';
		}

		// --- Authentication ---
		// The project infers SMTP authentication from a non-empty username.
		// A username with an empty password is an incomplete authenticated
		// configuration and must be rejected here.
		$username = (string) ( $config['username'] ?? '' );
		$password = (string) ( $config['password'] ?? '' );
		if ( '' !== $username && '' === $password ) {
			$errors['password'] = 'SMTP password is required when a username is provided.';
		}

		return new ValidationResult( empty( $errors ), $errors );
	}

	/**
	 * Tests the SMTP connection without transmitting any email.
	 *
	 * Delegates to PHPMailer's smtpConnect(), which handles TCP connection,
	 * STARTTLS negotiation, and SMTP authentication (when SMTPAuth = true) in
	 * a single, ordered operation. Authentication must NOT be performed separately
	 * after smtpConnect() returns — the session would already be authenticated
	 * and the server would reject a redundant AUTH command.
	 *
	 * In exceptions mode (PHPMailer constructor arg: true), smtpConnect() always
	 * throws PHPMailer\PHPMailer\Exception on failure; it never returns false.
	 * The false-branch is a safety net for non-exceptions callers.
	 *
	 * The connection is always closed before this method returns, including on
	 * failure. smtpClose() is safe to call even when already disconnected.
	 *
	 * @param array<string, mixed> $config Provider configuration from SettingsRepository.
	 * @return ConnectionResult
	 */
	public function test_connection( array $config ): ConnectionResult {
		$validation = $this->validate_config( $config );
		if ( ! $validation->valid ) {
			return new ConnectionResult( false, $this->config_validation_failure_message( $validation ) );
		}

		PhpMailerLoader::load();

		$mailer = $this->create_mailer();
		$this->configure_transport( $mailer, $config );

		try {
			if ( ! $mailer->smtpConnect() ) {
				// Safety net only: in exceptions mode smtpConnect() throws rather
				// than returning false. Reached only if caller disables exceptions.
				return new ConnectionResult(
					false,
					'Unable to connect to the SMTP server.'
				);
			}

			$mailer->smtpClose();
			return new ConnectionResult(
				true,
				'Successfully connected to the configured SMTP server.'
			);

		} catch ( PHPMailerException $e ) {
			// Ensure the connection is closed even when an exception is thrown.
			// smtpConnect() already calls quit() internally on TLS/auth failure
			// before rethrowing; smtpClose() is idempotent when disconnected.
			$mailer->smtpClose();
			return new ConnectionResult( false, $this->normalize_connection_message( $e ) );
		}
	}

	/**
	 * Sends a mail message through the configured SMTP server.
	 *
	 * @param MailMessage          $message The prepared message.
	 * @param array<string, mixed> $config  Provider configuration from SettingsRepository.
	 * @return SendResult
	 */
	public function send( MailMessage $message, array $config ): SendResult {
		$validation = $this->validate_config( $config );
		if ( ! $validation->valid ) {
			return new SendResult(
				success: false,
				provider: 'smtp',
				response_message: $this->config_validation_failure_message( $validation ),
				failure_category: TransportFailureCategory::CONFIG
			);
		}

		PhpMailerLoader::load();

		$mailer = $this->create_mailer();
		$this->configure_transport( $mailer, $config );

		try {
			// Validate and set the sender address from the message (not from config;
			// the provider uses the message's from address for actual transport).
			$from = $this->parse_address( $message->from );
			if ( false === filter_var( $from['email'], FILTER_VALIDATE_EMAIL ) ) {
				return new SendResult(
					success: false,
					provider: 'smtp',
					response_message: 'The sender address is not valid.',
					failure_category: TransportFailureCategory::CONFIG
				);
			}
			$mailer->setFrom( $from['email'], $from['name'] );

			// Validate and add recipient addresses.
			if ( empty( $message->to ) ) {
				return new SendResult(
					success: false,
					provider: 'smtp',
					response_message: 'No recipient addresses were provided.',
					failure_category: TransportFailureCategory::CONFIG
				);
			}

			foreach ( $message->to as $recipient ) {
				$addr = $this->parse_address( (string) $recipient );
				if ( false === filter_var( $addr['email'], FILTER_VALIDATE_EMAIL ) ) {
					return new SendResult(
						success: false,
						provider: 'smtp',
						response_message: 'One or more recipient addresses are not valid.',
						failure_category: TransportFailureCategory::CONFIG
					);
				}
				$mailer->addAddress( $addr['email'], $addr['name'] );
			}

			// Subject and body.
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer uses PascalCase properties.
			$mailer->Subject = $message->subject;
			$mailer->isHTML( 'text/html' === $message->content_type );
			$mailer->Body = $message->body;
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			// Custom headers — safe subset only (structural headers are skipped).
			foreach ( $message->headers as $header ) {
				$this->add_safe_header( $mailer, (string) $header );
			}

			// Attachments — paths only; contents are never read into Scalyn data.
			foreach ( $message->attachments as $attachment ) {
				$mailer->addAttachment( (string) $attachment );
			}

			$mailer->send();

			// provider_message_id: PHPMailer's internally generated RFC Message-ID
			// is not a provider-assigned transaction identifier. Set to null.
			//
			// response_code: PHPMailer does not expose the raw SMTP response code
			// on the success path without accessing internal state unsafely. Set to null.
			return new SendResult(
				success: true,
				provider: 'smtp',
				provider_message_id: null,
				response_code: null,
				response_message: 'Message accepted by the configured SMTP server.',
				retryable: false
			);

		} catch ( PHPMailerException $e ) {
			return $this->normalize_send_failure( $e );
		}
	}

	/**
	 * Creates a new PHPMailer instance with exceptions enabled.
	 *
	 * Override in tests to inject a stub without loading real PHPMailer files.
	 *
	 * @return PHPMailer
	 */
	protected function create_mailer(): PHPMailer {
		return new PHPMailer( true );
	}

	/**
	 * Applies SMTP transport settings to a PHPMailer instance.
	 *
	 * For encryption = 'none', SMTPAutoTLS is explicitly set to false so that
	 * PHPMailer does not opportunistically upgrade to STARTTLS when the server
	 * advertises it. This respects the administrator's explicit intent.
	 *
	 * @param PHPMailer            $mailer The instance to configure.
	 * @param array<string, mixed> $config SMTP configuration.
	 * @return void
	 */
	private function configure_transport( PHPMailer $mailer, array $config ): void {
		$host       = (string) ( $config['host'] ?? '' );
		$port       = (int) ( $config['port'] ?? 587 );
		$encryption = (string) ( $config['encryption'] ?? 'tls' );
		$username   = (string) ( $config['username'] ?? '' );
		$password   = (string) ( $config['password'] ?? '' );
		$from_name  = (string) ( $config['from_name'] ?? '' );

		$mailer->isSMTP();

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer uses PascalCase properties.
		$mailer->Host      = $host;
		$mailer->Port      = $port;
		$mailer->CharSet   = 'UTF-8';
		$mailer->SMTPDebug = 0; // Disable debug transcript; default is 0, set explicitly for clarity.
		$mailer->Timeout   = self::CONNECTION_TIMEOUT_SECONDS;

		switch ( $encryption ) {
			case 'ssl':
				$mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				break;
			case 'tls':
				$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				break;
			default:
				// 'none': must not silently upgrade. PHPMailer's SMTPAutoTLS
				// enables opportunistic STARTTLS by default when SMTPSecure is
				// empty; disable it explicitly to honour the user's choice.
				$mailer->SMTPSecure  = '';
				$mailer->SMTPAutoTLS = false;
				break;
		}

		if ( '' !== $username && '' !== $password ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $username;
			$mailer->Password = $password;
		}

		if ( '' !== $from_name ) {
			$mailer->FromName = $from_name;
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Returns a safe, human-readable message for a failed configuration validation.
	 *
	 * The per-field messages from validate_config() are static strings that
	 * never echo back the raw invalid value, so surfacing the first one
	 * directly is safe and more actionable to an administrator than a
	 * generic message.
	 *
	 * @param ValidationResult $result A failed validation result.
	 * @return string
	 */
	private function config_validation_failure_message( ValidationResult $result ): string {
		$messages = array_values( $result->errors );
		return $messages[0] ?? 'The SMTP configuration is invalid.';
	}

	/**
	 * Returns true when the given hostname/IP string is malformed.
	 *
	 * Does NOT block private or internal IP ranges — private SMTP relays are
	 * legitimate. Administrators are responsible for configuring trusted hosts.
	 *
	 * Rejected:
	 *   - Empty (caller handles empty separately).
	 *   - Control characters (0x00–0x1F, 0x7F) including CR/LF/NUL.
	 *   - Any embedded whitespace (space, tab).
	 *   - URL scheme prefixes (http://, smtp://, ftp://, etc.).
	 *
	 * Allowed:
	 *   - DNS hostnames.
	 *   - IPv4 dotted-decimal addresses.
	 *   - IPv6 addresses in bracket notation (e.g. [::1]).
	 *
	 * @param string $host Hostname or IP string to inspect.
	 * @return bool
	 */
	private function is_host_malformed( string $host ): bool {
		// Control characters (includes CR, LF, NUL, TAB).
		if ( preg_match( '/[\x00-\x1F\x7F]/', $host ) ) {
			return true;
		}
		// Whitespace (space or tab not already caught above by the range).
		if ( preg_match( '/\s/', $host ) ) {
			return true;
		}
		// URL scheme prefixes.
		foreach ( self::FORBIDDEN_HOST_SCHEMES as $scheme ) {
			if ( 0 === stripos( $host, $scheme ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parses an RFC-5321-style address into email and display-name components.
	 *
	 * Accepted formats:
	 *   - 'user@example.com'
	 *   - 'Display Name <user@example.com>'
	 *
	 * @param string $address Raw address string.
	 * @return array{email: string, name: string}
	 */
	private function parse_address( string $address ): array {
		$address = trim( $address );
		if ( preg_match( '/^(.+?)\s*<([^>]+)>\s*$/', $address, $matches ) ) {
			return array(
				'email' => trim( $matches[2] ),
				'name'  => trim( $matches[1] ),
			);
		}
		return array(
			'email' => $address,
			'name'  => '',
		);
	}

	/**
	 * Adds a single custom header to PHPMailer if it is safe to do so.
	 *
	 * Headers that PHPMailer controls structurally (From, To, Subject,
	 * Content-Type, etc.) are silently skipped. Any header containing
	 * CR or LF characters is discarded to prevent header injection.
	 *
	 * Cc, Bcc, and Reply-To are skipped in this vertical slice because proper
	 * handling requires address parsing beyond the scope of this ticket.
	 * These are documented as a follow-up task.
	 *
	 * @param PHPMailer $mailer PHPMailer instance to modify.
	 * @param string    $header Raw header in 'Name: value' format.
	 * @return void
	 */
	private function add_safe_header( PHPMailer $mailer, string $header ): void {
		$colon_pos = strpos( $header, ':' );
		if ( false === $colon_pos ) {
			return;
		}

		$name  = trim( substr( $header, 0, $colon_pos ) );
		$value = trim( substr( $header, $colon_pos + 1 ) );

		// Reject header injection: discard any header whose name or value
		// contains a carriage return or line feed.
		if ( preg_match( '/[\r\n]/', $name . $value ) ) {
			return;
		}

		// Skip structural headers that PHPMailer manages.
		if ( in_array( strtolower( $name ), self::STRUCTURAL_HEADERS, true ) ) {
			return;
		}

		$mailer->addCustomHeader( $name, $value );
	}

	/**
	 * Classifies a PHPMailer exception into a stable transport failure category.
	 *
	 * Message text is inspected only to select a category; the raw exception
	 * message is never retained or returned. An unrecognized message is
	 * classified as UNKNOWN rather than guessed into a specific category —
	 * an inability to classify is not evidence of a specific problem.
	 *
	 * @param PHPMailerException $e The caught exception.
	 * @return string One of the TransportFailureCategory constants.
	 */
	private function classify_transport_exception( PHPMailerException $e ): string {
		$raw = strtolower( $e->getMessage() );

		// Certificate is checked first: certificate-error text (e.g. "certificate
		// verify failed", "self-signed certificate") also contains 'ssl'/'tls'.
		if ( str_contains( $raw, 'certificate' ) || str_contains( $raw, 'x509' ) || str_contains( $raw, 'self-signed' ) || str_contains( $raw, 'self signed' ) ) {
			return TransportFailureCategory::CERTIFICATE;
		}
		// TLS/handshake is checked before timeout/connectivity: a TLS failure
		// message may also contain 'connect' (e.g. "Could not connect: TLS negotiation failed.").
		if ( str_contains( $raw, 'tls' ) || str_contains( $raw, 'ssl' ) || str_contains( $raw, 'crypto' ) || str_contains( $raw, 'starttls' ) ) {
			return TransportFailureCategory::TLS;
		}
		if ( str_contains( $raw, 'timed out' ) || str_contains( $raw, 'timeout' ) ) {
			return TransportFailureCategory::TIMEOUT;
		}
		if ( str_contains( $raw, 'connect' ) || str_contains( $raw, 'refused' ) || str_contains( $raw, 'unreachable' ) || str_contains( $raw, 'resolve' ) ) {
			return TransportFailureCategory::CONNECTIVITY;
		}
		if ( str_contains( $raw, 'authenticate' ) || str_contains( $raw, 'auth' ) ) {
			return TransportFailureCategory::AUTH;
		}
		if ( str_contains( $raw, 'recipients' ) || str_contains( $raw, 'rejected' ) || str_contains( $raw, 'relay' ) || str_contains( $raw, 'data not accepted' ) ) {
			return TransportFailureCategory::PROVIDER_REJECTION;
		}
		return TransportFailureCategory::UNKNOWN;
	}

	/**
	 * Returns the sanitized message and retryability for a failure category.
	 *
	 * @param string $category One of the TransportFailureCategory constants.
	 * @return array{0: string, 1: bool} Sanitized message and retryable flag.
	 */
	private function message_and_retry_for_category( string $category ): array {
		return match ( $category ) {
			TransportFailureCategory::CERTIFICATE => array( "The server's TLS certificate could not be verified.", false ),
			TransportFailureCategory::TLS => array( 'Unable to establish the requested secure connection.', false ),
			TransportFailureCategory::TIMEOUT => array( 'The connection to the SMTP server timed out.', true ),
			TransportFailureCategory::CONNECTIVITY => array( 'Unable to connect to the SMTP server.', true ),
			TransportFailureCategory::AUTH => array( 'SMTP authentication failed.', false ),
			TransportFailureCategory::PROVIDER_REJECTION => array( 'The SMTP server did not accept the message.', false ),
			default => array( 'SMTP transport failed.', false ),
		};
	}

	/**
	 * Converts a PHPMailer exception into a sanitized SendResult failure.
	 *
	 * Raw exception messages are never exposed to callers. The message text
	 * is inspected only to select the appropriate sanitized category string.
	 *
	 * @security Passwords and usernames must never appear in the returned result.
	 *
	 * @param PHPMailerException $e The caught exception.
	 * @return SendResult
	 */
	private function normalize_send_failure( PHPMailerException $e ): SendResult {
		$category = $this->classify_transport_exception( $e );

		list( $message, $retry ) = $this->message_and_retry_for_category( $category );

		return new SendResult(
			success: false,
			provider: 'smtp',
			provider_message_id: null,
			response_code: null,
			response_message: $message,
			retryable: $retry,
			failure_category: $category
		);
	}

	/**
	 * Converts a PHPMailer exception into a sanitized connection error message.
	 *
	 * @param PHPMailerException $e The caught exception.
	 * @return string Sanitized human-readable message.
	 */
	private function normalize_connection_message( PHPMailerException $e ): string {
		$category = $this->classify_transport_exception( $e );

		list( $message ) = $this->message_and_retry_for_category( $category );

		return $message;
	}
}
