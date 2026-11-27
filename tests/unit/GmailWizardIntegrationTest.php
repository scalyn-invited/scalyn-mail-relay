<?php
/**
 * Integration tests for Gmail paths in WizardController and WizardPage.
 *
 * Tests:
 *   1. Gmail selection with named configurations present → goes to step 3, NOT configurations
 *   2. SMTP with 0 named config matches → redirected to configurations (control: routing still works for SMTP)
 *   3. Gmail in wizard step 5 uses from_email without name wrapper
 *   4. WizardPage::get_first_incomplete_step() clamps to 3 when Gmail connected=false
 *   5. WizardPage::get_first_incomplete_step() advances past 3 when Gmail connected + from_email set
 *
 * @package ScalynMailRelay
 */

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\WizardController;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;
use Scalyn\MailRelay\Contracts\ProviderInterface;

/**
 * Stub provider for GmailWizardIntegrationTest.
 */
final class GmailWizardTestProvider implements ProviderInterface {
	public string $id    = 'gmail';
	public string $label = 'Gmail / Google Workspace';
	public ?ValidationResult $validation_result = null;
	public ?ConnectionResult $connection_result = null;
	public ?SendResult $send_result             = null;
	public ?MailMessage $last_sent_message      = null;
	public bool $send_called                    = false;

	public function get_id(): string { return $this->id; }
	public function get_label(): string { return $this->label; }
	public function get_capabilities(): array { return array( 'html' ); }

	public function validate_config( array $config ): ValidationResult {
		return $this->validation_result ?? new ValidationResult( true );
	}

	public function test_connection( array $config ): ConnectionResult {
		return $this->connection_result ?? new ConnectionResult( true, 'Gmail token accepted.' );
	}

	public function send( MailMessage $message, array $config ): SendResult {
		$this->send_called       = true;
		$this->last_sent_message = $message;
		return $this->send_result ?? new SendResult( true, 'gmail', 'fakeid123', '200', 'Message accepted by the Gmail API; delivery is unconfirmed.' );
	}
}

final class GmailWizardIntegrationTest extends TestCase {

	private GmailWizardTestProvider $provider;
	private ProviderRegistry $registry;
	private CredentialCipher $cipher;

	protected function setUp(): void {
		// Reset Plugin singleton so each test gets a fresh Container.
		$reflection = new ReflectionClass( Plugin::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setValue( null, null );

		// Reset all test globals.
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$GLOBALS['_test_wp_transients']    = array();
		$GLOBALS['_test_current_user_id']  = 1;
		$GLOBALS['_test_wp_nonce_valid']   = true;
		$GLOBALS['_test_wp_redirect']      = null;
		$GLOBALS['_test_current_user_can'] = array();

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Build stub provider and registry.
		$this->provider = new GmailWizardTestProvider();
		$this->registry = new ProviderRegistry();
		$this->registry->register( $this->provider );

		// Also register SMTP so it's always a valid choice.
		$smtp_stub     = new class implements ProviderInterface {
			public function get_id(): string { return 'smtp'; }
			public function get_label(): string { return 'SMTP'; }
			public function get_capabilities(): array { return array(); }
			public function validate_config( array $c ): ValidationResult { return new ValidationResult( true ); }
			public function test_connection( array $c ): ConnectionResult { return new ConnectionResult( true, 'OK' ); }
			public function send( MailMessage $m, array $c ): SendResult { return new SendResult( true, 'smtp' ); }
		};
		$this->registry->register( $smtp_stub );

		$this->cipher  = new CredentialCipher( base64_encode( str_repeat( 'w', 32 ) ) );

		$container      = Plugin::instance()->container();
		$registry_ref   = $this->registry;
		$cipher_ref     = $this->cipher;
		$container->set( ProviderRegistry::class, $this->registry );
		$container->set( \Scalyn\MailRelay\Core\CredentialCipher::class, $this->cipher );
		$container->set( SettingsRepository::class, static fn() => new SettingsRepository( $cipher_ref ) );
		$container->set(
			\Scalyn\MailRelay\Core\ConnectionVerification::class,
			static fn( $c ) => new \Scalyn\MailRelay\Core\ConnectionVerification(
				$c->get( SettingsRepository::class ),
				$registry_ref,
				new \Scalyn\MailRelay\Database\ConnectionEvidenceRepository()
			)
		);
		$container->set(
			MailDispatcher::class,
			static fn() => new MailDispatcher( $registry_ref, new SettingsRepository( $cipher_ref ) )
		);
	}

	protected function tearDown(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function post_step( int $step, array $extra = array() ): void {
		$_POST = array_merge( array( 'wizard_step' => (string) $step ), $extra );
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;
	}

	private function get_redirect(): ?string {
		return $GLOBALS['_test_wp_redirect'];
	}

	private function run_handle(): void {
		try {
			( new WizardController() )->handle();
		} catch ( WpRedirectException $e ) {
			// Expected — redirect occurred.
		}
	}

	// -------------------------------------------------------------------------
	// Test 1: Gmail bypasses named-configuration routing
	// -------------------------------------------------------------------------

	/**
	 * When named configurations exist for SMTP/etc., selecting Gmail must
	 * go directly to step 3, not to the configurations view.
	 *
	 * This is the routing fix from Step 4: NamedConfigurations::PROVIDERS
	 * does not include 'gmail', so the routing block must be skipped.
	 */
	public function test_gmail_selection_with_named_configurations_routes_to_step3(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		// Seed a named configuration for SMTP (gmail is NOT in PROVIDERS list).
		$settings = new SettingsRepository( $this->cipher );
		$settings->configure( 'create', 'smtp', 'My SMTP Config' );

		// Now select Gmail.
		$this->post_step( 2, array( 'provider_id' => 'gmail' ) );
		$this->run_handle();

		// Must redirect to step 3, not to configurations view.
		$redirect = (string) $this->get_redirect();
		$this->assertStringContainsString( 'step=3', $redirect );
		$this->assertStringNotContainsString( 'view=configurations', $redirect );
	}

	// -------------------------------------------------------------------------
	// Test 2 (control): SMTP with 0 exact matches still routes to configurations
	// -------------------------------------------------------------------------

	/**
	 * Control test: SMTP IS in NamedConfigurations::PROVIDERS.
	 * When named configurations exist and SMTP is not currently active, with 0
	 * exact matches for the submitted provider, routing must go to configurations.
	 */
	public function test_smtp_with_no_matching_config_routes_to_configurations(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		// Seed a named configuration for sendgrid (not smtp), so smtp has 0 matches.
		// We need a provider for sendgrid in the registry.
		$sendgrid_stub = new class implements ProviderInterface {
			public function get_id(): string { return 'sendgrid'; }
			public function get_label(): string { return 'SendGrid'; }
			public function get_capabilities(): array { return array(); }
			public function validate_config( array $c ): ValidationResult { return new ValidationResult( true ); }
			public function test_connection( array $c ): ConnectionResult { return new ConnectionResult( true, 'OK' ); }
			public function send( MailMessage $m, array $c ): SendResult { return new SendResult( true, 'sendgrid' ); }
		};
		$this->registry->register( $sendgrid_stub );

		$settings = new SettingsRepository( $this->cipher );
		$settings->configure( 'create', 'sendgrid', 'My SG Config' );
		// Active provider is empty; selecting smtp has 0 named config matches for smtp.

		$this->post_step( 2, array( 'provider_id' => 'smtp' ) );
		$this->run_handle();

		$redirect = (string) $this->get_redirect();
		$this->assertStringContainsString( 'view=configurations', $redirect );
		$this->assertStringContainsString( 'choose_provider=smtp', $redirect );
	}

	// -------------------------------------------------------------------------
	// Test 3: Gmail step 5 uses from_email without name wrapper
	// -------------------------------------------------------------------------

	/**
	 * WizardController::handle_step5() must use $from_email directly (not
	 * "Name <email>") when the active provider is Gmail. This matches the
	 * Gmail API expectation: the RFC 2822 From header is built in GmailProvider.
	 */
	public function test_gmail_step5_uses_bare_from_email(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		// Seed Gmail as the active provider with encrypted credentials and from_email set.
		// get_gmail_config() requires access_cipher, refresh_cipher and secret_cipher to be present.
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client(
			'1234567890-step5test.apps.googleusercontent.com',
			'GOCSPx-step5_test_secret_ok',
			$this->cipher
		);
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_tokens(
			'GOCSPx-step5_test_secret_ok',
			'ya29.step5_access_token',
			'step5_refresh_token',
			time() + 3600,
			$this->cipher
		);
		$settings->save_gmail_sender( 'sender@example.com', 'Test Sender' );
		$settings->save( array( 'provider' => array( 'active' => 'gmail', 'verified' => true ) ) );

		$this->provider->send_result = new SendResult( true, 'gmail', 'fakeid', '200', 'Accepted.' );

		$this->post_step( 5, array( 'test_recipient' => 'recipient@example.com' ) );
		$this->run_handle();

		$this->assertTrue( $this->provider->send_called, 'Provider send() must have been called.' );
		$this->assertNotNull( $this->provider->last_sent_message );
		// From must be the bare email address, NOT "Test Sender <sender@example.com>".
		$this->assertSame( 'sender@example.com', $this->provider->last_sent_message->from );
		$this->assertStringNotContainsString( 'Test Sender <', $this->provider->last_sent_message->from );
	}

	// -------------------------------------------------------------------------
	// Test 4: WizardPage clamps to step 3 when Gmail not connected
	// -------------------------------------------------------------------------

	/**
	 * When Gmail is the active provider but not connected, the wizard must
	 * clamp to step 3 (configuration), preventing skipping ahead.
	 */
	public function test_wizard_page_clamps_to_step3_when_gmail_not_connected(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		// Gmail is active but not connected (no access_cipher or refresh_cipher).
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'gmail' ),
		);

		$_GET = array( 'step' => '5' ); // Try to jump to step 5.
		ob_start();
		try {
			( new \Scalyn\MailRelay\Admin\Pages\WizardPage() )->render();
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = array();
		}

		// Step 3 content must be rendered (the Gmail configuration form).
		$this->assertStringContainsString( 'Configure Gmail', $html );
		// The step 5 form input must NOT appear — the page is clamped to step 3.
		$this->assertStringNotContainsString( 'name="test_recipient"', $html );
	}

	// -------------------------------------------------------------------------
	// Test 5: WizardPage advances past step 3 when Gmail connected + from_email
	// -------------------------------------------------------------------------

	/**
	 * When Gmail is connected AND from_email is set, the first incomplete step
	 * must be step 4 (connection verification), not step 3.
	 */
	public function test_wizard_page_advances_past_step3_when_gmail_fully_configured(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		// Seed Gmail as fully connected with from_email set.
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client(
			'1234567890-test.apps.googleusercontent.com',
			'GOCSPx-test_client_secret_ok',
			$this->cipher
		);
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_tokens(
			'GOCSPx-test_client_secret_ok',
			'ya29.synthetic_access_token',
			'synthetic_refresh_token',
			time() + 3600,
			$this->cipher
		);
		$settings->save_gmail_sender( 'sender@example.com', 'Test Sender' );
		$settings->save( array( 'provider' => array( 'active' => 'gmail' ) ) );
		// Provider is NOT yet verified.

		$_GET = array( 'step' => '7' ); // Try to jump to the end.
		ob_start();
		try {
			( new \Scalyn\MailRelay\Admin\Pages\WizardPage() )->render();
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = array();
		}

		// Step 4 (Verify Connection) must be rendered, not step 3 or 7.
		$this->assertStringContainsString( 'Verify Connection', $html );
		$this->assertStringNotContainsString( 'Provider Configuration Complete', $html );
	}

	// -------------------------------------------------------------------------
	// Test 6: Gmail active provider is preserved after token save
	// -------------------------------------------------------------------------

	/**
	 * Saving Gmail tokens must not change the active provider.
	 * This guards against a regression where get_active_provider_id() could
	 * be corrupted after a token save.
	 */
	public function test_smtp_remains_active_after_gmail_tokens_saved(): void {
		// Arrange: SMTP is the active provider.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp', 'verified' => true ),
		);
		$cipher   = new CredentialCipher( base64_encode( str_repeat( 'k', 32 ) ) );
		$settings = new SettingsRepository( $cipher );
		$settings->save_gmail_client( '1234567890-syn.apps.googleusercontent.com', 'GOCSPx-synth_secret_ok', $cipher );
		$settings = new SettingsRepository( $cipher );
		$settings->save_gmail_tokens( 'GOCSPx-synth_secret_ok', 'ya29.access', 'refresh_tok', time() + 3600, $cipher );

		// Active provider must still be smtp.
		$this->assertSame( 'smtp', $settings->get_active_provider_id() );
	}
}
