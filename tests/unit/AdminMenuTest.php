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
}
