<?php
/** Recovery policy and cancellation actions; no resend/worker execution endpoint.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Recovery\RecoveryPolicyRepository;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;

defined( 'ABSPATH' ) || exit;

/** Fixed messages only; never reflect errors or private queue content. */
final class RecoveryController {

	/** Handles nonce/capability/managed-policy protected POSTs. */
	public function handle(): void {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Recovery actions require a form submission.', 'scalyn-mail-relay' ) );
		}
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage recovery.', 'scalyn-mail-relay' ) );
		}
		check_admin_referer( 'scalyn_recovery' );
		\Scalyn\MailRelay\Agency\ManagedAccess::protect_form( 'critical' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$notice    = 'failed';
		try {
			if ( 'policy' === $operation ) {
				$token = isset( $_POST['policy_token'] ) && is_string( $_POST['policy_token'] ) ? sanitize_text_field( wp_unslash( $_POST['policy_token'] ) ) : '';
				( new RecoveryPolicyRepository() )->save( isset( $_POST['recovery_enabled'] ), isset( $_POST['storage_consent'] ), $token, new SettingsRepository(), new CredentialCipher() );
				$notice = 'saved';
			} elseif ( 'cancel' === $operation ) {
				$id     = isset( $_POST['logical_uuid'] ) && is_string( $_POST['logical_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['logical_uuid'] ) ) : '';
				$saved  = ( new RecoveryQueueRepository() )->cancel( $id );
				$notice = $saved ? 'cancelled' : 'not_cancelled';
				if ( \Scalyn\MailRelay\Database\SubmissionAttemptRepository::uuid( $id ) ) {
					do_action( \Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT, new \Scalyn\MailRelay\Audit\AuditEvent( 'recovery_action', $saved ? 'cancelled' : 'cancel_denied', $id ) );
				}
			}
		} catch ( \Throwable $error ) {
			$notice = 'failed';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=scalyn-mail-relay-alerts&recovery_notice=' . $notice . '#scalyn-recovery' ) );
		exit;
	}
}
