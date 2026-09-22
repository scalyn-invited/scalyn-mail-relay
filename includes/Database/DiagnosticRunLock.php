<?php
/**
 * Shared diagnostic exclusion.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Connection-owned lock released on process death. */
final class DiagnosticRunLock {

	/** Process-local guard against recursive GET_LOCK on the same connection.
	 *
	 * @var array<string, bool>
	 */
	private static array $held = array();

	/** Owned key, or null when this instance did not acquire a lock.
	 *
	 * @var string|null
	 */
	private ?string $owned = null;

	/** Returns a database and site-specific lock key. */
	private function name(): string {
		global $wpdb;
		return 'scalyn_diagnostics_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $wpdb->prefix );
	}

	/** Attempts immediate acquisition; database errors fail closed. */
	public function acquire(): bool {
		global $wpdb;
		$key = $this->name();
		if ( isset( self::$held[ $key ] ) ) {
			return false;
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned lock cannot be cached.
			$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) );
			if ( $acquired ) {
				self::$held[ $key ] = true;
				$this->owned        = $key;
			}
			return $acquired;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Releases the current connection's lock. */
	public function release(): void {
		global $wpdb;
		if ( null === $this->owned ) {
			return;
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned lock cannot be cached.
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->owned ) );
			if ( '1' === (string) $released ) {
				unset( self::$held[ $this->owned ] );
			}
		} catch ( \Throwable $error ) {
			// Fail closed for this process if release is uncertain.
			$this->owned = null;
		}
		$this->owned = null;
	}
}
