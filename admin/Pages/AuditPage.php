<?php
/**
 * Capability-protected audit history.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Read-only audit page using an allowlisted repository read model. */
final class AuditPage {

	/**
	 * Creates the page.
	 *
	 * @param AuditRepository $repository Audit history read model.
	 */
	public function __construct( private AuditRepository $repository ) {}

	/** Checks permission before reading, then renders escaped bounded results. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) && ! current_user_can( Capabilities::VIEW_AUDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to view audit history.', 'scalyn-mail-relay' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination, capability checked above.
		$before = isset( $_GET['before'] ) && is_string( $_GET['before'] ) ? absint( wp_unslash( $_GET['before'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page size, capability checked above.
		$raw_size   = isset( $_GET['per_page'] ) && is_string( $_GET['per_page'] ) ? sanitize_text_field( wp_unslash( $_GET['per_page'] ) ) : '';
		$page_sizes = array( 10, 25, 50, 100 );
		$per_page   = in_array( $raw_size, array( '10', '25', '50', '100' ), true ) ? (int) $raw_size : 50;
		$page       = array(
			'rows' => array(),
			'next' => 0,
		);
		$error      = false;
		try {
			$page = $this->repository->page( $before, $per_page );
		} catch ( \Throwable $exception ) {
			$error = true;
		}
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/audit.php';
	}
}
