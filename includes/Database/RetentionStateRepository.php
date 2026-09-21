<?php
/**
 * Cleanup coordination and non-sensitive status storage.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Uses a connection-owned lock: process death releases it without stale leases. */
final class RetentionStateRepository {

	public const OPTION_KEY = 'scalyn_mail_relay_retention_status';

	/** Returns a database/site scoped lock name under MySQL's 64-character limit. */
	private function lock_name(): string {
		global $wpdb;
		return 'scalyn_retention_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $wpdb->prefix );
	}

	/** Attempts a nonblocking lock. Fails closed on unsupported/error responses. */
	public function acquire(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned advisory lock cannot be cached.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name() ) );
	}

	/** Releases only the lock held by this database connection. */
	public function release(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned advisory lock cannot be cached.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
	}

	/**
	 * Saves only fixed states, timestamps and integer counts; never exceptions.
	 *
	 * @param array $status Internal cleanup status.
	 */
	public function save( array $status ): void {
		update_option( self::OPTION_KEY, $this->normalize( $status ), false );
	}

	/**
	 * Reads credential-free cleanup status.
	 *
	 * @return array Safe status.
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return $this->normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Applies an allowlist on both reads and writes.
	 *
	 * @param array $status Raw state.
	 * @return array Safe state.
	 */
	private function normalize( array $status ): array {
		$safe = array( 'state' => in_array( $status['state'] ?? '', array( 'running', 'complete', 'more_pending', 'failed' ), true ) ? $status['state'] : 'never' );
		foreach ( array( 'started_at', 'finished_at', 'last_success_at', 'mail_logs', 'timeline_events', 'diagnostic_rows', 'health_scores' ) as $key ) {
			$safe[ $key ] = isset( $status[ $key ] ) && is_numeric( $status[ $key ] ) ? max( 0, (int) $status[ $key ] ) : 0;
		}
		return $safe;
	}
}
