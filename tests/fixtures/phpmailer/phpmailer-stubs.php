<?php
/**
 * Minimal PHPMailer stub classes for unit tests.
 *
 * Loaded by the PHPUnit bootstrap so that PhpMailerLoader::load() finds
 * class_exists('PHPMailer\PHPMailer\PHPMailer') === true and skips requiring
 * any WordPress-bundled files (which are not present in the test fixture tree).
 *
 * These stubs expose the minimum interface used by SmtpProvider, plus
 * call-tracking properties so tests can assert configuration and behaviour
 * without a live SMTP connection.
 *
 * Do NOT use these stubs in production code.
 */

namespace PHPMailer\PHPMailer;

if ( class_exists( PHPMailer::class, false ) ) {
	return;
}

/**
 * PHPMailer exception stub.
 */
class Exception extends \RuntimeException {}

/**
 * PHPMailer SMTP stub for unit tests.
 */
class SMTP {

	/** @var bool Controls the return value of authenticate(). */
	public bool $authenticate_result = true;

	/** @var bool True after quit() is called. */
	public bool $quit_was_called = false;

	/** @var bool True after close() is called. */
	public bool $close_was_called = false;

	/**
	 * @param string      $username  SMTP username.
	 * @param string      $password  SMTP password.
	 * @param string|null $authtype  Authentication type (unused in stub).
	 * @return bool
	 */
	public function authenticate( string $username, string $password, ?string $authtype = null ): bool {
		return $this->authenticate_result;
	}

	/**
	 * @param bool $close_on_error Unused in stub.
	 * @return bool
	 */
	public function quit( bool $close_on_error = true ): bool {
		$this->quit_was_called = true;
		return true;
	}

	/** @return void */
	public function close(): void {
		$this->close_was_called = true;
	}
}

/**
 * PHPMailer main class stub for unit tests.
 */
class PHPMailer {

	/** @var string STARTTLS encryption mode constant. */
	public const ENCRYPTION_STARTTLS = 'tls';

	/** @var string SMTPS encryption mode constant. */
	public const ENCRYPTION_SMTPS = 'ssl';

	/** @var string UTF-8 charset constant. */
	public const CHARSET_UTF8 = 'UTF-8';

	// -------------------------------------------------------------------------
	// Transport configuration (written by SmtpProvider::configure_transport()).
	// -------------------------------------------------------------------------

	/** @var string SMTP server hostname or IP. */
	public string $Host = '';

	/** @var int SMTP server port. */
	public int $Port = 587;

	/** @var string SMTPSecure mode ('tls', 'ssl', or ''). */
	public string $SMTPSecure = '';

	/** @var bool Whether PHPMailer may opportunistically enable STARTTLS. */
	public bool $SMTPAutoTLS = true;

	/** @var bool Whether SMTP authentication is enabled. */
	public bool $SMTPAuth = false;

	/** @var string SMTP username. */
	public string $Username = '';

	/** @var string SMTP password. */
	public string $Password = '';

	/** @var string Message character set. */
	public string $CharSet = 'UTF-8';

	/** @var int SMTP debug output level (0 = disabled). */
	public int $SMTPDebug = 0;

	/** @var int Connection/read timeout in seconds (real PHPMailer's own default: 300). */
	public int $Timeout = 300;

	// -------------------------------------------------------------------------
	// Message fields (written by SmtpProvider::send()).
	// -------------------------------------------------------------------------

	/** @var string Sender address. */
	public string $From = '';

	/** @var string Sender display name. */
	public string $FromName = '';

	/** @var string Message subject. */
	public string $Subject = '';

	/** @var string Message body (HTML or plain text). */
	public string $Body = '';

	/** @var string Plain-text alternative body. */
	public string $AltBody = '';

	// -------------------------------------------------------------------------
	// Test-control properties.
	// -------------------------------------------------------------------------

	/** @var bool Return value of send() when no exception is configured. */
	public bool $send_result = true;

	/** @var bool Return value of smtpConnect(). */
	public bool $smtpConnect_result = true;

	/** @var Exception|null When set, smtpConnect() throws this exception. */
	public ?Exception $smtpConnect_exception = null;

	/** @var bool True after smtpClose() is called. */
	public bool $smtpClose_was_called = false;

	/** @var Exception|null When set, send() throws this exception. */
	public ?Exception $send_exception = null;

	// -------------------------------------------------------------------------
	// Call capture (for assertions).
	// -------------------------------------------------------------------------

	/** @var list<array{address: string, name: string}> Recipients added via addAddress(). */
	public array $recipients = array();

	/** @var list<array{name: string, value: string}> Custom headers added via addCustomHeader(). */
	public array $custom_headers = array();

	/** @var list<string> Attachment paths added via addAttachment(). */
	public array $attachments = array();

	/** @var SMTP Internal SMTP instance returned by getSMTPInstance(). */
	private SMTP $smtp_instance;

	/**
	 * @param bool $exceptions Unused in stub (exceptions are always on for tests).
	 */
	public function __construct( bool $exceptions = false ) {
		$this->smtp_instance = new SMTP();
	}

	/** @return void */
	public function isSMTP(): void {}

	/**
	 * @param string $address Sender RFC-5321 address.
	 * @param string $name    Sender display name.
	 * @param bool   $auto    Unused in stub.
	 * @return bool
	 */
	public function setFrom( string $address, string $name = '', bool $auto = true ): bool {
		$this->From     = $address;
		$this->FromName = $name;
		return true;
	}

	/**
	 * @param string $address Recipient RFC-5321 address.
	 * @param string $name    Recipient display name.
	 * @return bool
	 */
	public function addAddress( string $address, string $name = '' ): bool {
		$this->recipients[] = array(
			'address' => $address,
			'name'    => $name,
		);
		return true;
	}

	/**
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function addCustomHeader( string $name, string $value = '' ): void {
		$this->custom_headers[] = array(
			'name'  => $name,
			'value' => $value,
		);
	}

	/**
	 * @param string $path        Absolute filesystem path to the attachment.
	 * @param string $name        Attachment filename (unused in stub).
	 * @param string $encoding    Encoding (unused in stub).
	 * @param string $type        MIME type (unused in stub).
	 * @param string $disposition Disposition (unused in stub).
	 * @return bool
	 */
	public function addAttachment(
		string $path,
		string $name = '',
		string $encoding = 'base64',
		string $type = '',
		string $disposition = 'attachment'
	): bool {
		$this->attachments[] = $path;
		return true;
	}

	/**
	 * @param bool $is_html True for HTML body; false for plain text.
	 * @return void
	 */
	public function isHTML( bool $is_html = true ): void {}

	/**
	 * Sends the message. Throws Exception when send_exception is set or
	 * send_result is false.
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function send(): bool {
		if ( null !== $this->send_exception ) {
			throw $this->send_exception;
		}
		if ( ! $this->send_result ) {
			throw new Exception( 'Send failed.' );
		}
		return true;
	}

	/**
	 * @param array<string, mixed>|null $options Unused in stub.
	 * @return bool
	 * @throws Exception When smtpConnect_exception is set.
	 */
	public function smtpConnect( ?array $options = null ): bool {
		if ( null !== $this->smtpConnect_exception ) {
			throw $this->smtpConnect_exception;
		}
		return $this->smtpConnect_result;
	}

	/** @return void */
	public function smtpClose(): void {
		$this->smtpClose_was_called = true;
	}

	/**
	 * Returns the internal SMTP instance used for connection testing.
	 *
	 * @return SMTP
	 */
	public function getSMTPInstance(): SMTP {
		return $this->smtp_instance;
	}

	/**
	 * Injects a custom SMTP stub so tests can control authentication behaviour.
	 *
	 * @param SMTP $smtp SMTP stub instance.
	 * @return void
	 */
	public function setSmtpInstance( SMTP $smtp ): void {
		$this->smtp_instance = $smtp;
	}
}
