<?php
/**
 * Simplified read-only client dashboard and report.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Admin\ClientOverview;
use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Does not instantiate operational forms or transport services. */
final class ClientPage {

	/** Renders one fixed, allowlisted read model. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_CLIENT_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view the client summary.', 'scalyn-mail-relay' ) );
		}
		$summary = ClientOverview::load();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/client-overview.php';
	}
}
