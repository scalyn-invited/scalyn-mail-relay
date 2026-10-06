<?php
/**
 * Protected, configuration-only SMTP2GO form.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Never renders a credential or registers a transport. */
final class Smtp2goSettingsForm {
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
	 * @param CredentialCipher   $cipher Credential protection.
	 */
	public function __construct( private SettingsRepository $settings, private CredentialCipher $cipher ) {}

	/** Handles only authorized, nonce-protected POST requests. */
	public function handle(): void {
		if ( $this->handled ) {
			return;
		}
		$this->handled = true;
		$can_manage    = current_user_can( Capabilities::MANAGE_MAIL );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Form detection only; values are read after nonce validation.
		$submitted = isset( $_POST['scalyn_smtp2go_settings'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server-controlled method.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && $submitted ) {
			if ( ! $can_manage ) {
				wp_die( esc_html__( 'You do not have permission to configure mail providers.', 'scalyn-mail-relay' ) );
			}
			check_admin_referer( 'scalyn_smtp2go_settings' );
			$input = array();
			foreach ( array( 'from_email', 'from_name', 'api_key', 'key_action' ) as $field ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Repository strictly validates types and content; secrets must not be rewritten.
				$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : null;
			}
			$input['confirm_remove'] = isset( $_POST['confirm_remove'] ) && '1' === $_POST['confirm_remove'];
			if ( in_array( $input['key_action'], array( 'keep', 'replace' ), true ) && ! $this->cipher->is_available() ) {
				$this->notice = __( 'No changes saved: server encryption is unavailable. Configure a valid SCALYN_MAIL_RELAY_ENCRYPTION_KEY and enable PHP OpenSSL, then save again.', 'scalyn-mail-relay' );
				return;
			}
			if ( 'keep' === $input['key_action'] && ! $this->settings->get_smtp2go_settings()['has_key'] ) {
				$this->notice = __( 'No changes saved: there is no saved API key to keep. Choose Add or replace key and enter your SMTP2GO API key.', 'scalyn-mail-relay' );
				return;
			}
			try {
				$this->saved  = $this->settings->save_smtp2go( $input, $this->cipher );
				$this->notice = $this->saved ? __( 'SMTP2GO configuration saved. Use the Setup Wizard to verify the connection. Removing a saved key does not revoke it at SMTP2GO.', 'scalyn-mail-relay' ) : __( 'SMTP2GO settings could not be saved. Try again.', 'scalyn-mail-relay' );
			} catch ( \Throwable $error ) {
				$this->notice = __( 'No changes saved. Check the sender fields, credential action and removal confirmation. Saving a key requires a valid server encryption key; an unreadable saved key must be replaced or removed.', 'scalyn-mail-relay' );
			}
			unset( $input );
		}
	}

	/**
	 * Renders only public settings and fixed save feedback.
	 *
	 * @param bool $in_wizard Whether to keep configuration inside wizard step 3.
	 */
	public function render( bool $in_wizard = false ): void {
		$this->handle();
		$can_manage = current_user_can( Capabilities::MANAGE_MAIL );
		$notice     = $this->notice;
		$saved      = $this->saved;
		if ( $in_wizard && $saved ) {
			$notice = __( 'SMTP2GO configuration saved. Continue to verify the connection. Removing a saved key does not revoke it at SMTP2GO.', 'scalyn-mail-relay' );
		}
		$encryption_available = $this->cipher->is_available();
		$wizard_step          = 'smtp2go' === $this->settings->get_active_provider_id() ? 4 : 2;
		$config               = $this->settings->get_smtp2go_settings();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/smtp2go-settings.php';
	}
}
