<?php

use PHPUnit\Framework\TestCase;
use PHPMailer\PHPMailer\PHPMailer as StubPHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\Smtp\PhpMailerLoader;
use Scalyn\MailRelay\Providers\Smtp\SmtpProvider;
use Scalyn\MailRelay\Providers\ValidationResult;

/**
 * Concrete SmtpProvider subclass that injects a stub PHPMailer instance.
 *
 * Overriding create_mailer() avoids any real network calls and prevents
 * PhpMailerLoader from needing WordPress-bundled files during tests.
 */
final class TestSmtpProvider extends SmtpProvider {

	private StubPHPMailer $stub_mailer;

	public function __construct( StubPHPMailer $stub_mailer ) {
		$this->stub_mailer = $stub_mailer;
	}

	protected function create_mailer(): StubPHPMailer {
		return $this->stub_mailer;
	}
}

final class SmtpProviderTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a valid SMTP configuration array.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_config(): array {
		return array(
			'host'       => 'mail.example.com',
			'port'       => 587,
			'encryption' => 'tls',
			'username'   => 'user@example.com',
			'password'   => 'correct-horse-battery-staple',
			'from_name'  => 'Test Sender',
			'from_email' => 'from@example.com',
		);
	}

	/**
	 * Returns a valid SMTP configuration with no authentication.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_config_no_auth(): array {
		return array(
			'host'       => 'relay.internal.example.com',
			'port'       => 25,
			'encryption' => 'none',
			'username'   => '',
			'password'   => '',
			'from_name'  => '',
			'from_email' => 'from@example.com',
		);
	}

	/**
	 * Returns a minimal MailMessage for send() tests.
	 *
	 * @return MailMessage
	 */
	private function make_message(): MailMessage {
		return new MailMessage(
			uuid: 'test-uuid-001',
			from: 'from@example.com',
			to: array( 'to@example.com' ),
			subject: 'Test Subject',
			body: '<p>Test body</p>'
		);
	}

	/**
	 * Returns a TestSmtpProvider wrapping the given stub mailer.
	 *
	 * @param StubPHPMailer|null $mailer Stub to inject; creates a fresh stub if null.
	 * @return TestSmtpProvider
	 */
	private function make_provider( ?StubPHPMailer $mailer = null ): TestSmtpProvider {
		return new TestSmtpProvider( $mailer ?? new StubPHPMailer() );
	}

	// -------------------------------------------------------------------------
	// Provider identity
	// -------------------------------------------------------------------------

	public function test_get_id_returns_smtp(): void {
		$this->assertSame( 'smtp', ( new SmtpProvider() )->get_id() );
	}

	public function test_get_label_returns_descriptive_string(): void {
		$label = ( new SmtpProvider() )->get_label();
		$this->assertNotEmpty( $label );
		$this->assertStringContainsStringIgnoringCase( 'smtp', $label );
	}

	public function test_get_capabilities_includes_html_and_attachments(): void {
		$caps = ( new SmtpProvider() )->get_capabilities();
		$this->assertContains( 'html', $caps );
		$this->assertContains( 'attachments', $caps );
	}

	// -------------------------------------------------------------------------
	// validate_config — valid cases
	// -------------------------------------------------------------------------

	public function test_validate_config_accepts_full_authenticated_config(): void {
		$result = ( new SmtpProvider() )->validate_config( $this->valid_config() );
		$this->assertTrue( $result->valid );
		$this->assertEmpty( $result->errors );
	}

	public function test_validate_config_accepts_no_auth_config(): void {
		$result = ( new SmtpProvider() )->validate_config( $this->valid_config_no_auth() );
		$this->assertTrue( $result->valid );
		$this->assertEmpty( $result->errors );
	}

	public function test_validate_config_accepts_ssl_encryption(): void {
		$config               = $this->valid_config();
		$config['encryption'] = 'ssl';
		$config['port']       = 465;
		$result               = ( new SmtpProvider() )->validate_config( $config );
		$this->assertTrue( $result->valid );
	}

	// -------------------------------------------------------------------------
	// validate_config — host validation
	// -------------------------------------------------------------------------

	public function test_validate_config_rejects_empty_host(): void {
		$config         = $this->valid_config();
		$config['host'] = '';
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'host', $result->errors );
	}

	public function test_validate_config_rejects_host_with_http_scheme(): void {
		$config         = $this->valid_config();
		$config['host'] = 'http://mail.example.com';
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'host', $result->errors );
	}

	public function test_validate_config_rejects_host_with_smtp_scheme(): void {
		$config         = $this->valid_config();
		$config['host'] = 'smtp://mail.example.com';
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'host', $result->errors );
	}

	public function test_validate_config_rejects_host_with_control_character(): void {
		$config         = $this->valid_config();
		$config['host'] = "mail.example.com\x00";
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'host', $result->errors );
	}

	public function test_validate_config_rejects_host_with_newline_injection(): void {
		$config         = $this->valid_config();
		$config['host'] = "mail.example.com\r\nX-Injected: header";
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'host', $result->errors );
	}

	public function test_validate_config_rejects_host_with_embedded_space(): void {
		$config         = $this->valid_config();
		$config['host'] = 'mail example.com';
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'host', $result->errors );
	}

	public function test_validate_config_accepts_ipv4_host(): void {
		$config         = $this->valid_config();
		$config['host'] = '192.168.1.100';
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertTrue( $result->valid );
	}

	// -------------------------------------------------------------------------
	// validate_config — port validation
	// -------------------------------------------------------------------------

	public function test_validate_config_rejects_port_zero(): void {
		$config         = $this->valid_config();
		$config['port'] = 0;
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'port', $result->errors );
	}

	public function test_validate_config_rejects_port_above_65535(): void {
		$config         = $this->valid_config();
		$config['port'] = 65536;
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'port', $result->errors );
	}

	public function test_validate_config_accepts_port_1(): void {
		$config         = $this->valid_config();
		$config['port'] = 1;
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertTrue( $result->valid );
	}

	public function test_validate_config_accepts_port_65535(): void {
		$config         = $this->valid_config();
		$config['port'] = 65535;
		$result         = ( new SmtpProvider() )->validate_config( $config );
		$this->assertTrue( $result->valid );
	}

	// -------------------------------------------------------------------------
	// validate_config — from_email validation
	// -------------------------------------------------------------------------

	public function test_validate_config_rejects_empty_from_email(): void {
		$config               = $this->valid_config();
		$config['from_email'] = '';
		$result               = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'from_email', $result->errors );
	}

	public function test_validate_config_rejects_invalid_from_email(): void {
		$config               = $this->valid_config();
		$config['from_email'] = 'not-an-email';
		$result               = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'from_email', $result->errors );
	}

	public function test_validate_config_rejects_from_email_missing_at_sign(): void {
		$config               = $this->valid_config();
		$config['from_email'] = 'userexample.com';
		$result               = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'from_email', $result->errors );
	}

	// -------------------------------------------------------------------------
	// validate_config — authentication validation
	// -------------------------------------------------------------------------

	/**
	 * Requirement: username present + empty password = invalid (incomplete auth config).
	 */
	public function test_validate_config_rejects_username_with_empty_password(): void {
		$config             = $this->valid_config();
		$config['password'] = '';
		$result             = ( new SmtpProvider() )->validate_config( $config );
		$this->assertFalse( $result->valid );
		$this->assertArrayHasKey( 'password', $result->errors );
	}

	public function test_validate_config_accepts_username_with_non_empty_password(): void {
		$result = ( new SmtpProvider() )->validate_config( $this->valid_config() );
		$this->assertTrue( $result->valid );
	}

	public function test_validate_config_accepts_empty_username_and_empty_password(): void {
		$result = ( new SmtpProvider() )->validate_config( $this->valid_config_no_auth() );
		$this->assertTrue( $result->valid );
	}

	// -------------------------------------------------------------------------
	// send() — success: no fabricated IDs or codes
	// -------------------------------------------------------------------------

	/**
	 * Requirement: provider_message_id must be null on generic SMTP success.
	 * PHPMailer's RFC Message-ID is not a provider-assigned transaction ID.
	 */
	public function test_send_success_provider_message_id_is_null(): void {
		$result = $this->make_provider()->send( $this->make_message(), $this->valid_config() );
		$this->assertTrue( $result->success );
		$this->assertNull( $result->provider_message_id );
	}

	/**
	 * Requirement: response_code must not be fabricated as '250' on success.
	 */
	public function test_send_success_response_code_is_null(): void {
		$result = $this->make_provider()->send( $this->make_message(), $this->valid_config() );
		$this->assertTrue( $result->success );
		$this->assertNull( $result->response_code );
		$this->assertNotSame( '250', $result->response_code );
	}

	/**
	 * Requirement: success message must not say "Delivered".
	 */
	public function test_send_success_message_does_not_contain_delivered(): void {
		$result = $this->make_provider()->send( $this->make_message(), $this->valid_config() );
		$this->assertTrue( $result->success );
		$this->assertNotNull( $result->response_message );
		$this->assertStringNotContainsStringIgnoringCase( 'delivered', (string) $result->response_message );
	}

	public function test_send_success_provider_id_is_smtp(): void {
		$result = $this->make_provider()->send( $this->make_message(), $this->valid_config() );
		$this->assertSame( 'smtp', $result->provider );
	}

	// -------------------------------------------------------------------------
	// send() — failure normalisation
	// -------------------------------------------------------------------------

	/**
	 * Requirement: raw PHPMailer exception messages must not be exposed in results.
	 */
	public function test_send_failure_does_not_expose_raw_phpmailer_exception(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'SMTP server said: 550 5.1.1 User unknown' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		// Raw server response must not appear in the normalised message.
		$this->assertStringNotContainsString( '550 5.1.1', (string) $result->response_message );
		$this->assertStringNotContainsString( 'User unknown', (string) $result->response_message );
	}

	/**
	 * Requirement: passwords must not appear in result messages or metadata.
	 */
	public function test_send_failure_does_not_expose_password_in_result(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'authenticate: password incorrect-horse-battery-staple' );

		$config = $this->valid_config();
		$result = $this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertStringNotContainsString( $config['password'], (string) $result->response_message );
		// Metadata must also be free of the password.
		$this->assertStringNotContainsString( $config['password'], implode( ' ', array_map( 'strval', $result->metadata ) ) );
	}

	public function test_send_normalises_connection_exception_to_safe_message(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'SMTP connect() failed.' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::CONNECTIVITY, $result->failure_category );
		$this->assertTrue( $result->retryable );
		$this->assertStringContainsStringIgnoringCase( 'connect', (string) $result->response_message );
	}

	public function test_send_normalises_auth_exception_to_safe_message(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'SMTP authenticate: failed.' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::AUTH, $result->failure_category );
		$this->assertStringContainsStringIgnoringCase( 'authentication', (string) $result->response_message );
	}

	public function test_send_normalises_tls_exception_to_safe_message(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'Could not connect: TLS negotiation failed.' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::TLS, $result->failure_category );
		$this->assertStringContainsStringIgnoringCase( 'secure', (string) $result->response_message );
	}

	public function test_send_normalises_certificate_exception_to_safe_message(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'SSL operation failed: certificate verify failed.' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::CERTIFICATE, $result->failure_category );
		$this->assertFalse( $result->retryable );
		$this->assertStringContainsStringIgnoringCase( 'certificate', (string) $result->response_message );
	}

	public function test_send_normalises_timeout_exception_to_safe_message(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'Connection timed out.' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::TIMEOUT, $result->failure_category );
		$this->assertTrue( $result->retryable );
		$this->assertStringContainsStringIgnoringCase( 'timed out', (string) $result->response_message );
	}

	public function test_send_normalises_provider_rejection_exception_to_safe_message(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'SMTP Error: The following recipients failed: to@example.com' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::PROVIDER_REJECTION, $result->failure_category );
		$this->assertFalse( $result->retryable );
		$this->assertStringNotContainsString( 'to@example.com', (string) $result->response_message );
	}

	/**
	 * Requirement: an unrecognized failure must be classified as UNKNOWN, never
	 * guessed into a specific category — an inability to classify is not
	 * evidence of a specific problem.
	 */
	public function test_send_normalises_unrecognized_exception_to_unknown_fallback(): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'Something unexpected happened.' );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::UNKNOWN, $result->failure_category );
		$this->assertFalse( $result->retryable );
		$this->assertSame( 'SMTP transport failed.', $result->response_message );
	}

	/**
	 * Requirement: redaction must hold across every failure category — the
	 * sanitized message is always one of a small fixed set, never derived
	 * from the raw exception text, and response_code/metadata stay empty.
	 *
	 * @dataProvider transportFailureMessageProvider
	 */
	public function test_send_failure_redaction_across_all_categories( string $raw_message ): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( $raw_message );
		$result               = $this->make_provider( $stub )->send( $this->make_message(), $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertNull( $result->response_code );
		$this->assertSame( array(), $result->metadata );
		$this->assertContains(
			$result->response_message,
			array(
				"The server's TLS certificate could not be verified.",
				'Unable to establish the requested secure connection.',
				'The connection to the SMTP server timed out.',
				'Unable to connect to the SMTP server.',
				'SMTP authentication failed.',
				'The SMTP server did not accept the message.',
				'SMTP transport failed.',
			)
		);
	}

	/**
	 * Requirement: the same underlying failure must map to the same sanitized
	 * message whether it surfaces through send() or test_connection().
	 *
	 * @dataProvider transportFailureMessageProvider
	 */
	public function test_connection_message_matches_send_failure_message_for_same_exception( string $raw_message ): void {
		$send_stub                 = new StubPHPMailer();
		$send_stub->send_exception = new PHPMailerException( $raw_message );
		$send_result               = $this->make_provider( $send_stub )->send( $this->make_message(), $this->valid_config() );

		$connect_stub                        = new StubPHPMailer();
		$connect_stub->smtpConnect_exception = new PHPMailerException( $raw_message );
		$connection_result                   = $this->make_provider( $connect_stub )->test_connection( $this->valid_config() );

		$this->assertSame( $send_result->response_message, $connection_result->message );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function transportFailureMessageProvider(): array {
		return array(
			'certificate'        => array( 'SSL operation failed: certificate verify failed.' ),
			'tls'                => array( 'Could not connect: TLS negotiation failed.' ),
			'timeout'            => array( 'Connection timed out.' ),
			'connectivity'       => array( 'SMTP connect() failed.' ),
			'auth'               => array( 'authenticate: password incorrect-horse-battery-staple' ),
			'provider-rejection' => array( 'SMTP Error: The following recipients failed: to@example.com' ),
			'unknown'            => array( 'Something unexpected happened.' ),
		);
	}

	// -------------------------------------------------------------------------
	// send() — encryption = none must not enable STARTTLS opportunistically
	// -------------------------------------------------------------------------

	/**
	 * Requirement: PHPMailer's SMTPAutoTLS must be false when encryption is 'none'.
	 * If left at its default (true), PHPMailer upgrades opportunistically even
	 * when the user explicitly selected no encryption.
	 */
	public function test_send_encryption_none_sets_smtp_auto_tls_false(): void {
		$stub   = new StubPHPMailer();
		$config = $this->valid_config_no_auth();

		$this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertFalse( $stub->SMTPAutoTLS, 'SMTPAutoTLS must be false when encryption is none.' );
		$this->assertSame( '', $stub->SMTPSecure, 'SMTPSecure must be empty string when encryption is none.' );
	}

	public function test_send_encryption_tls_uses_starttls_constant(): void {
		$stub   = new StubPHPMailer();
		$config = $this->valid_config();

		$this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertSame( StubPHPMailer::ENCRYPTION_STARTTLS, $stub->SMTPSecure );
	}

	public function test_send_encryption_ssl_uses_smtps_constant(): void {
		$stub                 = new StubPHPMailer();
		$config               = $this->valid_config();
		$config['encryption'] = 'ssl';

		$this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertSame( StubPHPMailer::ENCRYPTION_SMTPS, $stub->SMTPSecure );
	}

	// -------------------------------------------------------------------------
	// send() / test_connection() — regression matrix (S3)
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function transportConfigMatrixProvider(): array {
		$base    = array(
			'host'       => 'mail.example.com',
			'from_name'  => '',
			'from_email' => 'from@example.com',
		);
		$auth    = array(
			'username' => 'user@example.com',
			'password' => 'correct-horse-battery-staple',
		);
		$no_auth = array(
			'username' => '',
			'password' => '',
		);

		return array(
			'tls-587-auth'    => array(
				array_merge(
					$base,
					$auth,
					array(
						'port'       => 587,
						'encryption' => 'tls',
					)
				),
				array(
					'SMTPSecure'  => 'tls',
					'SMTPAutoTLS' => true,
					'SMTPAuth'    => true,
					'Port'        => 587,
				),
			),
			'tls-587-no-auth' => array(
				array_merge(
					$base,
					$no_auth,
					array(
						'port'       => 587,
						'encryption' => 'tls',
					)
				),
				array(
					'SMTPSecure'  => 'tls',
					'SMTPAutoTLS' => true,
					'SMTPAuth'    => false,
					'Port'        => 587,
				),
			),
			'ssl-465-auth'    => array(
				array_merge(
					$base,
					$auth,
					array(
						'port'       => 465,
						'encryption' => 'ssl',
					)
				),
				array(
					'SMTPSecure'  => 'ssl',
					'SMTPAutoTLS' => true,
					'SMTPAuth'    => true,
					'Port'        => 465,
				),
			),
			'ssl-465-no-auth' => array(
				array_merge(
					$base,
					$no_auth,
					array(
						'port'       => 465,
						'encryption' => 'ssl',
					)
				),
				array(
					'SMTPSecure'  => 'ssl',
					'SMTPAutoTLS' => true,
					'SMTPAuth'    => false,
					'Port'        => 465,
				),
			),
			'none-25-no-auth' => array(
				array_merge(
					$base,
					$no_auth,
					array(
						'port'       => 25,
						'encryption' => 'none',
					)
				),
				array(
					'SMTPSecure'  => '',
					'SMTPAutoTLS' => false,
					'SMTPAuth'    => false,
					'Port'        => 25,
				),
			),
			'none-25-auth'    => array(
				array_merge(
					$base,
					$auth,
					array(
						'port'       => 25,
						'encryption' => 'none',
					)
				),
				array(
					'SMTPSecure'  => '',
					'SMTPAutoTLS' => false,
					'SMTPAuth'    => true,
					'Port'        => 25,
				),
			),
		);
	}

	/**
	 * @dataProvider transportConfigMatrixProvider
	 */
	public function test_send_regression_matrix_configures_mailer_and_succeeds( array $config, array $expected ): void {
		$stub = new StubPHPMailer();

		$result = $this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertTrue( $result->success, 'send() must succeed for a valid configuration combination.' );
		$this->assertSame( $expected['SMTPSecure'], $stub->SMTPSecure );
		$this->assertSame( $expected['SMTPAutoTLS'], $stub->SMTPAutoTLS );
		$this->assertSame( $expected['SMTPAuth'], $stub->SMTPAuth );
		$this->assertSame( $expected['Port'], $stub->Port );
		$this->assertSame( 15, $stub->Timeout );
	}

	/**
	 * @dataProvider transportConfigMatrixProvider
	 */
	public function test_connection_regression_matrix_configures_mailer_and_succeeds( array $config, array $expected ): void {
		$stub = new StubPHPMailer();

		$result = $this->make_provider( $stub )->test_connection( $config );

		$this->assertTrue( $result->success, 'test_connection() must succeed for a valid configuration combination.' );
		$this->assertSame( $expected['SMTPSecure'], $stub->SMTPSecure );
		$this->assertSame( $expected['SMTPAutoTLS'], $stub->SMTPAutoTLS );
		$this->assertSame( $expected['SMTPAuth'], $stub->SMTPAuth );
		$this->assertSame( $expected['Port'], $stub->Port );
		$this->assertSame( 15, $stub->Timeout );
		$this->assertTrue( $stub->smtpClose_was_called );
	}

	/**
	 * Requirement: failure classification is derived purely from exception text,
	 * never from configuration state — the same TLS-text failure must classify
	 * identically no matter which encryption mode was configured at failure time.
	 *
	 * @dataProvider encryptionModeProvider
	 */
	public function test_send_failure_classification_stable_across_encryption_modes( string $encryption, int $port ): void {
		$stub                 = new StubPHPMailer();
		$stub->send_exception = new PHPMailerException( 'Could not connect: TLS negotiation failed.' );

		$config               = $this->valid_config();
		$config['encryption'] = $encryption;
		$config['port']       = $port;

		$result = $this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertSame(
			TransportFailureCategory::TLS,
			$result->failure_category,
			"TLS-text failures must classify as TLS regardless of configured encryption ({$encryption})."
		);
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function encryptionModeProvider(): array {
		return array(
			'tls-587' => array( 'tls', 587 ),
			'ssl-465' => array( 'ssl', 465 ),
			'none-25' => array( 'none', 25 ),
		);
	}

	// -------------------------------------------------------------------------
	// send() / test_connection() — malformed config guard (S3)
	// -------------------------------------------------------------------------

	public function test_send_returns_config_failure_for_malformed_host(): void {
		$config         = $this->valid_config();
		$config['host'] = "mail.example.com\r\nX-Injected: header";

		$result = $this->make_provider()->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::CONFIG, $result->failure_category );
		$this->assertSame( 'SMTP host contains invalid characters or an unsupported format.', $result->response_message );
	}

	/**
	 * Requirement: a malformed config must never echo the raw invalid value back
	 * to the caller — only the static validate_config() error text is surfaced.
	 */
	public function test_send_config_failure_message_never_contains_raw_malformed_host(): void {
		$config         = $this->valid_config();
		$config['host'] = "mail.example.com\r\nX-Injected: header";

		$result = $this->make_provider()->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertStringNotContainsString( 'X-Injected', (string) $result->response_message );
		$this->assertStringNotContainsString( "\r\n", (string) $result->response_message );
	}

	public function test_send_returns_config_failure_for_url_scheme_host(): void {
		$config         = $this->valid_config();
		$config['host'] = 'http://mail.example.com';

		$result = $this->make_provider()->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::CONFIG, $result->failure_category );
		$this->assertStringNotContainsString( 'http://mail.example.com', (string) $result->response_message );
	}

	public function test_send_returns_config_failure_for_out_of_range_port(): void {
		$config         = $this->valid_config();
		$config['port'] = 99999;

		$result = $this->make_provider()->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::CONFIG, $result->failure_category );
		$this->assertSame( 'SMTP port must be between 1 and 65535.', $result->response_message );
	}

	public function test_send_returns_config_failure_for_username_without_password(): void {
		$config             = $this->valid_config();
		$config['password'] = '';

		$result = $this->make_provider()->send( $this->make_message(), $config );

		$this->assertFalse( $result->success );
		$this->assertSame( TransportFailureCategory::CONFIG, $result->failure_category );
		$this->assertSame( 'SMTP password is required when a username is provided.', $result->response_message );
	}

	public function test_send_config_guard_short_circuits_before_touching_mailer(): void {
		$stub           = new StubPHPMailer();
		$config         = $this->valid_config();
		$config['host'] = "mail.example.com\r\nX-Injected: header";

		$this->make_provider( $stub )->send( $this->make_message(), $config );

		$this->assertSame( '', $stub->Host, 'PHPMailer must not be configured when config validation fails.' );
	}

	public function test_connection_returns_safe_message_for_malformed_host(): void {
		$config         = $this->valid_config();
		$config['host'] = "mail.example.com\r\nX-Injected: header";

		$result = $this->make_provider()->test_connection( $config );

		$this->assertFalse( $result->success );
		$this->assertSame( 'SMTP host contains invalid characters or an unsupported format.', $result->message );
		$this->assertStringNotContainsString( 'X-Injected', $result->message );
	}

	public function test_connection_config_guard_short_circuits_before_touching_mailer(): void {
		$stub           = new StubPHPMailer();
		$config         = $this->valid_config();
		$config['port'] = 0;

		$this->make_provider( $stub )->test_connection( $config );

		$this->assertFalse( $stub->smtpClose_was_called, 'smtpConnect()/smtpClose() must never run when config validation fails.' );
	}

	// -------------------------------------------------------------------------
	// send() — address validation
	// -------------------------------------------------------------------------

	public function test_send_returns_config_failure_for_invalid_sender_address(): void {
		$message = new MailMessage(
			uuid: 'bad-from-001',
			from: 'not-an-email',
			to: array( 'to@example.com' ),
			subject: 'Test',
			body: 'body'
		);

		$result = $this->make_provider()->send( $message, $this->valid_config() );
		$this->assertFalse( $result->success );
		$this->assertSame( 'config', $result->failure_category );
	}

	public function test_send_returns_config_failure_for_invalid_recipient_address(): void {
		$message = new MailMessage(
			uuid: 'bad-to-001',
			from: 'from@example.com',
			to: array( 'not-an-email' ),
			subject: 'Test',
			body: 'body'
		);

		$result = $this->make_provider()->send( $message, $this->valid_config() );
		$this->assertFalse( $result->success );
		$this->assertSame( 'config', $result->failure_category );
	}

	public function test_send_returns_config_failure_when_to_is_empty(): void {
		$message = new MailMessage(
			uuid: 'no-to-001',
			from: 'from@example.com',
			to: array(),
			subject: 'Test',
			body: 'body'
		);

		$result = $this->make_provider()->send( $message, $this->valid_config() );
		$this->assertFalse( $result->success );
		$this->assertSame( 'config', $result->failure_category );
	}

	public function test_send_parses_display_name_address_format(): void {
		$stub    = new StubPHPMailer();
		$message = new MailMessage(
			uuid: 'display-name-001',
			from: 'Test Sender <from@example.com>',
			to: array( 'Recipient Name <to@example.com>' ),
			subject: 'Test',
			body: 'body'
		);

		$result = $this->make_provider( $stub )->send( $message, $this->valid_config() );

		$this->assertTrue( $result->success );
		$this->assertSame( 'from@example.com', $stub->From );
		$this->assertSame( 'Test Sender', $stub->FromName );
		$this->assertCount( 1, $stub->recipients );
		$this->assertSame( 'to@example.com', $stub->recipients[0]['address'] );
		$this->assertSame( 'Recipient Name', $stub->recipients[0]['name'] );
	}

	// -------------------------------------------------------------------------
	// send() — header handling
	// -------------------------------------------------------------------------

	/**
	 * Structural headers managed by PHPMailer must not be passed via addCustomHeader().
	 */
	public function test_send_skips_from_header(): void {
		$stub    = new StubPHPMailer();
		$message = new MailMessage(
			uuid: 'header-from-001',
			from: 'from@example.com',
			to: array( 'to@example.com' ),
			subject: 'Test',
			body: 'body',
			headers: array( 'From: override@evil.com' )
		);

		$this->make_provider( $stub )->send( $message, $this->valid_config() );

		$header_names = array_map( 'strtolower', array_column( $stub->custom_headers, 'name' ) );
		$this->assertNotContains( 'from', $header_names, 'From header must not be passed to addCustomHeader().' );
	}

	public function test_send_skips_subject_header(): void {
		$stub    = new StubPHPMailer();
		$message = new MailMessage(
			uuid: 'header-subject-001',
			from: 'from@example.com',
			to: array( 'to@example.com' ),
			subject: 'Test',
			body: 'body',
			headers: array( 'Subject: Injected Subject' )
		);

		$this->make_provider( $stub )->send( $message, $this->valid_config() );

		$header_names = array_map( 'strtolower', array_column( $stub->custom_headers, 'name' ) );
		$this->assertNotContains( 'subject', $header_names, 'Subject header must not be passed to addCustomHeader().' );
	}

	public function test_send_discards_header_containing_newline(): void {
		$stub    = new StubPHPMailer();
		$message = new MailMessage(
			uuid: 'header-inject-001',
			from: 'from@example.com',
			to: array( 'to@example.com' ),
			subject: 'Test',
			body: 'body',
			headers: array( "X-Custom: value\r\nBcc: attacker@evil.com" )
		);

		$this->make_provider( $stub )->send( $message, $this->valid_config() );

		// The entire header must be discarded because it contains CR+LF.
		$this->assertEmpty( $stub->custom_headers, 'Headers containing newlines must be silently discarded.' );
	}

	public function test_send_passes_safe_x_custom_header(): void {
		$stub    = new StubPHPMailer();
		$message = new MailMessage(
			uuid: 'header-safe-001',
			from: 'from@example.com',
			to: array( 'to@example.com' ),
			subject: 'Test',
			body: 'body',
			headers: array( 'X-Campaign-ID: abc123' )
		);

		$this->make_provider( $stub )->send( $message, $this->valid_config() );

		$found = false;
		foreach ( $stub->custom_headers as $header ) {
			if ( 'X-Campaign-ID' === $header['name'] && 'abc123' === $header['value'] ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Safe custom header must be passed to PHPMailer.' );
	}

	// -------------------------------------------------------------------------
	// test_connection() — connection lifecycle
	// -------------------------------------------------------------------------

	public function test_connection_success_returns_true_result(): void {
		$result = $this->make_provider()->test_connection( $this->valid_config() );
		$this->assertTrue( $result->success );
	}

	public function test_connection_calls_smtp_close_on_success(): void {
		$stub   = new StubPHPMailer();
		$result = $this->make_provider( $stub )->test_connection( $this->valid_config() );

		$this->assertTrue( $result->success );
		$this->assertTrue( $stub->smtpClose_was_called, 'smtpClose() must be called on successful connection.' );
	}

	public function test_connection_calls_smtp_close_when_auth_fails_after_connect(): void {
		// smtpConnect() in PHPMailer exceptions-mode throws (never returns false)
		// when authentication fails. It also calls quit() internally before throwing.
		// Our smtpClose() in the catch block is still called (idempotent on disconnect).
		$stub                        = new StubPHPMailer();
		$stub->smtpConnect_exception = new PHPMailerException( 'SMTP Error: Could not authenticate.' );

		$result = $this->make_provider( $stub )->test_connection( $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'SMTP authentication failed.', $result->message );
		$this->assertTrue( $stub->smtpClose_was_called, 'smtpClose() must be called in catch block even after smtpConnect() throws.' );
	}

	public function test_connection_calls_smtp_close_when_exception_is_thrown(): void {
		$stub                        = new StubPHPMailer();
		$stub->smtpConnect_exception = new PHPMailerException( 'TLS negotiation failed.' );

		$result = $this->make_provider( $stub )->test_connection( $this->valid_config() );

		$this->assertFalse( $result->success );
		$this->assertTrue( $stub->smtpClose_was_called, 'smtpClose() must be called when smtpConnect() throws.' );
	}

	public function test_connection_returns_failure_when_smtp_connect_returns_false(): void {
		$stub                     = new StubPHPMailer();
		$stub->smtpConnect_result = false;

		$result = $this->make_provider( $stub )->test_connection( $this->valid_config() );

		$this->assertFalse( $result->success );
	}

	public function test_connection_with_no_credentials_succeeds_and_closes(): void {
		$stub = new StubPHPMailer();

		$result = $this->make_provider( $stub )->test_connection( $this->valid_config_no_auth() );

		$this->assertTrue( $result->success );
		$this->assertTrue( $stub->smtpClose_was_called, 'smtpClose() must be called on success with no-auth config.' );
	}

	// -------------------------------------------------------------------------
	// PHPMailer loader
	// -------------------------------------------------------------------------

	/**
	 * Requirement: PHPMailer classes are pre-loaded via stubs in the test bootstrap.
	 * PhpMailerLoader::load() must be a no-op when classes already exist.
	 */
	public function test_phpmailer_loader_is_no_op_when_classes_already_loaded(): void {
		$this->assertTrue(
			class_exists( 'PHPMailer\\PHPMailer\\PHPMailer', false ),
			'PHPMailer stubs must be loaded before this test runs.'
		);

		// Must not throw or require any filesystem access.
		PhpMailerLoader::load();

		$this->assertTrue( class_exists( 'PHPMailer\\PHPMailer\\PHPMailer', false ) );
	}

	// -------------------------------------------------------------------------
	// Provider registration
	// -------------------------------------------------------------------------

	public function test_provider_can_be_registered_in_provider_registry(): void {
		$registry = new ProviderRegistry();
		$registry->register( new SmtpProvider() );

		$this->assertTrue( $registry->has( 'smtp' ) );
		$this->assertInstanceOf( SmtpProvider::class, $registry->get( 'smtp' ) );
	}

	public function test_provider_registration_replaces_existing_registration(): void {
		$registry = new ProviderRegistry();
		$registry->register( new SmtpProvider() );
		$registry->register( new SmtpProvider() ); // second registration must not throw.

		$this->assertTrue( $registry->has( 'smtp' ) );
	}
}
