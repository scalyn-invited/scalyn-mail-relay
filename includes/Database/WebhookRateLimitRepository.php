<?php
/**
 * Database-serialized budget for configured webhook sources.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** One non-autoloaded option per configured source; no recipient or credential data. */
final class WebhookRateLimitRepository {

	/** Reentrant calls on a shared connection must not reacquire its advisory lock.
	 *
	 * @var array<string, bool>
	 */
	private static array $held = array();

	/**
	 * Consumes one of 60 requests per fixed UTC minute for an authenticated source.
	 * The caller must establish that this is a configured source, never a payload UUID.
	 * Reads bypass option caches; GET_LOCK serializes workers using the same database.
	 *
	 * @param string $source_id Trusted integration UUID.
	 * @return bool False means the budget is exhausted, not a storage error.
	 * @throws \RuntimeException When safe serialization or persistence is unavailable.
	 */
	public function consume( string $source_id ): bool {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $source_id ) ) {
			throw new \RuntimeException( 'Webhook budget is unavailable.' );
		}
		$name = 'scalyn_webhook_budget_' . strtolower( $source_id );
		$lock = 'scalyn_wh_' . hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $wpdb->prefix . ':' . $name );
		$lock = substr( $lock, 0, 64 );
		if ( isset( self::$held[ $lock ] ) ) {
			throw new \RuntimeException( 'Webhook budget is unavailable.' );
		}
		$owned   = false;
		$failed  = false;
		$allowed = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached connection-owned serialization.
			$owned = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
			if ( ! $owned || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			self::$held[ $lock ] = true;
			// Database UTC time gives all workers the same window; do not trust request timestamps.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached database clock.
			$now = $wpdb->get_var( 'SELECT FLOOR(UNIX_TIMESTAMP() / 60)' );
			if ( ! is_numeric( $now ) || (int) $now < 1 || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private option always read fresh under the lock.
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			$state = null === $raw ? array(
				'window' => (int) $now,
				'count'  => 0,
			) : json_decode( $raw, true );
			if ( ! is_array( $state ) || ! is_int( $state['window'] ?? null ) || ! is_int( $state['count'] ?? null ) || 0 > $state['count'] || 60 < $state['count'] || 1 > $state['window'] || $state['window'] > (int) $now ) {
				throw new \RuntimeException();
			}
			if ( (int) $now !== $state['window'] ) {
				$state = array(
					'window' => (int) $now,
					'count'  => 0,
				);
			}
			$allowed = 60 > $state['count'];
			if ( $allowed ) {
				++$state['count'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Locked private non-autoloaded option, never read through the option cache.
				$saved = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', $wpdb->options, $name, wp_json_encode( $state ), 'no' ) );
				if ( false === $saved || ! empty( $wpdb->last_error ) ) {
					throw new \RuntimeException();
				}
			}
		} catch ( \Throwable $error ) {
			$failed = true;
		} finally {
			if ( $owned ) {
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release only this connection's acquired lock.
					$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
					if ( '1' !== (string) $released || ! empty( $wpdb->last_error ) ) {
						throw new \RuntimeException();
					}
					unset( self::$held[ $lock ] );
				} catch ( \Throwable $error ) {
					// Keep the process guard closed on uncertain release.
					$failed = true;
				}
			}
		}
		if ( $failed ) {
			throw new \RuntimeException( 'Webhook budget is unavailable.' );
		}
		return $allowed;
	}
}
