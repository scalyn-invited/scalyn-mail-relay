<?php
/**
 * Bounded cleanup of delivery associations and pseudonymous evidence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Expiry is anchored to attempt creation, never to late callback receipt. */
final class DeliveryRetentionRepository {

	/** Maximum attempts per cleanup transaction. */
	public const MAX_BATCH_SIZE = 250;

	/**
	 * Removes expired associations even when a mail log was never successfully stored.
	 *
	 * @param string $cutoff Exclusive UTC creation cutoff.
	 * @param int    $limit Bounded batch size.
	 * @return int Number of selected attempts; a full batch may have more pending.
	 * @throws \RuntimeException When validation or atomic cleanup fails.
	 */
	public function delete_expired_batch( string $cutoff, int $limit = 100 ): int {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$started    = false;
		try {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff, new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
				throw new \RuntimeException();
			}
			$limit = min( self::MAX_BATCH_SIZE, max( 1, $limit ) );
			$this->assert_transactional();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned cleanup transaction.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException();
			}
			$started = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Same attempt lock as the event writer prevents callback resurrection.
			$uuids = $wpdb->get_col( $wpdb->prepare( 'SELECT message_uuid FROM %i WHERE created_at < %s ORDER BY created_at,message_uuid LIMIT %d FOR UPDATE', $wpdb->prefix . 'scalyn_delivery_attempts', $cutoff, $limit ) );
			if ( ! is_array( $uuids ) || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			$this->delete_for_messages( $uuids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Return success only after durable aggregate deletion.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException();
			}
			return count( $uuids );
		} catch ( \Throwable $error ) {
			if ( $started ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Retain all evidence if any deletion fails.
				$wpdb->query( 'ROLLBACK' );
			}
			throw new \RuntimeException( 'Delivery retention cleanup failed.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Deletes delivery children in the CALLER'S transaction before its mail-log delete.
	 * Does not begin/commit a transaction. Caller must roll back on every exception.
	 *
	 * @param array $uuids Explicit bounded parent UUIDs selected for deletion.
	 * @return int Number of deleted delivery timeline projections.
	 * @throws \RuntimeException When validation, locking or deletion fails.
	 */
	public function delete_for_messages( array $uuids ): int {
		global $wpdb;
		if ( ! $uuids ) {
			return 0;
		}
		$suppressed = $wpdb->suppress_errors( true );
		try {
			if ( count( $uuids ) > self::MAX_BATCH_SIZE ) {
				throw new \RuntimeException();
			}
			foreach ( $uuids as $uuid ) {
				if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid ) ) {
					throw new \RuntimeException();
				}
			}
			$uuids = array_values( array_unique( $uuids ) );
			sort( $uuids );
			$this->assert_transactional();
			$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only placeholder syntax is interpolated; all values and table names are prepared.
			$locked = $wpdb->get_col( $wpdb->prepare( "SELECT message_uuid FROM %i WHERE message_uuid IN ({$placeholders}) ORDER BY message_uuid FOR UPDATE", ...array_merge( array( $wpdb->prefix . 'scalyn_delivery_attempts' ), $uuids ) ) );
			if ( ! is_array( $locked ) || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			$projections = 0;
			foreach ( array( 'scalyn_delivery_events', 'scalyn_delivery_recipients', 'scalyn_mail_timeline', 'scalyn_delivery_attempts' ) as $suffix ) {
				// Only delivery projections are removed here; the mail repository owns other lifecycle history.
				$condition = 'scalyn_mail_timeline' === $suffix ? " AND event_type='delivery_evidence'" : '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed condition and generated placeholders; identifiers and UUIDs use prepare.
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE message_uuid IN ({$placeholders}){$condition}", ...array_merge( array( $wpdb->prefix . $suffix ), $uuids ) ) );
				if ( false === $deleted ) {
					throw new \RuntimeException();
				}
				if ( 'scalyn_mail_timeline' === $suffix ) {
					$projections = (int) $deleted;
				}
			}
			return $projections;
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Delivery retention cleanup failed.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Verifies atomicity without assuming that an old timeline table uses InnoDB.
	 *
	 * @throws \RuntimeException When required transactional storage is unavailable.
	 */
	private function assert_transactional(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh storage readiness before destructive work.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN (%s,%s,%s,%s)", $wpdb->prefix . 'scalyn_delivery_attempts', $wpdb->prefix . 'scalyn_delivery_recipients', $wpdb->prefix . 'scalyn_delivery_events', $wpdb->prefix . 'scalyn_mail_timeline' ) );
		if ( '4' !== (string) $count ) {
			throw new \RuntimeException();
		}
	}
}
