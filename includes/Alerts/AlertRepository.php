<?php
/**
 * Incident and notification persistence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Alerts;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository owns transactional operational data; caching would break locking and queue claims.

/** All writers run under one site/connection lock; incident/outbox changes are atomic. */
final class AlertRepository {

	public const STATUS_OPTION = 'scalyn_mail_relay_alert_status';

	/** Reads bounded execution status without arbitrary stored metadata.
	 *
	 * @return array Safe state and epoch timestamp.
	 */
	public function status(): array {
		$row = get_option( self::STATUS_OPTION, array() );
		return array(
			'state' => is_array( $row ) && in_array( $row['state'] ?? '', array( 'running', 'completed', 'failed' ), true ) ? $row['state'] : 'never',
			'at'    => is_array( $row ) && is_int( $row['at'] ?? null ) ? max( 0, $row['at'] ) : 0,
		);
	}

	/** Stores fixed control metadata, not history or secrets.
	 *
	 * @param string $state Internal execution state.
	 */
	private function record_status( string $state ): void {
		try {
			update_option(
				self::STATUS_OPTION,
				array(
					'state' => $state,
					'at'    => time(),
				),
				false
			);
		} catch ( \Throwable $error ) {
			// A control-status observer must not expose exceptions or repeat committed work.
			return;
		}
	}

	/** Process-local recursive acquisition guard.
	 *
	 * @var bool
	 */
	private static bool $held = false;

	/**
	 * Runs work under exclusion, refusing unsupported schema/engines.
	 *
	 * @param callable $work Bounded work.
	 * @return bool Whether work completed.
	 */
	public function exclusive( callable $work ): bool {
		global $wpdb;
		if ( self::$held || version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0' ), '0.2.0', '<' ) ) {
			return false;
		}
		$key   = 'scalyn_alerts_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $wpdb->prefix );
		$owned = false;
		try {
			$owned = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $key ) );
			if ( ! $owned ) {
				return false;
			}
			self::$held = true;
			$this->record_status( 'running' );
			$engines = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s) AND ENGINE = 'InnoDB'", $wpdb->prefix . 'scalyn_alerts', $wpdb->prefix . 'scalyn_alert_notifications' ) );
			if ( 2 !== (int) $engines ) {
				$this->record_status( 'failed' );
				return false;
			}
			$work();
			$this->record_status( 'completed' );
			return true;
		} catch ( \Throwable $error ) {
			if ( $owned ) {
				$this->record_status( 'failed' );
			}
			return false;
		} finally {
			if ( $owned ) {
				try {
					if ( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ) ) {
						self::$held = false;
					}
				} catch ( \Throwable $error ) {
					// Uncertain release fails closed for this process.
					self::$held = true;
				}
			}
		}
	}

	/**
	 * Atomically transitions an incident and queues its one notification.
	 *
	 * @param string    $type Fixed rule.
	 * @param bool|null $condition True opens, false resolves, null holds.
	 * @param bool      $enabled Channel opt-in.
	 * @param int       $now Epoch.
	 * @return array|null Committed transition for audit.
	 * @throws \RuntimeException On persistence failure.
	 */
	public function transition( string $type, ?bool $condition, bool $enabled, int $now ): ?array {
		global $wpdb;
		if ( ! self::$held || ! in_array( $type, IncidentRules::TYPES, true ) || null === $condition ) {
			return null;
		}
		$alerts = $wpdb->prefix . 'scalyn_alerts';
		$outbox = $wpdb->prefix . 'scalyn_alert_notifications';
		$this->execute( 'START TRANSACTION' );
		try {
			$rows   = $this->rows( $wpdb->prepare( 'SELECT alert_uuid,status FROM %i WHERE alert_type = %s ORDER BY id DESC LIMIT 1 FOR UPDATE', $alerts, $type ) );
			$active = 'active' === ( $rows[0]['status'] ?? '' );
			if ( $active === $condition ) {
				$this->execute( 'COMMIT' );
				return null;
			}
			$uuid  = $active ? $rows[0]['alert_uuid'] : wp_generate_uuid4();
			$event = $active ? 'recovered' : 'opened';
			$at    = gmdate( 'Y-m-d H:i:s', $now );
			$due   = $now;
			if ( $active ) {
				$this->execute( $wpdb->prepare( "UPDATE %i SET status = 'resolved',resolved_at = %s WHERE alert_uuid = %s AND status = 'active'", $alerts, $at, $uuid ), 1 );
				$this->execute( $wpdb->prepare( "UPDATE %i SET status = 'cancelled' WHERE alert_uuid = %s AND status IN ('pending','sending')", $outbox, $uuid ) );
			} else {
				$prior = $this->rows( $wpdb->prepare( "SELECT n.created_at,n.last_attempt_at FROM %i n INNER JOIN %i a ON a.alert_uuid = n.alert_uuid WHERE a.alert_type = %s AND n.event = 'opened' ORDER BY n.id DESC LIMIT 1", $outbox, $alerts, $type ) );
				if ( $prior ) {
					$previous = max( ObservationRepository::epoch( $prior[0]['created_at'], new \DateTimeZone( 'UTC' ) ), ObservationRepository::epoch( $prior[0]['last_attempt_at'] ?? '', new \DateTimeZone( 'UTC' ) ) );
					$due      = max( $now, $previous + 900 );
				}
				$this->insert(
					$alerts,
					array(
						'alert_uuid'       => $uuid,
						'alert_type'       => $type,
						'severity'         => 'warning',
						'title'            => $type,
						'message'          => '',
						'status'           => 'active',
						'related_resource' => '',
						'created_at'       => $at,
					)
				);
			}
			$this->insert(
				$outbox,
				array(
					'notification_uuid' => wp_generate_uuid4(),
					'alert_uuid'        => $uuid,
					'event'             => $event,
					'status'            => $enabled ? 'pending' : 'skipped',
					'attempts'          => 0,
					'response_code'     => 0,
					'next_attempt_at'   => gmdate( 'Y-m-d H:i:s', $due ),
					'created_at'        => $at,
				)
			);
			$this->execute( 'COMMIT' );
			return array(
				'uuid'  => $uuid,
				'event' => $active ? 'resolved' : 'opened',
			);
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'Alert persistence is unavailable.' );
		}
	}

	/**
	 * Claims a bounded due batch, recording attempts before network I/O.
	 *
	 * @param int $now Epoch.
	 * @return array Jobs.
	 */
	public function due( int $now ): array {
		global $wpdb;
		if ( ! self::$held ) {
			return array();
		}
		return $this->rows( $wpdb->prepare( "SELECT n.notification_uuid,n.alert_uuid,n.event,n.attempts,a.alert_type FROM %i n INNER JOIN %i a ON a.alert_uuid = n.alert_uuid WHERE n.status IN ('pending','sending') AND n.next_attempt_at <= %s ORDER BY n.next_attempt_at,n.id LIMIT 5", $wpdb->prefix . 'scalyn_alert_notifications', $wpdb->prefix . 'scalyn_alerts', gmdate( 'Y-m-d H:i:s', $now ) ) );
	}

	/**
	 * Persist queue progress. Only the lock owner can write.
	 *
	 * @param array  $job Claimed job.
	 * @param string $status Fixed queue state.
	 * @param int    $attempts Attempts consumed.
	 * @param int    $code Safe response code.
	 * @param int    $now Epoch.
	 * @param int    $delay Retry delay seconds.
	 * @throws \RuntimeException On write failure.
	 */
	public function progress( array $job, string $status, int $attempts, int $code, int $now, int $delay = 0 ): void {
		global $wpdb;
		if ( ! self::$held || ! in_array( $status, array( 'sending', 'pending', 'sent', 'failed', 'skipped' ), true ) ) {
			throw new \RuntimeException( 'Invalid notification update.' );
		}
		$this->execute( $wpdb->prepare( 'UPDATE %i SET status=%s,attempts=%d,response_code=%d,last_attempt_at=%s,next_attempt_at=%s WHERE notification_uuid=%s', $wpdb->prefix . 'scalyn_alert_notifications', $status, min( 3, max( 0, $attempts ) ), $code >= 100 && $code <= 599 ? $code : 0, gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + $delay ), $job['notification_uuid'] ), 1 );
	}

	/**
	 * Deletes at most 100 expired resolved incidents and their outbox atomically.
	 *
	 * @param int $cutoff UTC epoch.
	 * @throws \RuntimeException On persistence failure.
	 */
	public function retain( int $cutoff ): void {
		global $wpdb;
		if ( ! self::$held ) {
			return;
		}
		$this->execute( 'START TRANSACTION' );
		try {
			$rows = $this->rows( $wpdb->prepare( "SELECT alert_uuid FROM %i WHERE status='resolved' AND resolved_at < %s ORDER BY resolved_at,id LIMIT 100 FOR UPDATE", $wpdb->prefix . 'scalyn_alerts', gmdate( 'Y-m-d H:i:s', $cutoff ) ) );
			foreach ( $rows as $row ) {
				$this->execute( $wpdb->prepare( 'DELETE FROM %i WHERE alert_uuid=%s', $wpdb->prefix . 'scalyn_alert_notifications', $row['alert_uuid'] ) );
				$this->execute( $wpdb->prepare( 'DELETE FROM %i WHERE alert_uuid=%s', $wpdb->prefix . 'scalyn_alerts', $row['alert_uuid'] ), 1 );
			}
			$this->execute( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'Alert retention is unavailable.' );
		}
	}

	/**
	 * Safe bounded history projection; legacy free text is never returned.
	 *
	 * @param int $before Exclusive row cursor.
	 * @return array Rows and next cursor.
	 */
	public function page( int $before = 0 ): array {
		global $wpdb;
		$rows = $this->rows( $wpdb->prepare( 'SELECT id,alert_uuid,alert_type,status,created_at,resolved_at FROM %i WHERE (%d=0 OR id<%d) ORDER BY id DESC LIMIT 51', $wpdb->prefix . 'scalyn_alerts', max( 0, $before ), max( 0, $before ) ) );
		$more = count( $rows ) > 50;
		$rows = array_slice( $rows, 0, 50 );
		foreach ( $rows as &$row ) {
			$row['id']         = max( 0, (int) $row['id'] );
			$row['alert_uuid'] = preg_match( '/^[0-9a-f-]{36}$/D', (string) $row['alert_uuid'] ) ? $row['alert_uuid'] : '';
			$row['alert_type'] = in_array( $row['alert_type'], IncidentRules::TYPES, true ) ? $row['alert_type'] : 'unknown';
			$row['status']     = in_array( $row['status'], array( 'active', 'resolved' ), true ) ? $row['status'] : 'unknown';
			foreach ( array( 'created_at', 'resolved_at' ) as $key ) {
				$row[ $key ] = is_string( $row[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $row[ $key ] ) ? $row[ $key ] : '';
			}
			$jobs                 = $this->rows( $wpdb->prepare( 'SELECT event,status,attempts,response_code FROM %i WHERE alert_uuid=%s ORDER BY id LIMIT 2', $wpdb->prefix . 'scalyn_alert_notifications', $row['alert_uuid'] ) );
			$row['notifications'] = array_map(
				static fn( $job ) => array(
					'event'         => in_array( $job['event'], array( 'opened', 'recovered' ), true ) ? $job['event'] : 'unknown',
					'status'        => in_array( $job['status'], array( 'pending', 'sending', 'sent', 'failed', 'skipped', 'cancelled' ), true ) ? $job['status'] : 'unknown',
					'attempts'      => min( 3, max( 0, (int) $job['attempts'] ) ),
					'response_code' => (int) $job['response_code'] >= 100 && (int) $job['response_code'] <= 599 ? (int) $job['response_code'] : 0,
				),
				$jobs
			);
		}
		unset( $row );
		return array(
			'rows' => $rows,
			'next' => $more ? (int) end( $rows )['id'] : 0,
		);
	}

	/**
	 * Executes prepared SQL and enforces success.
	 *
	 * @param string   $sql Prepared SQL.
	 * @param int|null $expected Optional affected count.
	 * @throws \RuntimeException On write failure.
	 */
	private function execute( string $sql, ?int $expected = null ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by repository callers.
		$result = $wpdb->query( $sql );
		if ( false === $result || ( null !== $expected && $expected !== $result ) ) {
			throw new \RuntimeException( 'Alert persistence is unavailable.' );
		}
	}

	/**
	 * Reads prepared SQL, distinguishing failure from no evidence.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array Rows.
	 * @throws \RuntimeException On read failure.
	 */
	private function rows( string $sql ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by repository callers.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Alert history is unavailable.' );
		}
		return $rows;
	}

	/**
	 * Inserts internal allowlisted data.
	 *
	 * @param string $table Owned table.
	 * @param array  $data Fixed record.
	 * @throws \RuntimeException On insert failure.
	 */
	private function insert( string $table, array $data ): void {
		global $wpdb;
		if ( 1 !== $wpdb->insert( $table, $data ) ) {
			throw new \RuntimeException( 'Alert persistence is unavailable.' );
		}
	}
}
