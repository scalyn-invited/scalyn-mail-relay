<?php
/**
 * Protected report export form.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Does not query report evidence until an authorized download is requested. */
final class ReportsPage {

	/** Renders an escaped form with an export-specific nonce. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::EXPORT_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'scalyn-mail-relay' ) );
		}
		$end   = new \DateTimeImmutable( 'now', wp_timezone() );
		$start = $end->modify( '-7 days' );
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/reports.php';
	}
}
