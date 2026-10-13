<?php
/** Privileged routing controls backed by credential-free projections.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\RoutingRepository;
use Scalyn\MailRelay\Mail\MailOrigin;

defined( 'ABSPATH' ) || exit;

/** Embedded in Providers. Saving policy never sends mail. */
final class RoutingForm {

	/** Renders accessible, bounded controls.
	 *
	 * @throws \RuntimeException Caught within the form boundary for invalid input.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$repository = new RoutingRepository();
		$settings   = new SettingsRepository();
		$notice     = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below before policy input.
		if ( isset( $_POST['scalyn_routing_save'] ) ) {
			check_admin_referer( 'scalyn_routing' );
			try {
				\Scalyn\MailRelay\Admin\ConfigurationGuard::check( $settings );
				$rules          = array();
				$current_policy = $repository->policy();
				foreach ( MailOrigin::types() as $key => $label ) {
					$field = str_replace( '/', '__', $key );
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each scalar is validated and sanitized below; repository validates identifiers.
					$raw = isset( $_POST['routes'][ $field ] ) && is_array( $_POST['routes'][ $field ] ) ? wp_unslash( $_POST['routes'][ $field ] ) : array();
					// Initial enable without JavaScript submits no disabled selections.
					if ( ! $current_policy['enabled'] && ! isset( $_POST['routes'] ) ) {
						$raw = $current_policy['rules'][ $key ] ?? array();
					}
					$ids = array();
					foreach ( $raw as $id ) {
						if ( ! is_string( $id ) ) {
							throw new \RuntimeException( 'Invalid routing selection.' );
						}
						if ( '' !== $id ) {
							$ids[] = sanitize_text_field( $id );
						}
					}
					$rules[ $key ] = $ids;
				}
				$token = isset( $_POST['routing_token'] ) && is_string( $_POST['routing_token'] ) ? sanitize_text_field( wp_unslash( $_POST['routing_token'] ) ) : '';
				$repository->save( isset( $_POST['routing_enabled'] ), $rules, $token, $settings );
				$notice = __( 'Routing saved. No email was sent.', 'scalyn-mail-relay' );
			} catch ( \Throwable $error ) {
				$notice = __( 'Routing was not saved. Reload and check access, configuration readiness and duplicate selections.', 'scalyn-mail-relay' );
			}
		}
		$policy         = $repository->policy();
		$configurations = array_filter( $settings->get_configurations(), static fn( array $item ): bool => ! $item['archived'] );
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/routing.php';
	}
}
