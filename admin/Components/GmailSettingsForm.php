<?php
/**
 * Protected Gmail OAuth configuration form.
 *
 * Manages the Google OAuth 2.0 authorization flow for Gmail sending.
 * Client credentials are saved here; token exchange happens via the
 * OAuth callback in AdminMenu::handle_providers_load().
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Providers\Gmail\OAuthClient;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Gmail OAuth connection form and handles credential saves.
 *
 * @security OAuth tokens are never rendered in markup. The client secret is
 * encrypted before storage. Only the client_id (non-sensitive) is displayed.
 */
final class GmailSettingsForm {

	/**
	 * Whether this request has already been processed.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * Fixed safe feedback for the current request.
	 *
	 * @var string
	 */
	private string $notice = '';

	/**
	 * Whether the submitted changes were persisted.
	 *
	 * @var bool
	 */
	private bool $saved = false;

	/**
	 * Uses the shared repository and credential protector.
	 *
	 * @param SettingsRepository $settings Settings service.
	 * @param CredentialCipher   $cipher   Credential protection.
	 */
	public function __construct(
		private SettingsRepository $settings,
		private CredentialCipher $cipher
	) {}

	/**
	 * Handles POST requests for saving client credentials or updating the sender.
	 *
	 * OAuth token exchange is handled separately in AdminMenu::handle_providers_load().
	 */
	public function handle(): void {
		if ( $this->handled ) {
			return;
		}
		$this->handled = true;
		$can_manage    = current_user_can( Capabilities::MANAGE_MAIL )
			&& ! \Scalyn\MailRelay\Agency\ManagedAccess::is_locked( 'providers' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// Save client credentials (triggers OAuth connect flow redirect).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['scalyn_gmail_client'] ) ) {
			if ( ! $can_manage ) {
				wp_die( esc_html__( 'You do not have permission to configure mail providers.', 'scalyn-mail-relay' ) );
			}
			check_admin_referer( 'scalyn_gmail_client' );
			\Scalyn\MailRelay\Admin\ConfigurationGuard::check( $this->settings );

			$this->handle_save_client();
			return;
		}

		// Save sender fields only.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['scalyn_gmail_sender'] ) ) {
			if ( ! $can_manage ) {
				wp_die( esc_html__( 'You do not have permission to configure mail providers.', 'scalyn-mail-relay' ) );
			}
			check_admin_referer( 'scalyn_gmail_sender' );
			\Scalyn\MailRelay\Admin\ConfigurationGuard::check( $this->settings );

			$this->handle_save_sender();
		}
	}

	/**
	 * Renders the form with current credential-free settings.
	 *
	 * @param bool $in_wizard Whether rendered inside wizard step 3.
	 */
	public function render( bool $in_wizard = false ): void {
		$this->handle();
		$can_manage = current_user_can( Capabilities::MANAGE_MAIL )
			&& ! \Scalyn\MailRelay\Agency\ManagedAccess::is_locked( 'providers' );
		$notice     = $this->notice;
		$saved      = $this->saved;
		if ( $in_wizard && $this->saved ) {
			$notice = __( 'Gmail sender saved. Continue to connection verification.', 'scalyn-mail-relay' );
		}
		$encryption_available = $this->cipher->is_available() || $this->cipher->can_initialize();
		$config               = $this->settings->get_gmail_settings();
		$redirect_uri         = $this->get_redirect_uri();
		$wizard_step          = 'gmail' === $this->settings->get_active_provider_id() ? 4 : 2;
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/gmail-settings.php';
	}

	/**
	 * Returns the OAuth redirect URI for display and registration in Google Cloud Console.
	 *
	 * @return string Exact redirect URI that must be registered in Google Cloud Console.
	 */
	public function get_redirect_uri(): string {
		return admin_url( 'admin.php?page=scalyn-mail-relay-providers' );
	}

	/**
	 * Builds the Google OAuth authorization URL and redirects the browser.
	 *
	 * Stores a single-use state token and PKCE verifier in a per-user transient.
	 * The state is validated in AdminMenu::handle_providers_load() after the callback.
	 *
	 * @param string $client_id OAuth client ID.
	 * @return void (exits on redirect)
	 */
	public function initiate_oauth( string $client_id ): void {
		$client       = new OAuthClient();
		$pkce         = $client->generate_pkce();
		$state        = $client->generate_state();
		$redirect_uri = $this->get_redirect_uri();

		// Store single-use state+verifier, keyed by user. TTL is 10 minutes.
		set_transient(
			$this->state_transient_key(),
			array(
				'state'    => $state,
				'verifier' => $pkce['verifier'],
			),
			600
		);

		$url = $client->authorization_url( $client_id, $redirect_uri, $state, $pkce['challenge'] );
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- External OAuth URL; wp_safe_redirect blocks non-local URLs.
		exit;
	}

	/**
	 * Returns the per-user OAuth state transient key.
	 *
	 * @return string
	 */
	public function state_transient_key(): string {
		return 'scalyn_gmail_oauth_' . get_current_user_id();
	}

	/**
	 * Processes the client credentials form POST.
	 */
	private function handle_save_client(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() in handle() before this method is called.
		$raw_id = isset( $_POST['gmail_client_id'] ) ? wp_unslash( $_POST['gmail_client_id'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() in handle() before this method is called.
		$raw_secret = isset( $_POST['gmail_client_secret'] ) ? wp_unslash( $_POST['gmail_client_secret'] ) : '';

		$client_id     = sanitize_text_field( (string) $raw_id );
		$client_secret = (string) $raw_secret; // Not sanitized — credentials must not be mutated.

		if ( '' === $client_id || '' === $client_secret ) {
			$this->notice = __( 'No changes saved: both Client ID and Client Secret are required.', 'scalyn-mail-relay' );
			return;
		}

		if ( ! ( $this->cipher->is_available() || $this->cipher->can_initialize() ) ) {
			$this->notice = __( 'No changes saved: server encryption is unavailable. Check private file permissions and PHP OpenSSL support.', 'scalyn-mail-relay' );
			return;
		}

		try {
			$this->settings->save_gmail_client( $client_id, $client_secret, $this->cipher );
			$this->saved = true;
		} catch ( \Throwable $error ) {
			$this->notice = __( 'No changes saved. Check the Client ID and Secret formats. The Client ID must end with .apps.googleusercontent.com.', 'scalyn-mail-relay' );
			return;
		}
		unset( $raw_secret, $client_secret );

		// Credentials saved — now initiate the OAuth flow.
		$this->initiate_oauth( $client_id );
	}

	/**
	 * Processes the sender (from_email, from_name) form POST.
	 */
	private function handle_save_sender(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() in handle() before this method is called.
		$raw_email = isset( $_POST['gmail_from_email'] ) ? wp_unslash( $_POST['gmail_from_email'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() in handle() before this method is called.
		$raw_name = isset( $_POST['gmail_from_name'] ) ? wp_unslash( $_POST['gmail_from_name'] ) : '';

		try {
			$this->settings->save_gmail_sender(
				sanitize_email( (string) $raw_email ),
				sanitize_text_field( (string) $raw_name )
			);
			$this->saved  = true;
			$this->notice = __( 'Gmail sender saved.', 'scalyn-mail-relay' );
		} catch ( \Throwable $error ) {
			$this->notice = __( 'No changes saved. Check the sender email address.', 'scalyn-mail-relay' );
		}
	}
}
