<?php
/**
 * Named configuration POST boundary.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Explicit actions only; selecting an identity changes the default immediately. */
final class ConfigurationController {
	/** Handles capability/nonce protected catalogue operations. */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Configuration management is not authorized.', 'scalyn-mail-relay' ) );
		}
		check_admin_referer( 'scalyn_configuration' );
		ConfigurationGuard::check();
		$input = array();
		foreach ( array( 'operation', 'configuration', 'configuration_name', 'return_page' ) as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		try {
			$ok = Plugin::instance()->container()->get( SettingsRepository::class )->configure( $input['operation'], $input['configuration'], $input['configuration_name'] );
		} catch ( \Throwable $error ) {
			$ok = false;
		}
		$page = 'wizard' === $input['return_page'] || ( $ok && 'select' === $input['operation'] ) ? 'scalyn-mail-relay-wizard' : 'scalyn-mail-relay-providers';
		$url  = add_query_arg(
			array(
				'page'                => $page,
				'step'                => $ok && 'select' === $input['operation'] ? 3 : 2,
				'configuration_saved' => $ok ? 'yes' : 'no',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url . ( 'scalyn-mail-relay-providers' === $page ? '#saved-configurations' : '' ) );
		exit;
	}
}
