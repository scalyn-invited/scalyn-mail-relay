<?php
/**
 * Bounded retention cleanup for diagnostics and health-score history.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Owns transactional deletion of expired diagnostic evidence. */
final class DiagnosticRetentionRepository {

	/** Maximum run groups and health snapshots selected per call. */
	public const MAX_BATCH_SIZE = 250;

	/** Default selection limit for each history type. */
	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Deletes one bounded batch from diagnostic and health-score history.
	 *
	 * A diagnostic run expires only when every row sharing its diagnostic UUID is
	 * older than the cutoff. Health snapshots have no run correlation in the
	 * current schema and therefore expire independently by their own created_at.
	 *
	 * @param string $cutoff Exclusive boundary in WordPress site time (Y-m-d H:i:s).
	 * @param int    $limit  Requested limit per history type; clamped to 1..250.
	 * @throws \RuntimeException When transaction or deletion work fails.
	 */
	public function delete_expired_batch(
		string $cutoff,
		int $limit = self::DEFAULT_BATCH_SIZE
	): DiagnosticRetentionCleanupResult {
		global $wpdb;

		$this->assert_valid_cutoff( $cutoff );
		$limit            = min( max( 1, $limit ), self::MAX_BATCH_SIZE );
		$diagnostic_table = $wpdb->prefix . 'scalyn_diagnostics';
		$health_table     = $wpdb->prefix . 'scalyn_health_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned transaction for atomic history deletion.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Diagnostic retention transaction could not start.' );
		}

		try {
			// MAX(created_at) makes the whole diagnostic UUID group authoritative:
			// a run with any row at or after the cutoff is retained in full.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is internal; cutoff and limit are prepared.
			$run_sql = $wpdb->prepare( "SELECT diagnostic_uuid FROM {$diagnostic_table} GROUP BY diagnostic_uuid HAVING MAX(created_at) < %s ORDER BY MIN(created_at) ASC, MIN(id) ASC LIMIT %d", $cutoff, $limit );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded group selection.
			$run_uuids = array_values( array_unique( array_filter( array_map( 'strval', (array) $wpdb->get_col( $run_sql ) ) ) ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Diagnostic retention selection failed.' );
			}

			$locked_ids = array();
			if ( array() !== $run_uuids ) {
				$run_placeholders = implode( ', ', array_fill( 0, count( $run_uuids ), '%s' ) );
				// Lock every row in each selected group before deletion. Diagnostic UUIDs
				// are immutable per-run identifiers and must never be reused by callers.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table/placeholders are internal; UUIDs are prepared.
				$lock_sql = $wpdb->prepare( "SELECT id FROM {$diagnostic_table} WHERE diagnostic_uuid IN ({$run_placeholders}) FOR UPDATE", ...$run_uuids );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Locks only the bounded selected run groups.
				$locked_ids = array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $lock_sql ) ) ) );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new \RuntimeException( 'Diagnostic retention group lock failed.' );
				}
				$locked_ids = array_values( array_filter( $locked_ids, static fn( int $id ): bool => $id > 0 ) );
				if ( count( $locked_ids ) < count( $run_uuids ) ) {
					throw new \RuntimeException( 'Diagnostic retention group lock failed.' );
				}
			}

			// Health snapshots are not correlated to diagnostic_uuid in schema 0.1.0.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is internal; cutoff and limit are prepared.
			$health_sql = $wpdb->prepare( "SELECT id FROM {$health_table} WHERE created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d FOR UPDATE", $cutoff, $limit );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded snapshot selection.
			$health_ids = array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $health_sql ) ) ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Health retention selection failed.' );
			}
			$health_ids = array_values( array_filter( $health_ids, static fn( int $id ): bool => $id > 0 ) );

			$diagnostic_deleted = 0;
			if ( array() !== $run_uuids ) {
				$run_placeholders = implode( ', ', array_fill( 0, count( $run_uuids ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table/placeholders are internal; UUIDs are prepared.
				$delete_runs_sql = $wpdb->prepare( "DELETE FROM {$diagnostic_table} WHERE diagnostic_uuid IN ({$run_placeholders})", ...$run_uuids );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Repository-owned bounded grouped delete.
				$diagnostic_deleted = $wpdb->query( $delete_runs_sql );
				if ( false === $diagnostic_deleted || count( $locked_ids ) !== $diagnostic_deleted ) {
					throw new \RuntimeException( 'Diagnostic retention delete failed.' );
				}
			}

			$health_deleted = 0;
			if ( array() !== $health_ids ) {
				$health_placeholders = implode( ', ', array_fill( 0, count( $health_ids ), '%d' ) );
				// Repeat the cutoff defensively so a stale snapshot ID cannot remove new evidence.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table/placeholders are internal; IDs and cutoff are prepared.
				$delete_health_sql = $wpdb->prepare( "DELETE FROM {$health_table} WHERE id IN ({$health_placeholders}) AND created_at < %s", ...array_merge( $health_ids, array( $cutoff ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Repository-owned bounded snapshot delete.
				$health_deleted = $wpdb->query( $delete_health_sql );
				if ( false === $health_deleted || count( $health_ids ) !== $health_deleted ) {
					throw new \RuntimeException( 'Health score retention delete failed.' );
				}
			}

			$this->commit( $wpdb );

			return new DiagnosticRetentionCleanupResult(
				$cutoff,
				count( $run_uuids ),
				(int) $diagnostic_deleted,
				count( $health_ids ),
				(int) $health_deleted
			);
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back repository-owned retention work.
			$wpdb->query( 'ROLLBACK' );
			if ( $error instanceof \RuntimeException ) {
				switch ( $error->getMessage() ) {
					case 'Diagnostic retention transaction could not commit.':
						throw new \RuntimeException( 'Diagnostic retention transaction could not commit.' );
					case 'Diagnostic retention group lock failed.':
						throw new \RuntimeException( 'Diagnostic retention group lock failed.' );
					case 'Diagnostic retention delete failed.':
						throw new \RuntimeException( 'Diagnostic retention delete failed.' );
					case 'Health score retention delete failed.':
						throw new \RuntimeException( 'Health score retention delete failed.' );
				}
			}
			throw new \RuntimeException( 'Diagnostic retention cleanup failed.' );
		}
	}

	/**
	 * Validates an exact calendar timestamp.
	 *
	 * @param string $cutoff Proposed site-time cutoff.
	 * @throws \InvalidArgumentException When the cutoff is invalid.
	 */
	private function assert_valid_cutoff( string $cutoff ): void {
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff );
		$errors = \DateTimeImmutable::getLastErrors();

		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
			throw new \InvalidArgumentException( 'Diagnostic retention cutoff must use a valid Y-m-d H:i:s site-time value.' );
		}
	}

	/**
	 * Commits the current transaction.
	 *
	 * @param object $wpdb WordPress database connection.
	 * @throws \RuntimeException When commit fails.
	 */
	private function commit( object $wpdb ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit repository-owned retention work.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new \RuntimeException( 'Diagnostic retention transaction could not commit.' );
		}
	}
}
