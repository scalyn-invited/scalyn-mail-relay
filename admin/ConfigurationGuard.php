<?php
/**
 * Reject stale forms after another tab changes configuration selection.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Opaque revision tokens supplement, never replace, nonce/capability checks. */
final class ConfigurationGuard {
	/**
	 * Emits the revision belonging to the rendered settings snapshot.
	 *
	 * @param SettingsRepository|null $settings Optional rendered snapshot.
	 */
	public static function field( ?SettingsRepository $settings = null ): void {
		$settings ??= new SettingsRepository();
		echo '<input type="hidden" name="configuration_revision" value="' . esc_attr( $settings->get_diagnostic_revision() ) . '" />';
	}

	/**
	 * Checks a POST token after its controller has verified the nonce.
	 *
	 * @param SettingsRepository|null $snapshot Optional action's settings snapshot.
	 */
	public static function check( ?SettingsRepository $snapshot = null ): void {
		$settings = new SettingsRepository();
		if ( '' === $settings->get_active_configuration_id() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller must verify its operation nonce first.
		$revision = isset( $_POST['configuration_revision'] ) && is_string( $_POST['configuration_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['configuration_revision'] ) ) : '';
		if ( ! hash_equals( $settings->get_diagnostic_revision(), $revision ) || ( null !== $snapshot && ! hash_equals( $snapshot->get_diagnostic_revision(), $revision ) ) ) {
			wp_die( esc_html__( 'The selected configuration changed. Reload this page before continuing. No action was taken.', 'scalyn-mail-relay' ) );
		}
	}
}
