<?php
/**
 * Minimal, revision-keyed connection evidence persistence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** One latest check per revision/provider; no messages, headers or credentials. */
final class ConnectionEvidenceRepository {
	/**
	 * Persists a result; checks that started earlier cannot replace newer checks.
	 *
	 * @param string $revision Captured revision UUID.
	 * @param string $provider Provider ID.
	 * @param string $status passed, failed or unknown.
	 * @param string $started UTC start timestamp with microseconds.
	 * @param string $checked UTC completion timestamp with microseconds.
	 * @throws \RuntimeException When storage is unavailable.
	 * @throws \InvalidArgumentException When evidence is invalid.
	 */
	public function record( string $revision, string $provider, string $status, string $started, string $checked ): void {
		global $wpdb;
		if ( ! self::valid_scope( $revision, $provider ) || ! in_array( $status, array( 'passed', 'failed', 'unknown' ), true )
			|| ! self::valid_time( $started ) || ! self::valid_time( $checked ) || $checked < $started ) {
			throw new \InvalidArgumentException( 'Invalid connection evidence.' );
		}
		if ( ! self::ready() ) {
			throw new \RuntimeException( 'Connection evidence unavailable.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic latest-started-wins upsert; no read/write race.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (configuration_id,provider,status,started_at,checked_at) VALUES (%s,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE status=IF(VALUES(started_at)>started_at,VALUES(status),status),
			checked_at=IF(VALUES(started_at)>started_at,VALUES(checked_at),checked_at),
			started_at=GREATEST(started_at,VALUES(started_at))',
				$wpdb->prefix . 'scalyn_connection_evidence',
				$revision,
				$provider,
				$status,
				$started,
				$checked
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Connection evidence unavailable.' );
		}
	}

	/**
	 * Reads only an exact revision/provider, never falls back to older settings.
	 *
	 * @param string $revision Current revision.
	 * @param string $provider Current provider.
	 * @return array|null Allowlisted evidence or unavailable.
	 */
	public function find( string $revision, string $provider ): ?array {
		global $wpdb;
		if ( ! self::ready() || ! self::valid_scope( $revision, $provider ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded exact-key operational read.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,started_at,checked_at FROM %i WHERE configuration_id=%s AND provider=%s LIMIT 1', $wpdb->prefix . 'scalyn_connection_evidence', $revision, $provider ), ARRAY_A );
		if ( ! is_array( $row ) || ! empty( $wpdb->last_error ) || ! in_array( $row['status'] ?? '', array( 'passed', 'failed', 'unknown' ), true )
			|| ! self::valid_time( $row['started_at'] ?? '' ) || ! self::valid_time( $row['checked_at'] ?? '' ) || $row['checked_at'] < $row['started_at'] ) {
			return null;
		}
		return array_intersect_key( $row, array_flip( array( 'status', 'started_at', 'checked_at' ) ) );
	}

	/**
	 * Deletes one bounded batch using the shared log-retention policy.
	 *
	 * @param string $cutoff UTC cutoff, with microseconds.
	 * @return int Deleted rows.
	 * @throws \RuntimeException When cleanup fails.
	 */
	public function delete_expired( string $cutoff ): int {
		global $wpdb;
		if ( ! self::ready() || ! self::valid_time( $cutoff ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixed table and bounded retention deletion.
		$count = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE checked_at < %s ORDER BY checked_at LIMIT 100', $wpdb->prefix . 'scalyn_connection_evidence', $cutoff ) );
		if ( false === $count ) {
			throw new \RuntimeException( 'Connection evidence cleanup unavailable.' );
		}
		return (int) $count;
	}

	/** Schema readiness never triggers a migration during verification. */
	private static function ready(): bool {
		return version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.9.0', '>=' );
	}

	/**
	 * Validates opaque scope without accepting arbitrary metadata.
	 *
	 * @param string $revision Revision UUID.
	 * @param string $provider Provider ID.
	 */
	private static function valid_scope( string $revision, string $provider ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $revision )
			&& 1 === preg_match( '/^[a-z0-9_-]{1,100}$/D', $provider );
	}

	/**
	 * Validates canonical UTC timestamps, rejecting invalid dates.
	 *
	 * @param string $value Timestamp.
	 */
	private static function valid_time( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s.u' ) === $value;
	}
}
