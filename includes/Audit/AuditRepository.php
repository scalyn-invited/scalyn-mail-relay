<?php
/**
 * Append-only audit persistence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Audit;

defined( 'ABSPATH' ) || exit;

/** Owns audit appends, bounded safe reads and age-limited retention. */
final class AuditRepository {

	/**
	 * Appends a validated event with captured request attribution.
	 *
	 * @param AuditEvent $event Credential-free contract.
	 * @throws \RuntimeException When insertion fails, with no internal details.
	 */
	public function append( AuditEvent $event ): void {
		global $wpdb;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Repository-owned append-only audit INSERT.
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'scalyn_audit_logs',
				array(
					'user_id'       => $event->actor->user_id,
					'action'        => $event->action,
					'resource_type' => 'operation',
					'resource_id'   => $event->correlation_id,
					'ip_address'    => '',
					'user_agent'    => '',
					'metadata'      => wp_json_encode( $event->metadata() ),
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( 1 !== $inserted ) {
				throw new \RuntimeException( 'Audit persistence failed.' );
			}
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Audit persistence failed.' );
		}
	}

	/**
	 * Returns a bounded keyset page with only normalized, allowlisted fields.
	 *
	 * @param int $before Exclusive prior row ID, or zero for newest.
	 * @return array Rows and next cursor, with no raw metadata exposed.
	 * @throws \RuntimeException When reading fails.
	 */
	public function page( int $before = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'scalyn_audit_logs';
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table; cursor is prepared, fixed bounded limit.
			$sql = $wpdb->prepare( "SELECT id,user_id,action,resource_id,metadata,created_at FROM {$table} WHERE (%d = 0 OR id < %d) ORDER BY id DESC LIMIT 51", max( 0, $before ), max( 0, $before ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Repository-owned prepared keyset read.
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Audit history is unavailable.' );
			}
			$more = count( $rows ) > 50;
			$rows = array_slice( $rows, 0, 50 );
			$safe = array_map( array( $this, 'normalize_row' ), $rows );
			return array(
				'rows' => $safe,
				'next' => $more ? (int) end( $safe )['id'] : 0,
			);
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Audit history is unavailable.' );
		}
	}

	/**
	 * Projects stored data through the event contract, including legacy rows.
	 *
	 * @param array $row Stored row.
	 * @return array Safe display model; unexpected values are never passed through.
	 */
	private function normalize_row( array $row ): array {
		$safe = array(
			'id'             => max( 0, (int) ( $row['id'] ?? 0 ) ),
			'user_id'        => 0,
			'action'         => 'unknown',
			'outcome'        => 'unknown',
			'source'         => 'unknown',
			'correlation_id' => '',
			'changed_fields' => array(),
			'created_at'     => '',
		);
		$date = $row['created_at'] ?? '';
		if ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $date ) ) {
			$safe['created_at'] = $date;
		}
		try {
			$meta = json_decode( $row['metadata'] ?? '', true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $meta ) || ! in_array( $meta['version'] ?? null, array( 1, 2 ), true ) ) {
				return $safe;
			}
			$event                  = new AuditEvent( $row['action'] ?? '', $meta['outcome'] ?? '', $row['resource_id'] ?? '', $meta['changed_fields'] ?? array() );
			$safe['action']         = $event->action;
			$safe['outcome']        = $event->outcome;
			$safe['correlation_id'] = $event->correlation_id;
			$safe['changed_fields'] = $event->metadata()['changed_fields'];
			if ( 2 === $meta['version'] && in_array( $meta['source'] ?? '', AuditActor::SOURCES, true ) ) {
				$safe['source']  = $meta['source'];
				$safe['user_id'] = 'scheduled' === $meta['source'] ? 0 : max( 0, (int) ( $row['user_id'] ?? 0 ) );
			}
		} catch ( \Throwable $error ) {
			// Malformed historical records have a safe unknown display, never raw data.
			return $safe;
		}
		return $safe;
	}

	/**
	 * Removes at most 100 expired audit rows atomically; recent rows are retained.
	 *
	 * @param string $cutoff Exclusive site-time expiry boundary.
	 * @return int Deleted rows.
	 * @throws \RuntimeException On failed queries or incomplete deletes.
	 * @throws \InvalidArgumentException When the cutoff is not a valid timestamp.
	 */
	public function delete_expired_batch( string $cutoff ): int {
		global $wpdb;
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
			throw new \InvalidArgumentException( 'Invalid audit retention cutoff.' );
		}
		$table = $wpdb->prefix . 'scalyn_audit_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned retention transaction.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Audit retention failed.' );
		}
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table; prepared cutoff.
			$sql = $wpdb->prepare( "SELECT id FROM {$table} WHERE created_at < %s ORDER BY created_at ASC,id ASC LIMIT 100 FOR UPDATE", $cutoff );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded lock.
			$ids = $wpdb->get_col( $sql );
			if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Audit retention failed.' );
			}
			$deleted = 0;
			if ( array() !== $ids ) {
				$slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal identifiers and placeholders, prepared values.
				$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$slots}) AND created_at < %s", ...array_merge( $ids, array( $cutoff ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared retention delete.
				$deleted = $wpdb->query( $sql );
				if ( count( $ids ) !== $deleted ) {
					throw new \RuntimeException( 'Audit retention failed.' );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit retention batch.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Audit retention failed.' );
			}
			return $deleted;
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back failed retention.
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'Audit retention failed.' );
		}
	}
}
