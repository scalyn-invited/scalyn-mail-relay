<?php
/**
 * Revision-keyed connection evidence schema.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Additive schema; no historical success is backfilled. */
final class ConnectionEvidenceSchema {
	/**
	 * Creates and verifies the bounded evidence table.
	 *
	 * @throws \RuntimeException When schema verification fails.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_connection_evidence';
		dbDelta(
			"CREATE TABLE {$table} (
		configuration_id char(36) NOT NULL,
		provider varchar(100) NOT NULL,
		status varchar(16) NOT NULL,
		started_at datetime(6) NOT NULL,
		checked_at datetime(6) NOT NULL,
		PRIMARY KEY  (configuration_id,provider),
		KEY checked_at (checked_at)
		) ENGINE=InnoDB {$wpdb->get_charset_collate()};"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify schema before advancing the version.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		if ( array_diff( array( 'configuration_id', 'provider', 'status', 'started_at', 'checked_at' ), $columns ?? array() ) ) {
			throw new \RuntimeException( 'Connection evidence migration failed.' );
		}
		foreach ( array(
			'PRIMARY'    => array( 0, array( 'configuration_id', 'provider' ) ),
			'checked_at' => array( 1, array( 'checked_at' ) ),
		) as $name => $expected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact index order and uniqueness verification.
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $name ), ARRAY_A );
			$actual = array();
			foreach ( $rows ?? array() as $row ) {
				if ( (int) $row['Non_unique'] !== $expected[0] || null !== ( $row['Sub_part'] ?? null ) ) {
					throw new \RuntimeException( 'Connection evidence migration failed.' );
				}
				$actual[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			}
			ksort( $actual );
			if ( array_values( $actual ) !== $expected[1] ) {
				throw new \RuntimeException( 'Connection evidence migration failed.' );
			}
		}
	}
}
