<?php
/**
 * Admin menu registration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Admin\Pages\DashboardPage;
use Scalyn\MailRelay\Admin\Pages\DataControlsPage;
use Scalyn\MailRelay\Admin\Pages\AuditPage;
use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\RetentionStateRepository;
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
		add_action( 'admin_menu', array( AgencyBranding::class, 'apply_menu_label' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( AgencyBranding::class, 'enqueue_menu_styles' ) );
		add_action( 'admin_notices', array( AgencyBranding::class, 'render' ) );
		add_action( 'admin_post_scalyn_export_report', array( new ReportExportController(), 'handle' ) );
		add_action( 'admin_post_scalyn_client_report', array( new ClientReportController(), 'handle' ) );
		add_action( 'admin_post_scalyn_configuration', array( new ConfigurationController(), 'handle' ) );
		add_action( 'admin_post_scalyn_recovery', array( new RecoveryController(), 'handle' ) );
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

		// Keep the first submenu as Dashboard regardless of the saved brand name.
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
		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Audit History — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Audit History', 'scalyn-mail-relay' ),
			current_user_can( Capabilities::VIEW_AUDIT ) ? Capabilities::VIEW_AUDIT : Capabilities::MANAGE_SETTINGS,
			'scalyn-mail-relay-audit',
			array( $this, 'render_audit' )
		);
		add_submenu_page( 'scalyn-mail-relay', __( 'Alerts & Recovery', 'scalyn-mail-relay' ), __( 'Alerts & Recovery', 'scalyn-mail-relay' ), Capabilities::MANAGE_SETTINGS, 'scalyn-mail-relay-alerts', array( $this, 'render_alerts' ) );
		add_submenu_page( 'scalyn-mail-relay', __( 'Reports', 'scalyn-mail-relay' ), __( 'Reports', 'scalyn-mail-relay' ), Capabilities::EXPORT_REPORTS, 'scalyn-mail-relay-reports', array( new \Scalyn\MailRelay\Admin\Pages\ReportsPage(), 'render' ) );
		if ( \Scalyn\MailRelay\Agency\ManagedAccess::is_client() ) {
			add_submenu_page( 'scalyn-mail-relay', __( 'Summary Reports', 'scalyn-mail-relay' ), __( 'Summary Reports', 'scalyn-mail-relay' ), Capabilities::VIEW_CLIENT_REPORTS, 'scalyn-mail-relay-client-reports', array( new \Scalyn\MailRelay\Admin\Pages\ClientPage(), 'render' ) );
		}
		add_submenu_page( 'scalyn-mail-relay', __( 'Agency Settings', 'scalyn-mail-relay' ), __( 'Agency Settings', 'scalyn-mail-relay' ), Capabilities::MANAGE_AGENCY, 'scalyn-mail-relay-agency', array( new \Scalyn\MailRelay\Admin\Pages\AgencyPage( Plugin::instance()->container()->get( \Scalyn\MailRelay\Agency\AgencyRepository::class ) ), 'render' ) );
		add_submenu_page(
			'scalyn-mail-relay',
			__( 'Settings — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Settings', 'scalyn-mail-relay' ),
			Capabilities::MANAGE_SETTINGS,
			'scalyn-mail-relay-data-controls',
			array( $this, 'render_data_controls' )
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

		$style_path    = SCALYN_MAIL_RELAY_PATH . 'assets/css/admin.css';
		$style_version = is_readable( $style_path ) ? (string) filemtime( $style_path ) : SCALYN_MAIL_RELAY_VERSION;
		wp_enqueue_style( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/css/admin.css', array(), $style_version );
		wp_add_inline_style( 'scalyn-mail-relay-admin', AgencyBranding::css() );
		$help_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/helper-text.js';
		if ( str_ends_with( $hook, 'scalyn-mail-relay-providers' ) ) {
			$catalog_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/provider-catalog.js';
			wp_enqueue_script( 'scalyn-provider-catalog', SCALYN_MAIL_RELAY_URL . 'assets/js/provider-catalog.js', array(), (string) filemtime( $catalog_script ), true );
		}
		wp_enqueue_script( 'scalyn-helper-text', SCALYN_MAIL_RELAY_URL . 'assets/js/helper-text.js', array(), (string) filemtime( $help_script ), true );
		if ( preg_match( '/scalyn-mail-relay-(providers|agency|data-controls|diagnostics|alerts)$/', $hook ) ) {
			$tabs_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/provider-tabs.js';
			wp_enqueue_script( 'scalyn-provider-tabs', SCALYN_MAIL_RELAY_URL . 'assets/js/provider-tabs.js', array(), (string) filemtime( $tabs_script ), true );
		}
		if ( false !== strpos( $hook, 'scalyn-mail-relay-agency' ) && ( current_user_can( Capabilities::MANAGE_AGENCY ) || current_user_can( 'manage_options' ) ) ) {
			wp_enqueue_media();
			$brand_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/agency-branding.js';
			wp_enqueue_script( 'scalyn-agency-branding', SCALYN_MAIL_RELAY_URL . 'assets/js/agency-branding.js', array( 'media-views' ), (string) filemtime( $brand_script ), true );
		}

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
		$script_path    = SCALYN_MAIL_RELAY_PATH . 'assets/js/admin.js';
		$script_version = is_readable( $script_path ) ? (string) filemtime( $script_path ) : SCALYN_MAIL_RELAY_VERSION;
		wp_enqueue_script( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/js/admin.js', array(), $script_version, true );

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

	/** Renders retention and uninstall controls. */
	public function render_data_controls(): void {
		$container = Plugin::instance()->container();
		( new DataControlsPage( $container->get( SettingsRepository::class ), $container->get( RetentionStateRepository::class ) ) )->render();
	}

	/** Renders the bounded audit history view. */
	public function render_audit(): void {
		( new AuditPage( Plugin::instance()->container()->get( AuditRepository::class ) ) )->render();
	}

	/** Renders protected alert settings and history through shared services. */
	public function render_alerts(): void {
		$container = Plugin::instance()->container();
		( new \Scalyn\MailRelay\Admin\Pages\AlertsPage( $container->get( \Scalyn\MailRelay\Alerts\AlertRepository::class ), $container->get( \Scalyn\MailRelay\Alerts\WebhookChannel::class ) ) )->render();
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
			$container->get( TimelineRepository::class ),
			$container->get( \Scalyn\MailRelay\Delivery\DeliveryCoverage::class )
		) )->render();
	}

	/**
	 * Renders the diagnostics page.
	 */
	public function render_diagnostics(): void {
		( new DiagnosticsPage() )->render();
	}
}
