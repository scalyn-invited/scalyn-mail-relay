<?php
/**
 * Tests for the inactive-Gmail configure path on the Providers page.
 *
 * Verifies:
 *   1. "Configure Gmail" button appears for inactive Gmail.
 *   2. "Select in wizard" is still present alongside it.
 *   3. GmailSettingsForm is rendered inline when ?configure=gmail and Gmail is inactive.
 *   4. Inline form is NOT shown when Gmail is the active provider.
 *   5. Saving sender settings preserves the active provider.
 *   6. Saving client credentials preserves the active provider before the OAuth redirect.
 *
 * Tests 1–4 render ProvidersPage via ob_start, mirroring the ProvidersPageTest pattern.
 * Tests 5–6 drive GmailSettingsForm::handle() directly, without an HTTP round-trip.
 *
 * @package ScalynMailRelay
 */

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Components\GmailSettingsForm;
use Scalyn\MailRelay\Admin\Pages\ProvidersPage;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

final class GmailProvidersConfigureTest extends TestCase {

	private CredentialCipher $cipher;

	// Synthetic-only credentials that never interact with real Google APIs.
	private const CLIENT_ID     = '1234567890-configtest.apps.googleusercontent.com';
	private const CLIENT_SECRET = 'GOCSPx-providers_configure_test_ok';

	protected function setUp(): void {
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );

		$GLOBALS['_test_wp_options']             = array();
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_wp_actions']             = array();
		$GLOBALS['_test_wp_added_actions']       = array();
		$GLOBALS['_test_wp_transients']          = array();
		$GLOBALS['_test_current_user_id']        = 1;
		$GLOBALS['_test_wp_nonce_valid']         = true;
		$GLOBALS['_test_wp_redirect']            = null;
		$GLOBALS['_test_current_user_can']       = array(
			Capabilities::MANAGE_MAIL     => true,
			Capabilities::MANAGE_SETTINGS => true,
		);
		$GLOBALS['wpdb']                         = new WpdbStub();

		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->cipher = new CredentialCipher( base64_encode( str_repeat( 'p', 32 ) ) );
	}

	protected function tearDown(): void {
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Boots the plugin, registers a minimal set of providers including a Gmail stub,
	 * and renders ProvidersPage, returning the captured HTML.
	 *
	 * @param string $active_provider_id  Provider to set as active in settings.
	 * @param array  $gmail_options       Extra options to merge into wp_options before render.
	 */
	private function render_page( string $active_provider_id = 'brevo', array $extra_options = array() ): string {
		// Set options BEFORE boot so SettingsRepository reads them on first access.
		if ( '' !== $active_provider_id ) {
			$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array_merge(
				array( 'provider' => array( 'active' => $active_provider_id ) ),
				$extra_options
			);
		}

		Plugin::instance()->boot();

		$container = Plugin::instance()->container();
		$container->set( CredentialCipher::class, $this->cipher );

		// Register stub providers including Gmail.
		$registry = $container->get( ProviderRegistry::class );
		foreach ( array( 'smtp', 'brevo', 'sendgrid' ) as $id ) {
			$stub = $this->make_stub_provider( $id );
			$registry->register( $stub );
		}
		$registry->register( $this->make_stub_provider( 'gmail', 'Gmail / Google Workspace' ) );

		ob_start();
		try {
			( new ProvidersPage() )->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** Returns a minimal provider stub with the given ID and optional label. */
	private function make_stub_provider( string $id, string $label = '' ): ProviderInterface {
		return new class( $id, $label ?: strtoupper( $id ) ) implements ProviderInterface {
			public function __construct( private string $id, private string $label ) {}
			public function get_id(): string { return $this->id; }
			public function get_label(): string { return $this->label; }
			public function get_capabilities(): array { return array(); }
			public function validate_config( array $c ): ValidationResult { return new ValidationResult( true ); }
			public function test_connection( array $c ): ConnectionResult { return new ConnectionResult( true, 'OK' ); }
			public function send( MailMessage $m, array $c ): SendResult { return new SendResult( true, $this->id ); }
		};
	}

	// -------------------------------------------------------------------------
	// Tests 1 & 2 — Action buttons for inactive Gmail
	// -------------------------------------------------------------------------

	/**
	 * When Gmail is registered but not the active provider, the Providers page
	 * must show a "Configure Gmail" button linking to ?configure=gmail.
	 */
	public function test_configure_gmail_button_appears_for_inactive_gmail(): void {
		$html = $this->render_page( 'brevo' );

		$this->assertStringContainsString( 'Configure Gmail', $html );
		$this->assertStringContainsString( 'configure=gmail', $html );
	}

	/**
	 * "Select in wizard" must remain alongside "Configure Gmail" so the user
	 * can still switch providers through the wizard if they prefer.
	 */
	public function test_select_in_wizard_still_present_for_inactive_gmail(): void {
		$html = $this->render_page( 'brevo' );

		// Gmail's "Select in wizard" link must carry preselect_provider=gmail.
		$this->assertStringContainsString( 'preselect_provider=gmail', $html );
		$this->assertStringContainsString( 'Select in wizard', $html );
	}

	// -------------------------------------------------------------------------
	// Tests 3 & 4 — Inline configure form visibility
	// -------------------------------------------------------------------------

	/**
	 * When ?configure=gmail is set and Gmail is inactive, the providers page must
	 * embed the GmailSettingsForm directly inside the Gmail card.
	 */
	public function test_configure_form_shown_when_configure_param_set_and_gmail_inactive(): void {
		$_GET = array( 'configure' => 'gmail' );

		$html = $this->render_page( 'brevo' );

		// Client credentials form is present (uses scalyn_gmail_client action).
		$this->assertStringContainsString( 'scalyn_gmail_client', $html );
		$this->assertStringContainsString( 'gmail-client-id', $html );
		// Setup instructions are present.
		$this->assertStringContainsString( 'Google Cloud Console setup instructions', $html );
	}

	/**
	 * When ?configure=gmail is set but Gmail IS the active provider, the inline
	 * configure panel must NOT be rendered — the active-provider path uses the wizard.
	 */
	public function test_configure_form_not_shown_when_gmail_is_active(): void {
		$_GET = array( 'configure' => 'gmail' );

		$html = $this->render_page( 'gmail' );

		// Gmail is active so the providers page shows "Configure in wizard", not the inline form.
		$this->assertStringContainsString( 'Configure in wizard', $html );
		// The inline client form must NOT be embedded (it would only appear for inactive Gmail).
		$this->assertStringNotContainsString( 'scalyn_gmail_client', $html );
	}

	// -------------------------------------------------------------------------
	// Tests 5 & 6 — Active provider preserved through form operations
	// -------------------------------------------------------------------------

	/**
	 * Saving Gmail sender settings on the Providers page must leave the active
	 * provider unchanged — only gmail.from_email and gmail.from_name are written.
	 */
	public function test_sender_save_does_not_change_active_provider(): void {
		// Arrange: brevo is the active provider; Gmail has no credentials yet.
		$settings = new SettingsRepository( $this->cipher );
		$settings->save( array( 'provider' => array( 'active' => 'brevo' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array(
			'scalyn_gmail_sender' => '1',
			'gmail_from_email'    => 'sender@example.com',
			'gmail_from_name'     => 'Test Sender',
		);

		$form = new GmailSettingsForm( $settings, $this->cipher );
		$form->handle();

		// Active provider must still be brevo.
		$this->assertSame( 'brevo', ( new SettingsRepository() )->get_active_provider_id() );

		// Sender must have been saved.
		$gmail = ( new SettingsRepository() )->get_gmail_settings();
		$this->assertSame( 'sender@example.com', $gmail['from_email'] );
		$this->assertSame( 'Test Sender', $gmail['from_name'] );
	}

	/**
	 * Saving Gmail client credentials on the Providers page triggers an OAuth
	 * redirect, but the active provider must not have changed before the redirect.
	 */
	public function test_client_save_does_not_change_active_provider_before_oauth_redirect(): void {
		// Arrange: brevo is the active provider.
		$settings = new SettingsRepository( $this->cipher );
		$settings->save( array( 'provider' => array( 'active' => 'brevo' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array(
			'scalyn_gmail_client' => '1',
			'gmail_client_id'     => self::CLIENT_ID,
			'gmail_client_secret' => self::CLIENT_SECRET,
		);

		$form = new GmailSettingsForm( $settings, $this->cipher );
		try {
			$form->handle();
			$this->fail( 'Expected WpRedirectException for OAuth initiation.' );
		} catch ( WpRedirectException $e ) {
			// OAuth redirect fired as expected.
		}

		// Active provider must still be brevo despite the credential save.
		$this->assertSame( 'brevo', ( new SettingsRepository() )->get_active_provider_id() );

		// Client credentials must have been encrypted and stored.
		$gmail = ( new SettingsRepository( $this->cipher ) )->get_gmail_settings();
		$this->assertSame( self::CLIENT_ID, $gmail['client_id'] );
		$this->assertTrue( $gmail['has_secret'] );
	}
}
