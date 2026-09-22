<?php
/**
 * Protected DKIM diagnostic configuration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Saves only the public selector through the settings repository. */
final class DkimSettingsForm {

	/**
	 * Uses the shared settings service so later consumers see saved values.
	 *
	 * @param SettingsRepository $settings Plugin settings.
	 */
	public function __construct( private SettingsRepository $settings ) {}

	/** Renders and handles only this form, with capability and nonce enforcement. */
	public function render(): void {
		$can_manage = current_user_can( Capabilities::MANAGE_SETTINGS );
		$notice     = '';
		$error      = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Form detection only; nonce checked before reading values or saving.
		$submitted = isset( $_POST['scalyn_dkim_settings'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server-controlled method.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && $submitted ) {
			if ( ! $can_manage ) {
				wp_die( esc_html__( 'You do not have permission to configure DKIM diagnostics.', 'scalyn-mail-relay' ) );
			}
			check_admin_referer( 'scalyn_dkim_settings' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict allowlist validation below rejects invalid input rather than rewriting it into a different selector.
			$value = isset( $_POST['dkim_selector'] ) && is_string( $_POST['dkim_selector'] ) ? trim( wp_unslash( $_POST['dkim_selector'] ) ) : null;
			if ( ! SettingsRepository::valid_dkim_selector( $value ) ) {
				$error  = true;
				$notice = __( 'Enter a selector of 1–63 letters, digits, hyphens or underscores, starting and ending with a letter or digit. Enter only the selector, not the full DNS name or key. Leave blank to clear it. No settings were changed.', 'scalyn-mail-relay' );
			} else {
				$this->settings->save( array( 'advanced' => array( 'dkim_selector' => $value ) ) );
				$error  = ( new SettingsRepository() )->get_dkim_selector() !== $value;
				$notice = $error ? __( 'DKIM selector could not be saved. Try again.', 'scalyn-mail-relay' ) : __( 'DKIM selector saved. Run diagnostics again to refresh the results; existing findings are unchanged until a new run.', 'scalyn-mail-relay' );
			}
		}
		$selector = $this->settings->get_dkim_selector();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/dkim-settings.php';
	}
}
