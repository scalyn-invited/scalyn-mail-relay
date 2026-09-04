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
use Scalyn\MailRelay\Admin\WizardController;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;

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

		$wizard_hook = add_submenu_page(
			'scalyn-mail-relay',
			__( 'Setup Wizard — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Setup Wizard', 'scalyn-mail-relay' ),
			Capabilities::MANAGE_SETTINGS,
			'scalyn-mail-relay-wizard',
			array( $this, 'render_wizard' )
		);

		// Register POST handling before admin-header.php outputs HTML so that
		// wp_safe_redirect() can still send the Location header. The page
		// callback fires after output has started and headers are already sent.
		if ( $wizard_hook ) {
			add_action( 'load-' . $wizard_hook, array( $this, 'handle_wizard_post' ) );
		}

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

		// Enqueue the admin script on every plugin screen. The script self-gates
		// on the presence of the #scalyn-run-diagnostics button, which is rendered
		// on both the Dashboard (Quick Actions) and the Diagnostics page.
		//
		// Do not gate this on a literal hook suffix: sub-menu pages registered
		// under "Mail Relay" receive hooks such as
		// "mail-relay_page_scalyn-mail-relay-diagnostics" (only the top-level
		// Dashboard is "toplevel_page_scalyn-mail-relay"). A previous
		// "toplevel_page_scalyn-mail-relay-diagnostics" comparison never matched,
		// so the script was never loaded and the Run Diagnostics link navigated
		// to the POST-only REST endpoint via GET, producing a rest_no_route 404.
		wp_enqueue_script( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/js/admin.js', array(), SCALYN_MAIL_RELAY_VERSION, true );

		// Pass REST API nonce and translatable strings to script.
		wp_localize_script(
			'scalyn-mail-relay-admin',
			'scalynMailRelaySettings',
			array(
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'runningLabel'   => __( 'Running...', 'scalyn-mail-relay' ),
				'errorPrefix'    => __( 'Error running diagnostics:', 'scalyn-mail-relay' ),
				'timeoutMessage' => __( 'The diagnostics run is taking longer than expected. Reload the page in a moment to see the latest results.', 'scalyn-mail-relay' ),
			)
		);
		wp_set_script_translations( 'scalyn-mail-relay-admin', 'scalyn-mail-relay' );
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
	 * Handles wizard POST requests before any page output is sent.
	 *
	 * Registered on the load-{hook} action so it fires before admin-header.php
	 * outputs HTML, which allows wp_safe_redirect() + exit to send the Location
	 * header before the response body begins.
	 *
	 * On GET (and any non-POST) requests, returns immediately so normal page
	 * rendering in render_wizard() proceeds unaffected.
	 */
	public function handle_wizard_post(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_METHOD is a server-controlled value, not user input.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		( new WizardController() )->handle();
	}

	/**
	 * Renders the providers page.
	 */
	public function render_providers(): void {
		( new ProvidersPage() )->render();
	}

	/**
	 * Renders the email logs page.
	 *
	 * MailLogRepository and TimelineRepository are resolved from the shared
	 * service container, consistent with the established plugin service pattern.
	 */
	public function render_logs(): void {
		$container = Plugin::instance()->container();
		( new LogsPage(
			$container->get( MailLogRepository::class ),
			$container->get( TimelineRepository::class )
		) )->render();
	}

	/**
	 * Renders the diagnostics page.
	 */
	public function render_diagnostics(): void {
		( new DiagnosticsPage() )->render();
	}
}
