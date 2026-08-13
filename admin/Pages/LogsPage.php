<?php
/**
 * Email Logs admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Capability-gated shell for the Email Logs admin page.
 *
 * Log data, timeline presentation, and filtering UI will be implemented
 * once Yaj delivers the logging module (includes/Logging/) and REST contracts.
 * This class provides the navigation anchor and access control only.
 *
 * Ownership: Kim / Admin (shell); Yaj (logging data and timeline UI).
 */
final class LogsPage {

	/**
	 * Performs a capability check and renders the logs view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_LOGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view email logs.', 'scalyn-mail-relay' ) );
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/logs.php';
	}
}
