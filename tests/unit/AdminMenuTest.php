<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\AdminMenu;
use Scalyn\MailRelay\Admin\Pages\DashboardPage;
use Scalyn\MailRelay\Admin\Pages\DiagnosticsPage;
use Scalyn\MailRelay\Admin\Pages\LogsPage;
use Scalyn\MailRelay\Admin\Pages\ProvidersPage;
use Scalyn\MailRelay\Admin\Pages\WizardPage;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;

/**
 * Tests for the admin page capability gates.
 *
 * Each page class must reject unauthorized users via wp_die() before
 * any rendering begins. These tests verify the capability each page
 * requires and that wrong or absent capabilities are correctly rejected.
 */
final class AdminMenuTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = array();
		$GLOBALS['_test_wp_redirect']      = null;
		$_SERVER['REQUEST_METHOD']         = 'GET';
		$GLOBALS['wpdb']                   = new WpdbStub();
	}

	protected function tearDown(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	// -------------------------------------------------------------------------
	// Capability constant values
	// -------------------------------------------------------------------------

	public function test_view_dashboard_capability_value(): void {
		$this->assertSame( 'scalyn_mail_relay_view_dashboard', Capabilities::VIEW_DASHBOARD );
	}

	public function test_view_logs_capability_value(): void {
		$this->assertSame( 'scalyn_mail_relay_view_logs', Capabilities::VIEW_LOGS );
	}

	public function test_run_diagnostics_capability_value(): void {
		$this->assertSame( 'scalyn_mail_relay_run_diagnostics', Capabilities::RUN_DIAGNOSTICS );
	}

	public function test_manage_mail_capability_value(): void {
		$this->assertSame( 'scalyn_mail_relay_manage_mail', Capabilities::MANAGE_MAIL );
	}

	public function test_manage_settings_capability_value(): void {
		$this->assertSame( 'scalyn_mail_relay_manage_settings', Capabilities::MANAGE_SETTINGS );
	}

	// -------------------------------------------------------------------------
	// Unauthorized user rejection (no capabilities granted)
	// -------------------------------------------------------------------------

	public function test_dashboard_page_rejects_user_without_view_dashboard(): void {
		$this->expectException( RuntimeException::class );
		( new DashboardPage() )->render();
	}

	public function test_wizard_page_rejects_user_without_manage_settings(): void {
		$this->expectException( RuntimeException::class );
		( new WizardPage() )->render();
	}

	public function test_providers_page_rejects_user_without_manage_mail(): void {
		$this->expectException( RuntimeException::class );
		( new ProvidersPage() )->render();
	}

	public function test_logs_page_rejects_user_without_view_logs(): void {
		$this->expectException( RuntimeException::class );
		( new LogsPage( new MailLogRepository(), new TimelineRepository() ) )->render();
	}

	public function test_diagnostics_page_rejects_user_without_run_diagnostics(): void {
		$this->expectException( RuntimeException::class );
		( new DiagnosticsPage() )->render();
	}

	// -------------------------------------------------------------------------
	// Wrong-capability rejection (correct capability is absent)
	// -------------------------------------------------------------------------

	public function test_dashboard_page_rejects_user_with_only_manage_settings(): void {
		// Granting manage_settings must not unlock view_dashboard.
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;
		$this->expectException( RuntimeException::class );
		( new DashboardPage() )->render();
	}

	public function test_wizard_page_rejects_user_with_only_view_dashboard(): void {
		// Granting view_dashboard must not unlock manage_settings.
		$GLOBALS['_test_current_user_can'][ Capabilities::VIEW_DASHBOARD ] = true;
		$this->expectException( RuntimeException::class );
		( new WizardPage() )->render();
	}

	public function test_providers_page_rejects_user_with_only_view_dashboard(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::VIEW_DASHBOARD ] = true;
		$this->expectException( RuntimeException::class );
		( new ProvidersPage() )->render();
	}

	public function test_logs_page_rejects_user_with_only_manage_settings(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;
		$this->expectException( RuntimeException::class );
		( new LogsPage( new MailLogRepository(), new TimelineRepository() ) )->render();
	}

	public function test_diagnostics_page_rejects_user_with_only_view_dashboard(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::VIEW_DASHBOARD ] = true;
		$this->expectException( RuntimeException::class );
		( new DiagnosticsPage() )->render();
	}

	// -------------------------------------------------------------------------
	// handle_wizard_post() — load-hook POST gate
	// -------------------------------------------------------------------------

	/**
	 * Regression guard: handle_wizard_post() must not redirect or throw on GET.
	 *
	 * When WordPress fires the load-{hook} action on a normal GET page load,
	 * handle_wizard_post() must return silently so render_wizard() can proceed.
	 */
	public function test_handle_wizard_post_does_nothing_on_get(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		( new AdminMenu() )->handle_wizard_post();

		$this->assertNull(
			$GLOBALS['_test_wp_redirect'],
			'handle_wizard_post() must not redirect when the request method is GET.'
		);
	}

	// -------------------------------------------------------------------------
	// enqueue_assets() — admin script loading
	// -------------------------------------------------------------------------

	private function reset_enqueue_globals(): void {
		$GLOBALS['_test_wp_enqueued_styles']   = array();
		$GLOBALS['_test_wp_enqueued_scripts']  = array();
		$GLOBALS['_test_wp_localized_scripts'] = array();
	}

	public function test_enqueue_assets_ignores_non_plugin_screens(): void {
		$this->reset_enqueue_globals();

		( new AdminMenu() )->enqueue_assets( 'index.php' );

		$this->assertSame( array(), $GLOBALS['_test_wp_enqueued_styles'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_enqueued_scripts'] );
	}

	/**
	 * Regression guard: the Diagnostics screen is a sub-menu page, so WordPress
	 * passes "mail-relay_page_scalyn-mail-relay-diagnostics" as its hook suffix
	 * (not "toplevel_page_..."). The admin script must load there so the
	 * Run Diagnostics button POSTs to the REST endpoint instead of navigating
	 * to it via GET.
	 */
	public function test_enqueue_assets_loads_admin_script_on_diagnostics_submenu_hook(): void {
		$this->reset_enqueue_globals();

		( new AdminMenu() )->enqueue_assets( 'mail-relay_page_scalyn-mail-relay-diagnostics' );

		$this->assertArrayHasKey( 'scalyn-mail-relay-admin', $GLOBALS['_test_wp_enqueued_styles'] );
		$this->assertArrayHasKey( 'scalyn-mail-relay-admin', $GLOBALS['_test_wp_enqueued_scripts'] );
		$this->assertStringEndsWith( 'assets/js/admin.js', $GLOBALS['_test_wp_enqueued_scripts']['scalyn-mail-relay-admin'] );

		$settings = $GLOBALS['_test_wp_localized_scripts']['scalyn-mail-relay-admin']['scalynMailRelaySettings'] ?? null;
		$this->assertIsArray( $settings, 'REST nonce and labels must be localized onto the admin script.' );
		$this->assertArrayHasKey( 'restNonce', $settings );
		$this->assertNotSame( '', $settings['restNonce'] );
	}

	/**
	 * The Dashboard renders a Run Diagnostics quick action too, so the script
	 * must also load on the top-level page hook.
	 */
	public function test_enqueue_assets_loads_admin_script_on_dashboard_hook(): void {
		$this->reset_enqueue_globals();

		( new AdminMenu() )->enqueue_assets( 'toplevel_page_scalyn-mail-relay' );

		$this->assertArrayHasKey( 'scalyn-mail-relay-admin', $GLOBALS['_test_wp_enqueued_scripts'] );
		$this->assertArrayHasKey( 'scalyn-mail-relay-admin', $GLOBALS['_test_wp_localized_scripts'] );
	}
}
