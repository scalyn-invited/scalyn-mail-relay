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

		$providers_hook = add_submenu_page(
			'scalyn-mail-relay',
			__( 'Providers — Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Providers', 'scalyn-mail-relay' ),
			Capabilities::MANAGE_MAIL,
			'scalyn-mail-relay-providers',
			array( $this, 'render_providers' )
		);

		if ( $providers_hook ) {
			add_action( 'load-' . $providers_hook, array( $this, 'handle_providers_load' ) );
		}

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
		if ( current_user_can( 'manage_options' ) ) {
			$ai_container = Plugin::instance()->container();
			add_submenu_page( 'scalyn-mail-relay', __( 'AI Assistant', 'scalyn-mail-relay' ), __( 'AI Assistant', 'scalyn-mail-relay' ), 'manage_options', 'scalyn-mail-relay-ai', array( new \Scalyn\MailRelay\Admin\Pages\AiPage( $ai_container->get( \Scalyn\MailRelay\AI\AiSettings::class ), $ai_container->get( \Scalyn\MailRelay\AI\AiAdapters::class ) ), 'render' ) );
		}
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
		if ( preg_match( '/scalyn-mail-relay-(providers|agency|data-controls|diagnostics|alerts|ai)$/', $hook ) ) {
			$tabs_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/provider-tabs.js';
			wp_enqueue_script( 'scalyn-provider-tabs', SCALYN_MAIL_RELAY_URL . 'assets/js/provider-tabs.js', array(), (string) filemtime( $tabs_script ), true );
		}
		if ( str_ends_with( $hook, 'scalyn-mail-relay-ai' ) && current_user_can( 'manage_options' ) ) {
			$ai_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/ai-assistant.js';
			wp_enqueue_script( 'scalyn-ai-assistant', SCALYN_MAIL_RELAY_URL . 'assets/js/ai-assistant.js', array(), (string) filemtime( $ai_script ), true );
			$ai_settings_script = SCALYN_MAIL_RELAY_PATH . 'assets/js/ai-settings.js';
			wp_enqueue_script( 'scalyn-ai-settings', SCALYN_MAIL_RELAY_URL . 'assets/js/ai-settings.js', array(), (string) filemtime( $ai_settings_script ), true );
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

		// scalyn_gmail_client POSTs trigger an external OAuth redirect — must run here,
		// before admin-header.php outputs HTML, so wp_redirect() can send the Location header.
		$this->handle_gmail_client_post();

		( new WizardController() )->handle();
	}

	/**
	 * Handles GET requests on the providers page load hook.
	 *
	 * Detects the Gmail OAuth callback (code + state params) and exchanges the
	 * authorization code for tokens. Stores a result transient and redirects to
	 * the same page without OAuth params to prevent double-processing on reload.
	 */
	public function handle_providers_load(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$method = $_SERVER['REQUEST_METHOD'] ?? '';

		// scalyn_gmail_client POSTs trigger an external OAuth redirect — must run here,
		// before admin-header.php outputs HTML, so wp_redirect() can send the Location header.
		if ( 'POST' === $method ) {
			$this->handle_gmail_client_post();
			return;
		}

		if ( 'GET' !== $method ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- State token is used as CSRF protection for OAuth flows.
		$has_code  = isset( $_GET['code'] ) || isset( $_GET['error'] );
		$has_state = isset( $_GET['state'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $has_code || ! $has_state ) {
			return;
		}

		// Looks like an OAuth callback — ensure the user has permission.
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			return;
		}

		$this->handle_gmail_oauth_callback();
	}

	/**
	 * Handles a scalyn_gmail_client POST on a load- hook, before admin output begins.
	 *
	 * When the user submits the Gmail client credentials form (from either the Setup Wizard
	 * or the Providers page configure panel), GmailSettingsForm::handle() saves the
	 * encrypted credentials and then calls initiate_oauth(), which calls wp_redirect() and
	 * exits. That redirect MUST happen before admin-header.php outputs HTML; calling it from
	 * a page-content callback produces a "headers already sent" warning and a blank page.
	 *
	 * scalyn_gmail_sender POSTs do not redirect, so they can remain in the render path.
	 */
	private function handle_gmail_client_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['scalyn_gmail_client'] ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			return;
		}
		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		$cipher    = $container->get( \Scalyn\MailRelay\Core\CredentialCipher::class );
		( new \Scalyn\MailRelay\Admin\Components\GmailSettingsForm( $settings, $cipher ) )->handle();
		// If credentials are valid, handle() calls initiate_oauth() → wp_redirect() → exit.
		// If validation fails (empty fields, cipher unavailable), handle() returns here and
		// the render-path instance will show the error notice.
	}

	/**
	 * Processes the Google OAuth callback: validates state, exchanges code, saves tokens.
	 *
	 * Uses a single-use state transient keyed by user ID to prevent CSRF and replay.
	 * After processing, redirects to the providers page (clean URL).
	 *
	 * @security Tokens and secrets must never appear in redirect URLs or transient values.
	 */
	private function handle_gmail_oauth_callback(): void {
		$redirect_to = admin_url( 'admin.php?page=scalyn-mail-relay-providers' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		$received_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code           = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$error_param    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended

		$transient_key = 'scalyn_gmail_oauth_' . get_current_user_id();
		$stored        = get_transient( $transient_key );
		delete_transient( $transient_key ); // Single-use regardless of outcome.

		if ( '' !== $error_param ) {
			$message = 'access_denied' === $error_param
				? __( 'Google authorization was denied. No account was connected.', 'scalyn-mail-relay' )
				: __( 'Google returned an error during authorization. Try reconnecting.', 'scalyn-mail-relay' );
			set_transient( 'scalyn_gmail_oauth_result_' . get_current_user_id(), array( 'success' => false, 'message' => $message ), 60 );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( ! is_array( $stored ) || '' === $received_state || $received_state !== ( $stored['state'] ?? '' ) ) {
			set_transient( 'scalyn_gmail_oauth_result_' . get_current_user_id(), array( 'success' => false, 'message' => __( 'The OAuth state token is invalid or expired. Please try connecting again.', 'scalyn-mail-relay' ) ), 60 );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( '' === $code ) {
			set_transient( 'scalyn_gmail_oauth_result_' . get_current_user_id(), array( 'success' => false, 'message' => __( 'No authorization code received from Google. Please try again.', 'scalyn-mail-relay' ) ), 60 );
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$verifier = (string) ( $stored['verifier'] ?? '' );
		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		$cipher    = $container->get( \Scalyn\MailRelay\Core\CredentialCipher::class );

		try {
			$gmail_config  = $settings->get_gmail_settings();
			$client_id     = $gmail_config['client_id'];
			if ( '' === $client_id || ! $gmail_config['has_secret'] ) {
				throw new \RuntimeException( 'No Gmail client credentials saved. Save Client ID and Secret first.' );
			}
			// Retrieve the secret for the token exchange (tokens not required yet).
			$client_secret = $settings->get_gmail_client_secret( $cipher );
			$redirect_uri = admin_url( 'admin.php?page=scalyn-mail-relay-providers' );
			$tokens       = ( new \Scalyn\MailRelay\Providers\Gmail\OAuthClient() )->exchange_code(
				$client_id,
				$client_secret,
				$code,
				$redirect_uri,
				$verifier
			);
			$access_token  = $tokens['access_token'];
			$refresh_token = $tokens['refresh_token'];
			$expires_at    = time() + (int) $tokens['expires_in'];

			$settings->save_gmail_tokens( $client_secret, $access_token, $refresh_token, $expires_at, $cipher );

			unset( $tokens, $access_token, $refresh_token, $client_secret );

			set_transient(
				'scalyn_gmail_oauth_result_' . get_current_user_id(),
				array(
					'success' => true,
					'message' => __( 'Google account connected. Set the sender email below and use the Setup Wizard to verify the connection.', 'scalyn-mail-relay' ),
				),
				60
			);
			// Drop back into the configure panel so the sender form is immediately accessible.
			$redirect_to = add_query_arg( 'configure', 'gmail', $redirect_to );
		} catch ( \Throwable $error ) {
			set_transient(
				'scalyn_gmail_oauth_result_' . get_current_user_id(),
				array(
					'success' => false,
					'message' => __( 'Google authorization failed. Check the client credentials and redirect URI, then try again.', 'scalyn-mail-relay' ),
				),
				60
			);
		}

		wp_safe_redirect( $redirect_to );
		exit;
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
