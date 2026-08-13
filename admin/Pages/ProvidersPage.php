<?php
/**
 * Providers admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Capability-gated shell for the Providers admin page.
 *
 * Provider listing, configuration forms, and connection management will be
 * implemented once Saturn delivers the provider contracts and REST endpoints.
 * This class provides the navigation anchor and access control only.
 *
 * Ownership: Kim / Admin (shell); Saturn (provider logic and UI content).
 */
final class ProvidersPage {

	/**
	 * Performs a capability check and renders the providers view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			wp_die( esc_html__( 'You do not have permission to manage mail providers.', 'scalyn-mail-relay' ) );
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/providers.php';
	}
}
