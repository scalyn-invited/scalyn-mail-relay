<?php
/** CLI-only, network-free check against WordPress's real PHPMailer, not test stubs. */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$root = isset( $argv[1] ) ? realpath( $argv[1] ) : false;
if ( false === $root || ! is_file( $root . '/wp-includes/PHPMailer/PHPMailer.php' ) ) {
	fwrite( STDERR, "Usage: php tests/manual/provider-mime-check.php <WordPress-root>\n" );
	exit( 1 );
}
define( 'ABSPATH', $root . '/' );
define( 'SCALYN_MAIL_RELAY_PATH', dirname( __DIR__, 2 ) . '/' );
require_once SCALYN_MAIL_RELAY_PATH . 'includes/Core/Autoloader.php';
\Scalyn\MailRelay\Core\Autoloader::register();

try {
	\Scalyn\MailRelay\Providers\Smtp\PhpMailerLoader::load();
	foreach ( array( 'text/plain', 'text/html' ) as $type ) {
		$mailer = new class(true) extends \PHPMailer\PHPMailer\PHPMailer {
			public function smtpConnect( $options = null ) { return true; }
			public function send() { return $this->preSend(); }
		};
		$provider = new class($mailer) extends \Scalyn\MailRelay\Providers\Smtp\SmtpProvider {
			public function __construct( private \PHPMailer\PHPMailer\PHPMailer $fixture ) {}
			protected function create_mailer(): \PHPMailer\PHPMailer\PHPMailer { return $this->fixture; }
		};
		$message = new \Scalyn\MailRelay\Mail\MailMessage(
			'12345678-1234-4234-8234-123456789abc',
			'Sender <sender@example.com>',
			array( 'Recipient <recipient@example.com>' ),
			'Local MIME verification — café',
			'text/html' === $type ? '<p>Synthetic content — café</p>' : 'Synthetic content — café',
			$type,
			array( 'Cc: Copy <copy@example.com>', 'Bcc: blind@example.com', 'Reply-To: Support <support@example.com>' ),
			array( __FILE__ )
		);
		$result = $provider->send( $message, array( 'host' => 'smtp.example.com', 'port' => 587, 'from_email' => 'sender@example.com' ) );
		$mime = $mailer->getSentMIMEMessage();
		$checks = array(
			'prepared' => $result->success,
			'content_type' => str_contains( $mime, 'Content-Type: ' . $type ),
			'attachment' => str_contains( $mime, 'Content-Disposition: attachment' ),
			'bcc_private' => ! str_contains( $mime, 'blind@example.com' ),
			'cc_preserved' => count( $mailer->getCcAddresses() ) === 1,
			'bcc_envelope_preserved' => count( $mailer->getBccAddresses() ) === 1,
			'reply_to_preserved' => count( $mailer->getReplyToAddresses() ) === 1,
		);
		if ( in_array( false, $checks, true ) ) {
			throw new \RuntimeException( 'Local MIME check failed.' );
		}
		echo $type . ": local MIME checks passed; no network request or email sent.\n";
	}
} catch ( \Throwable $error ) {
	fwrite( STDERR, "Local MIME checks failed; no provider delivery claim is made.\n" );
	exit( 1 );
}
