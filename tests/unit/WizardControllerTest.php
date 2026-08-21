<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\WizardController;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;
use Scalyn\MailRelay\Contracts\ProviderInterface;

/**
 * Stub provider for WizardController tests.
 *
 * Allows control of validate_config, test_connection, and send results.
 */
final class WizardTestProvider implements ProviderInterface {
	public string $id    = 'smtp';
	public string $label = 'SMTP (Test)';
	public ?ValidationResult $validation_result = null;
	public ?ConnectionResult $connection_result = null;
	public ?SendResult $send_result = null;

	/** Records the config passed to validate_config(). */
	public array $last_validate_config = array();

	/** Records the message passed to send(). */
	public ?MailMessage $last_sent_message = null;

	/** Tracks whether send() was called. */
	public bool $send_called = false;

	public function get_id(): string { return $this->id; }
	public function get_label(): string { return $this->label; }
	public function get_capabilities(): array { return array(); }

	public function validate_config( array $config ): ValidationResult {
		$this->last_validate_config = $config;
		return $this->validation_result ?? new ValidationResult( true );
	}

	public function test_connection( array $config ): ConnectionResult {
		return $this->connection_result ?? new ConnectionResult( true, 'Connection OK.' );
	}

	public function send( MailMessage $message, array $config ): SendResult {
		$this->send_called       = true;
		$this->last_sent_message = $message;
		return $this->send_result ?? new SendResult( true, 'smtp', null, null, 'Message accepted by the configured SMTP server.', false );
	}
}

final class WizardControllerTest extends TestCase {

	private WizardTestProvider $provider;
	private ProviderRegistry   $registry;

	protected function setUp(): void {
		// Reset Plugin singleton so each test gets a fresh Container.
		$reflection = new ReflectionClass( Plugin::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Reset all test globals.
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_transients']    = array();
		$GLOBALS['_test_current_user_id']  = 1;
		$GLOBALS['_test_wp_nonce_valid']   = true; // Default: nonce is valid.
		$GLOBALS['_test_wp_redirect']      = null;
		$GLOBALS['_test_current_user_can'] = array();

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Build the stub provider and registry.
		$this->provider = new WizardTestProvider();
		$this->registry = new ProviderRegistry();
		$this->registry->register( $this->provider );

		// Pre-populate the fresh container with our stubs.
		$container    = Plugin::instance()->container();
		$registry_ref = $this->registry;
		$container->set( ProviderRegistry::class, $this->registry );
		// SettingsRepository factory so each get() produces a fresh instance.
		$container->set( SettingsRepository::class, static fn() => new SettingsRepository() );
		// MailDispatcher constructed with our stub registry and fresh settings.
		$container->set(
			MailDispatcher::class,
			static fn() => new MailDispatcher( $registry_ref, new SettingsRepository() )
		);
	}

	protected function tearDown(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sets up a POST for the given step with capability granted.
	 */
	private function post_step( int $step, array $extra = array() ): void {
		$_POST = array_merge( array( 'wizard_step' => (string) $step ), $extra );
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;
	}

	/**
	 * Returns the redirect URL stored by the wp_safe_redirect stub.
	 */
	private function get_redirect(): ?string {
		return $GLOBALS['_test_wp_redirect'];
	}

	/**
	 * Runs the controller and catches the WpRedirectException thrown by
	 * the wp_safe_redirect stub (which simulates the exit; call that follows).
	 */
	private function run_handle(): void {
		try {
			( new WizardController() )->handle();
		} catch ( WpRedirectException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — redirect occurred; test asserts on $GLOBALS['_test_wp_redirect'].
		}
	}

	// -------------------------------------------------------------------------
	// Step 2 — Provider selection
	// -------------------------------------------------------------------------

	public function test_step2_valid_selection_saves_provider_and_redirects_to_step3(): void {
		$this->post_step( 2, array( 'provider_id' => 'smtp' ) );

		$this->run_handle();

		$settings = new SettingsRepository();
		$this->assertSame( 'smtp', $settings->get_active_provider_id() );
		$this->assertStringContainsString( 'step=3', (string) $this->get_redirect() );
	}

	public function test_step2_unregistered_provider_id_is_rejected(): void {
		$this->post_step( 2, array( 'provider_id' => 'nonexistent' ) );

		$this->run_handle();

		$settings = new SettingsRepository();
		$this->assertSame( '', $settings->get_active_provider_id() );
		$this->assertStringContainsString( 'step=2', (string) $this->get_redirect() );
	}

	public function test_step2_empty_provider_id_is_rejected(): void {
		$this->post_step( 2, array( 'provider_id' => '' ) );

		$this->run_handle();

		$settings = new SettingsRepository();
		$this->assertSame( '', $settings->get_active_provider_id() );
		$this->assertStringContainsString( 'step=2', (string) $this->get_redirect() );
	}

	public function test_step2_missing_capability_dies(): void {
		$_POST = array( 'wizard_step' => '2', 'provider_id' => 'smtp' );
		// No capability granted.

		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	public function test_step2_invalid_nonce_dies(): void {
		$this->post_step( 2, array( 'provider_id' => 'smtp' ) );
		$GLOBALS['_test_wp_nonce_valid'] = false;

		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	// -------------------------------------------------------------------------
	// Step 3 — SMTP configuration
	// -------------------------------------------------------------------------

	/**
	 * Returns a valid SMTP POST array for step 3 tests.
	 */
	private function valid_smtp_post(): array {
		return array(
			'host'       => 'smtp.example.com',
			'port'       => '587',
			'encryption' => 'tls',
			'username'   => 'user@example.com',
			'password'   => 'secret123',
			'from_name'  => 'Test Sender',
			'from_email' => 'from@example.com',
		);
	}

	public function test_step3_valid_config_is_saved_and_redirects_to_step4(): void {
		$this->post_step( 3, array( 'smtp' => $this->valid_smtp_post() ) );
		$this->provider->validation_result = new ValidationResult( true );

		$this->run_handle();

		$smtp = ( new SettingsRepository() )->get_smtp_config();
		$this->assertSame( 'smtp.example.com', $smtp['host'] );
		$this->assertStringContainsString( 'step=4', (string) $this->get_redirect() );
	}

	public function test_step3_invalid_config_stores_error_keys_and_redirects_to_step3(): void {
		$post = array_merge( $this->valid_smtp_post(), array( 'host' => '' ) );
		$this->post_step( 3, array( 'smtp' => $post ) );
		$this->provider->validation_result = new ValidationResult( false, array( 'host' => 'SMTP host is required.' ) );

		$this->run_handle();

		$this->assertStringContainsString( 'step=3', (string) $this->get_redirect() );
		$errors = get_transient( 'scalyn_wizard_step3_errors_1' );
		$this->assertIsArray( $errors );
		$this->assertContains( 'host', $errors );
	}

	public function test_step3_validation_errors_do_not_contain_credential_values(): void {
		$post = array_merge( $this->valid_smtp_post(), array( 'password' => '' ) );
		$this->post_step( 3, array( 'smtp' => $post ) );
		$this->provider->validation_result = new ValidationResult( false, array( 'password' => 'Password required.' ) );

		$this->run_handle();

		$errors = get_transient( 'scalyn_wizard_step3_errors_1' );
		// Only keys stored, never the error message values or config values.
		$this->assertSame( array( 'password' ), $errors );
	}

	public function test_step3_blank_password_uses_stored_password_for_validation(): void {
		// Pre-store a password.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array( 'password' => 'stored-secret' ),
		);

		$smtp_post             = $this->valid_smtp_post();
		$smtp_post['password'] = ''; // Blank submitted password.
		$this->post_step( 3, array( 'smtp' => $smtp_post ) );

		$this->run_handle();

		// Validation was called with the stored password substituted, not blank.
		$this->assertSame( 'stored-secret', $this->provider->last_validate_config['password'] );
	}

	public function test_step3_password_not_present_in_stored_validation_error_transient(): void {
		$this->post_step( 3, array( 'smtp' => $this->valid_smtp_post() ) );
		$this->provider->validation_result = new ValidationResult( false, array( 'host' => 'Bad host.' ) );

		$this->run_handle();

		$errors = get_transient( 'scalyn_wizard_step3_errors_1' );
		// Stored transient must be an array of keys only, not an assoc of key => message.
		$this->assertIsArray( $errors );
		foreach ( $errors as $v ) {
			$this->assertIsString( $v );
			$this->assertStringNotContainsStringIgnoringCase( 'secret', $v );
			$this->assertStringNotContainsStringIgnoringCase( 'password', $v );
		}
	}

	public function test_step3_missing_capability_dies(): void {
		$_POST = array(
			'wizard_step' => '3',
			'smtp'        => $this->valid_smtp_post(),
		);
		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	public function test_step3_invalid_nonce_dies(): void {
		$this->post_step( 3, array( 'smtp' => $this->valid_smtp_post() ) );
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	public function test_step3_save_preserves_active_provider_set_by_step2(): void {
		// Pre-store provider.active as written by a prior Step 2 save.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);

		$this->post_step( 3, array( 'smtp' => $this->valid_smtp_post() ) );
		$this->provider->validation_result = new ValidationResult( true );

		$this->run_handle();

		// A fresh repository constructed from the DB must still report 'smtp'.
		$fresh = new SettingsRepository();
		$this->assertSame(
			'smtp',
			$fresh->get_active_provider_id(),
			'Step 3 save must not overwrite provider.active written during Step 2.'
		);
		$this->assertSame( 'smtp.example.com', $fresh->get_smtp_config()['host'],
			'SMTP settings saved in Step 3 must also be readable from a fresh instance.'
		);
	}

	public function test_step3_blank_password_preserves_stored_password_and_provider_active(): void {
		// Pre-store both provider.active and an existing SMTP password.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'password' => 'already-stored-secret' ),
		);

		$smtp_post             = $this->valid_smtp_post();
		$smtp_post['password'] = ''; // Blank — must keep stored password.
		$this->post_step( 3, array( 'smtp' => $smtp_post ) );
		$this->provider->validation_result = new ValidationResult( true );

		$this->run_handle();

		$fresh = new SettingsRepository();
		$this->assertSame(
			'smtp',
			$fresh->get_active_provider_id(),
			'provider.active must survive a Step 3 save with a blank password field.'
		);
		$this->assertSame(
			'already-stored-secret',
			$fresh->get_smtp_config()['password'],
			'Stored password must be preserved when the submitted password field is blank.'
		);
	}

	// -------------------------------------------------------------------------
	// Step 4 — Connection test
	// -------------------------------------------------------------------------

	public function test_step4_calls_test_connection_not_send(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);
		$this->provider->connection_result = new ConnectionResult( true, 'Connected.' );
		$this->post_step( 4 );

		$this->run_handle();

		$this->assertFalse( $this->provider->send_called );
		$result = get_transient( 'scalyn_wizard_conn_1' );
		$this->assertTrue( $result['success'] );
	}

	public function test_step4_success_stored_in_transient(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);
		$this->provider->connection_result = new ConnectionResult( true, 'Successfully connected to the configured SMTP server.' );
		$this->post_step( 4 );

		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_conn_1' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_step4_failure_stored_in_transient(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);
		$this->provider->connection_result = new ConnectionResult( false, 'Unable to connect to the SMTP server.' );
		$this->post_step( 4 );

		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_conn_1' );
		$this->assertFalse( $result['success'] );
	}

	public function test_step4_transient_contains_no_credentials(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'password' => 'super-secret-password' ),
		);
		$this->provider->connection_result = new ConnectionResult( true, 'OK.' );
		$this->post_step( 4 );

		$this->run_handle();

		$result  = get_transient( 'scalyn_wizard_conn_1' );
		$encoded = json_encode( $result );
		$this->assertStringNotContainsString( 'super-secret-password', (string) $encoded );
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertArrayNotHasKey( 'config', $result );
	}

	public function test_step4_redirects_to_step4(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);
		$this->post_step( 4 );

		$this->run_handle();

		$this->assertStringContainsString( 'step=4', (string) $this->get_redirect() );
	}

	public function test_step4_no_provider_configured_stores_failure(): void {
		// No active provider in settings.
		$this->post_step( 4 );

		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_conn_1' );
		$this->assertFalse( $result['success'] );
	}

	public function test_step4_missing_capability_dies(): void {
		$_POST = array( 'wizard_step' => '4' );
		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	public function test_step4_invalid_nonce_dies(): void {
		$this->post_step( 4 );
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	// -------------------------------------------------------------------------
	// Step 5 — Test email
	// -------------------------------------------------------------------------

	public function test_step5_invalid_recipient_stores_failure_and_redirects(): void {
		$this->post_step( 5, array( 'test_recipient' => 'not-an-email' ) );

		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_email_1' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'step=5', (string) $this->get_redirect() );
	}

	public function test_step5_dispatches_via_mail_dispatcher(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'from_email' => 'from@example.com', 'from_name' => 'Test' ),
		);
		$this->provider->send_result = new SendResult( true, 'smtp', null, null, 'Message accepted by the configured SMTP server.', false );

		$this->post_step( 5, array( 'test_recipient' => 'recipient@example.com' ) );

		$this->run_handle();

		$this->assertTrue( $this->provider->send_called );
		$this->assertNotNull( $this->provider->last_sent_message );
		$this->assertInstanceOf( MailMessage::class, $this->provider->last_sent_message );
		$this->assertSame( array( 'recipient@example.com' ), $this->provider->last_sent_message->to );
	}

	public function test_step5_success_message_does_not_contain_delivered(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'from_email' => 'from@example.com' ),
		);
		$this->provider->send_result = new SendResult( true, 'smtp', null, null, 'Message accepted by the configured SMTP server.', false );

		$this->post_step( 5, array( 'test_recipient' => 'recipient@example.com' ) );
		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_email_1' );
		$this->assertStringNotContainsStringIgnoringCase( 'delivered', $result['message'] );
	}

	public function test_step5_failure_result_is_normalized(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'from_email' => 'from@example.com' ),
		);
		$this->provider->send_result = new SendResult( false, 'smtp', null, null, 'SMTP transport failed.', false, 'network' );

		$this->post_step( 5, array( 'test_recipient' => 'recipient@example.com' ) );
		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_email_1' );
		$this->assertFalse( $result['success'] );
		// Must not contain raw credentials or PHPMailer internals.
		$this->assertStringNotContainsStringIgnoringCase( 'password', $result['message'] );
	}

	public function test_step5_mail_message_constructed_with_valid_fields(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array(
				'from_email' => 'from@example.com',
				'from_name'  => 'Sender Name',
			),
		);
		$this->provider->send_result = new SendResult( true, 'smtp' );

		$this->post_step( 5, array( 'test_recipient' => 'to@example.com' ) );
		$this->run_handle();

		$msg = $this->provider->last_sent_message;
		$this->assertNotNull( $msg );
		$this->assertNotEmpty( $msg->uuid );
		$this->assertStringContainsString( 'from@example.com', $msg->from );
		$this->assertSame( array( 'to@example.com' ), $msg->to );
		$this->assertNotEmpty( $msg->subject );
		$this->assertSame( 'text/html', $msg->content_type );
	}

	public function test_step5_redirects_to_step5(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'from_email' => 'from@example.com' ),
		);
		$this->provider->send_result = new SendResult( true, 'smtp' );

		$this->post_step( 5, array( 'test_recipient' => 'to@example.com' ) );
		$this->run_handle();

		$this->assertStringContainsString( 'step=5', (string) $this->get_redirect() );
	}

	public function test_step5_missing_capability_dies(): void {
		$_POST = array(
			'wizard_step'    => '5',
			'test_recipient' => 'to@example.com',
		);
		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	public function test_step5_invalid_nonce_dies(): void {
		$this->post_step( 5, array( 'test_recipient' => 'to@example.com' ) );
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$this->expectException( RuntimeException::class );
		( new WizardController() )->handle();
	}

	public function test_step5_success_result_stored_in_transient(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array( 'from_email' => 'from@example.com' ),
		);
		$this->provider->send_result = new SendResult( true, 'smtp' );

		$this->post_step( 5, array( 'test_recipient' => 'to@example.com' ) );
		$this->run_handle();

		$result = get_transient( 'scalyn_wizard_email_1' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['message'] );
	}
}
