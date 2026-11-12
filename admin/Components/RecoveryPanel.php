<?php
/** Credential-free recovery read model and explicit storage consent controls.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Recovery\RecoveryPolicyRepository;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;
use Scalyn\MailRelay\Database\SubmissionAttemptRepository;

defined( 'ABSPATH' ) || exit;

/** Never reads encrypted payload, transport configuration or recipients. */
final class RecoveryPanel {

	/** Renders only for authorized operators. */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$policy      = ( new RecoveryPolicyRepository() )->policy();
		$ready       = version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.13.0', '>=' );
		$rows        = array();
		$history     = array();
		$unavailable = ! $ready;
		try {
			if ( $ready ) {
				$rows = ( new RecoveryQueueRepository() )->summaries();
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded history selection.
				$logical = isset( $_GET['recovery_message'] ) && is_string( $_GET['recovery_message'] ) ? sanitize_text_field( wp_unslash( $_GET['recovery_message'] ) ) : '';
				$history = ( new SubmissionAttemptRepository() )->history( $logical );
			}
		} catch ( \Throwable $error ) {
			$unavailable = true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixed feedback labels only, no actions or reflected input.
		$notice_key = isset( $_GET['recovery_notice'] ) && is_string( $_GET['recovery_notice'] ) ? sanitize_key( wp_unslash( $_GET['recovery_notice'] ) ) : '';
		$notices    = array(
			'saved'         => __( 'Recovery preference saved. No email was sent by this action.', 'scalyn-mail-relay' ),
			'cancelled'     => __( 'Pending work cancelled and its payload erased. This cannot recall an in-flight email.', 'scalyn-mail-relay' ),
			'not_cancelled' => __( 'Work was not cancelled. It may be in flight or already terminal; review provider activity.', 'scalyn-mail-relay' ),
			'failed'        => __( 'No confirmed change. Reload and check access, encryption, schema, storage consent and configured named default.', 'scalyn-mail-relay' ),
		);
		$notice     = $notices[ $notice_key ] ?? '';
		$labels     = array(
			'pending'    => __( 'Queued — not Accepted', 'scalyn-mail-relay' ),
			'running'    => __( 'Preparing — not Accepted', 'scalyn-mail-relay' ),
			'submitting' => __( 'Submitting — outcome pending', 'scalyn-mail-relay' ),
			'accepted'   => __( 'Accepted — delivery unconfirmed', 'scalyn-mail-relay' ),
			'failed'     => __( 'Failed — stopped', 'scalyn-mail-relay' ),
			'review'     => __( 'Needs review — do not resend', 'scalyn-mail-relay' ),
			'expired'    => __( 'Expired — payload erased', 'scalyn-mail-relay' ),
			'cancelled'  => __( 'Cancelled', 'scalyn-mail-relay' ),
		);
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/recovery.php';
	}
}
