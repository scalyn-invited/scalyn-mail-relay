<?php
/**
 * Admin menu registration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Admin\Pages\DashboardPage;
use Scalyn\MailRelay\Admin\Pages\DiagnosticsPage;
use Scalyn\MailRelay\Admin\Pages\LogsPage;
use Scalyn\MailRelay\Admin\Pages\ProvidersPage;
use Scalyn\MailRelay\Admin\Pages\WizardPage;
use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Scalyn Mail Relay admin menu structure and enqueues admin assets.
 *
 * Sub-menu pages delegate capability checking and rendering to dedicated
 * page classes in admin/pages/. Each page class owns its own capability
 * gate so access control is co-located with the render logic.
 *
 * Ownership: Kim / Admin.
 */
final class AdminMenu {

	/**
	 * Hooks the admin menu and asset enqueueing actions into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the top-level admin menu and all sub-menu pages.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Mail Relay', 'scalyn-mail-relay' ),
			Capabilities::VIEW_DASHBOARD,
			'scalyn-mail-relay',
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt2',
			56
		);

		// Rename the auto-generated first sub-menu item from "Mail Relay" to "Dashboard".
		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Dashboard — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Dashboard', 'scalyn-mail-relay' ),
			Capabilities::VIEW_DASHBOARD,
			'scalyn-mail-relay',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Setup Wizard — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Setup Wizard', 'scalyn-mail-relay' ),
			Capabilities::MANAGE_SETTINGS,
			'scalyn-mail-relay-wizard',
			array( $this, 'render_wizard' )
		);

		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Providers — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Providers', 'scalyn-mail-relay' ),
			Capabilities::MANAGE_MAIL,
			'scalyn-mail-relay-providers',
			array( $this, 'render_providers' )
		);

		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Email Logs — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Email Logs', 'scalyn-mail-relay' ),
			Capabilities::VIEW_LOGS,
			'scalyn-mail-relay-logs',
			array( $this, 'render_logs' )
		);

		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Diagnostics — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Diagnostics', 'scalyn-mail-relay' ),
			Capabilities::RUN_DIAGNOSTICS,
			'scalyn-mail-relay-diagnostics',
			array( $this, 'render_diagnostics' )
		);
	}

	/**
	 * Enqueues admin CSS and JS on Scalyn Mail Relay pages.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'scalyn-mail-relay' ) ) {
			return;
		}

		wp_enqueue_style( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/css/admin.css', array(), SCALYN_MAIL_RELAY_VERSION );
		wp_enqueue_script( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/js/admin.js', array(), SCALYN_MAIL_RELAY_VERSION, true );
	}

	/**
	 * Renders the dashboard page.
	 */
	public function render_dashboard(): void {
		( new DashboardPage() )->render();
	}

	/**
	 * Renders the setup wizard page.
	 */
	public function render_wizard(): void {
		( new WizardPage() )->render();
	}

	/**
	 * Renders the providers page.
	 */
	public function render_providers(): void {
		( new ProvidersPage() )->render();
	}

	/**
	 * Renders the email logs page.
	 */
	public function render_logs(): void {
		( new LogsPage() )->render();
	}

	/**
	 * Renders the diagnostics page.
	 */
	public function render_diagnostics(): void {
		( new DiagnosticsPage() )->render();
	}
}
