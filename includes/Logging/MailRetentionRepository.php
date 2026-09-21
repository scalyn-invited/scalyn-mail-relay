<?php
/**
 * Bounded retention cleanup for mail logs and their timeline events.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Owns cross-table deletion for the mail-log aggregate.
 *
 * The mail log's created_at value is the authoritative expiry timestamp. A row
 * expires only when created_at is strictly earlier than the supplied site-time
 * cutoff. Related timeline events are deleted as part of the same transaction,
 * even when their individual timestamps are newer than the parent log.
 */
final class MailRetentionRepository {

	/** Maximum mail-log aggregates deleted by one call. */
	public const MAX_BATCH_SIZE = 250;

	/** Default batch size for callers that do not specify one. */
	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Deletes one oldest-first batch of expired mail-log aggregates.
	 *
	 * @param string $cutoff Exclusive expiry boundary in WordPress site time (Y-m-d H:i:s).
	 * @param int    $limit  Requested message limit; clamped to 1..MAX_BATCH_SIZE.
	 * @throws \RuntimeException When transaction or deletion work fails.
	 */
	public function delete_expired_batch(
		string $cutoff,
		int $limit = self::DEFAULT_BATCH_SIZE
	): MailRetentionCleanupResult {
		global $wpdb;

		$this->assert_valid_cutoff( $cutoff );
		$limit          = min( max( 1, $limit ), self::MAX_BATCH_SIZE );
		$mail_table     = $wpdb->prefix . 'scalyn_mail_logs';
		$timeline_table = $wpdb->prefix . 'scalyn_mail_timeline';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned transaction for atomic aggregate deletion.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Mail retention transaction could not start.' );
		}

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix; cutoff and limit are prepared.
			$selection_sql = $wpdb->prepare( "SELECT message_uuid FROM {$mail_table} WHERE created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d FOR UPDATE", $cutoff, $limit );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded retention selection.
			$uuids = array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col( $selection_sql ) ) ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Mail retention selection failed.' );
			}

			if ( array() === $uuids ) {
				$this->commit( $wpdb );
				return new MailRetentionCleanupResult( $cutoff, 0, 0, 0 );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $uuids ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and placeholder list are internal; UUID values are prepared.
			$timeline_sql = $wpdb->prepare( "DELETE FROM {$timeline_table} WHERE message_uuid IN ({$placeholders})", ...$uuids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Repository-owned bounded delete.
			$timeline_deleted = $wpdb->query( $timeline_sql );
			if ( false === $timeline_deleted ) {
				throw new \RuntimeException( 'Mail timeline retention delete failed.' );
			}

			// Repeat the cutoff defensively: only expired parents may be removed even
			// if a caller or future implementation supplies a stale candidate set.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and placeholder list are internal; UUID values and cutoff are prepared.
			$mail_sql = $wpdb->prepare( "DELETE FROM {$mail_table} WHERE message_uuid IN ({$placeholders}) AND created_at < %s", ...array_merge( $uuids, array( $cutoff ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Repository-owned bounded delete.
			$mail_deleted = $wpdb->query( $mail_sql );
			if ( false === $mail_deleted || count( $uuids ) !== $mail_deleted ) {
				throw new \RuntimeException( 'Mail log retention delete failed.' );
			}

			$this->commit( $wpdb );

			return new MailRetentionCleanupResult(
				$cutoff,
				count( $uuids ),
				(int) $timeline_deleted,
				(int) $mail_deleted
			);
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back the repository-owned retention transaction.
			$wpdb->query( 'ROLLBACK' );
			if ( $error instanceof \RuntimeException ) {
				switch ( $error->getMessage() ) {
					case 'Mail retention transaction could not commit.':
						throw new \RuntimeException( 'Mail retention transaction could not commit.' );
					case 'Mail timeline retention delete failed.':
						throw new \RuntimeException( 'Mail timeline retention delete failed.' );
					case 'Mail log retention delete failed.':
						throw new \RuntimeException( 'Mail log retention delete failed.' );
				}
			}
			throw new \RuntimeException( 'Mail retention cleanup failed.' );
		}
	}

	/**
	 * Validates an exact calendar timestamp without normalizing invalid dates.
	 *
	 * @param string $cutoff Proposed site-time cutoff.
	 * @throws \InvalidArgumentException When the value is not an exact valid timestamp.
	 */
	private function assert_valid_cutoff( string $cutoff ): void {
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff );
		$errors = \DateTimeImmutable::getLastErrors();

		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
			throw new \InvalidArgumentException( 'Mail retention cutoff must use a valid Y-m-d H:i:s site-time value.' );
		}
	}

	/**
	 * Commits the active cleanup transaction.
	 *
	 * @param object $wpdb WordPress database connection.
	 * @throws \RuntimeException When the commit fails.
	 */
	private function commit( object $wpdb ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit the repository-owned retention transaction.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new \RuntimeException( 'Mail retention transaction could not commit.' );
		}
	}
}
