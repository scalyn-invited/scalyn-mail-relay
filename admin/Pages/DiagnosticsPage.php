<?php
/**
 * Diagnostics admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Capability-gated shell for the Diagnostics admin page.
 *
 * The diagnostic engine, health scoring, SPF/DKIM/DMARC checks, and
 * remediation UI will be implemented once Yaj delivers the diagnostics
 * module (includes/Diagnostics/). This class provides the navigation
 * anchor and access control only.
 *
 * Ownership: Kim / Admin (shell); Yaj (diagnostics engine and results UI).
 */
final class DiagnosticsPage {

	/**
	 * Performs a capability check and renders the diagnostics view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			wp_die( esc_html__( 'You do not have permission to run diagnostics.', 'scalyn-mail-relay' ) );
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/diagnostics.php';
	}
}
