<?php
/** Durable outbox ownership, claims and irreversible terminal states.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** All payload reads are worker-private. Admin uses summaries only. */
class RecoveryQueueRepository {

	public const LOCK = 'scalyn_mail_relay_queue_capacity_lock';

	/**
	 * Acknowledges durable ownership only after a successful bounded INSERT.
	 *
	 * @param string $logical UUID.
	 * @param string $payload Authenticated encrypted envelope.
	 * @param int    $now UTC Unix time.
	 * @throws \RuntimeException For capacity, duplicate, invalid or failed ownership.
	 */
	public function enqueue( string $logical, #[\SensitiveParameter] string $payload, int $now ): void {
		global $wpdb;
		if ( ! SubmissionAttemptRepository::uuid( $logical ) || ! str_starts_with( $payload, 'v1:' ) || strlen( $payload ) > 12000000 || ! add_option( self::LOCK, true, '', false ) ) {
			throw new \RuntimeException( 'Recovery ownership is unavailable.' );
		}
		try {
			$table = $wpdb->prefix . 'scalyn_recovery_queue';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialized capacity check across queue insertions, excluding terminal empty payloads.
			$capacity = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, COALESCE(SUM(OCTET_LENGTH(payload)),0) AS bytes FROM %i WHERE payload <> ''", $table ), ARRAY_A );
			if ( ! is_array( $capacity ) || (int) $capacity['total'] >= 100 || (int) $capacity['bytes'] + strlen( $payload ) > 20971520 ) {
				throw new \RuntimeException( 'Recovery storage capacity is unavailable.' );
			}
			$at = gmdate( 'Y-m-d H:i:s', $now );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable queue ownership, never replaced on duplicate UUID.
			$saved = $wpdb->insert(
				$table,
				array(
					'logical_uuid'  => $logical,
					'payload'       => $payload,
					'state'         => 'pending',
					'attempt_count' => 0,
					'next_at'       => $at,
					'expires_at'    => gmdate( 'Y-m-d H:i:s', $now + 86400 ),
					'created_at'    => $at,
					'updated_at'    => $at,
					'reason'        => 'owned',
				)
			);
			if ( 1 !== $saved ) {
				throw new \RuntimeException( 'Recovery ownership could not be saved.' );
			}
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Claims one due message atomically. Crashed claims are never automatically replayed.
	 *
	 * @param int $now UTC Unix time.
	 * @return array|null Private row with encrypted payload and claim token.
	 */
	public function claim( int $now ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'scalyn_recovery_queue';
		$at    = gmdate( 'Y-m-d H:i:s', $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed bounded candidate read; ownership established by CAS below.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE state=%s AND next_at<=%s AND expires_at>%s AND attempt_count<3 ORDER BY next_at,logical_uuid LIMIT 1', $table, 'pending', $at, $at ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$token = wp_generate_uuid4();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic ownership; racing workers cannot both win.
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state=%s,claim_token=%s,claimed_at=%s,updated_at=%s WHERE logical_uuid=%s AND state=%s AND next_at<=%s AND expires_at>%s AND attempt_count<3', $table, 'running', $token, $at, $at, $row['logical_uuid'], 'pending', $at, $at ) );
		if ( 1 !== $won ) {
			return null;
		}
		$row['claim_token'] = $token;
		return $row;
	}

	/**
	 * Commits the irreversible submission boundary before the adapter may run.
	 *
	 * @param array $row Private claimed row.
	 * @param int   $now UTC Unix time.
	 */
	public function submitting( array $row, int $now ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Claim-checked irreversible transition with expiry and submission bounds.
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state=%s,attempt_count=attempt_count+1,updated_at=%s WHERE logical_uuid=%s AND state=%s AND claim_token=%s AND expires_at>%s AND attempt_count=%d AND attempt_count<3', $wpdb->prefix . 'scalyn_recovery_queue', 'submitting', gmdate( 'Y-m-d H:i:s', $now ), $row['logical_uuid'], 'running', $row['claim_token'], gmdate( 'Y-m-d H:i:s', $now ), (int) $row['attempt_count'] ) );
	}

	/**
	 * Finishes a claim; every terminal state discards content immediately.
	 *
	 * @param array  $row Private claimed row.
	 * @param string $state pending/accepted/failed/review.
	 * @param string $reason Fixed operational reason.
	 * @param int    $now UTC time.
	 * @param int    $next_at Next eligible time, never before the fixed delay.
	 * @throws \RuntimeException For invalid or lost claim.
	 */
	public function finish( array $row, string $state, string $reason, int $now, int $next_at = 0 ): void {
		global $wpdb;
		if ( ! in_array( $state, array( 'pending', 'accepted', 'failed', 'review' ), true ) || ! in_array( $reason, array( 'accepted', 'permanent', 'unconfirmed', 'retry_wait', 'exhausted', 'configuration_changed', 'storage_unavailable', 'disabled' ), true ) ) {
			throw new \RuntimeException( 'Invalid recovery transition.' );
		}
		$table = $wpdb->prefix . 'scalyn_recovery_queue';
		$sql   = 'pending' === $state
			? 'UPDATE %i SET state=%s,reason=%s,next_at=%s,claim_token=NULL,claimed_at=NULL,updated_at=%s WHERE logical_uuid=%s AND claim_token=%s AND state IN (%s,%s)'
			: "UPDATE %i SET state=%s,reason=%s,next_at=%s,claim_token=NULL,claimed_at=NULL,updated_at=%s,payload='' WHERE logical_uuid=%s AND claim_token=%s AND state IN (%s,%s)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed allowlisted SQL templates prepared here.
		$saved = $wpdb->query( $wpdb->prepare( $sql, $table, $state, $reason, gmdate( 'Y-m-d H:i:s', 0 !== $next_at ? $next_at : $now ), gmdate( 'Y-m-d H:i:s', $now ), $row['logical_uuid'], $row['claim_token'], 'running', 'submitting' ) );
		if ( 1 !== $saved ) {
			throw new \RuntimeException( 'Recovery claim could not be finalized. Do not resend without review.' );
		}
	}

	/**
	 * Deletes expired content even when recovery is off. Unknown claims become review.
	 *
	 * @param int $now UTC time.
	 */
	public function sweep( int $now ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'scalyn_recovery_queue';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded crash cleanup never makes a claim retryable.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state=%s,reason=%s,payload='',claim_token=NULL,updated_at=%s WHERE state IN (%s,%s) AND claimed_at<=%s LIMIT 100", $table, 'review', 'unconfirmed', gmdate( 'Y-m-d H:i:s', $now ), 'running', 'submitting', gmdate( 'Y-m-d H:i:s', $now - 300 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Hard expiry is also checked before decrypting and before submission.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state=CASE WHEN state IN ('running','submitting') THEN 'review' ELSE 'expired' END,reason=CASE WHEN state IN ('running','submitting','review') THEN 'unconfirmed' ELSE 'expired' END,payload='',claim_token=NULL,updated_at=%s WHERE expires_at<=%s AND payload<>'' LIMIT 100", $table, gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now ) ) );
	}

	/**
	 * Credential/content-free read model, at most 50 rows with cursor pagination.
	 *
	 * @param string $before Optional UTC timestamp cursor.
	 */
	public function summaries( string $before = '9999-12-31 23:59:59' ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded public read; encrypted payload and sender plan explicitly excluded.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT logical_uuid,state,attempt_count,next_at,expires_at,created_at,reason FROM %i WHERE created_at<%s ORDER BY created_at DESC,logical_uuid DESC LIMIT 50', $wpdb->prefix . 'scalyn_recovery_queue', $before ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Cancellation cannot undo an in-flight submission; never offers manual resend.
	 *
	 * @param string $logical UUID.
	 */
	public function cancel( string $logical ): bool {
		global $wpdb;
		if ( ! SubmissionAttemptRepository::uuid( $logical ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Safe pending/claimed cancellation; submitting is deliberately excluded.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET state=%s,reason=%s,payload='',claim_token=NULL,updated_at=%s WHERE logical_uuid=%s AND state IN (%s,%s,%s)", $wpdb->prefix . 'scalyn_recovery_queue', 'cancelled', 'cancelled', gmdate( 'Y-m-d H:i:s' ), $logical, 'pending', 'running', 'review' ) );
	}

	/**
	 * Prunes only terminal metadata, never an active payload before processing.
	 *
	 * @param string $cutoff UTC timestamp using existing log retention.
	 */
	public function prune( string $cutoff ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded metadata retention after payload erasure.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE payload='' AND state NOT IN (%s,%s,%s) AND created_at<%s LIMIT 500", $wpdb->prefix . 'scalyn_recovery_queue', 'pending', 'running', 'submitting', $cutoff ) );
	}
}
