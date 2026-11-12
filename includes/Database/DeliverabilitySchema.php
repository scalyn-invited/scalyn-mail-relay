<?php
/** Persisted, privacy-minimal assessment traces (schema 0.11.0).
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Additive migration; no historical results are backfilled. */
final class DeliverabilitySchema {

	/** Creates and verifies trace storage before advancing the version.
	 *
	 * @throws \RuntimeException If schema verification fails.
	 */
	public static function migrate(): void {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			self::create_and_verify();
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Deliverability schema could not be verified.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Creates storage and verifies all required columns and exact indexes.
	 *
	 * @throws \RuntimeException When verification fails.
	 */
	private static function create_and_verify(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_deliverability_evidence';
		dbDelta(
			"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		computation_uuid char(36) NOT NULL,
		configuration_id char(36) NOT NULL,
		provider varchar(100) NOT NULL,
		diagnostic_uuid char(36) NULL,
		kind varchar(16) NOT NULL,
		weights_version varchar(32) NOT NULL,
		payload longtext NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY computation_uuid (computation_uuid),
		KEY configuration_kind (configuration_id,provider,kind,id),
		KEY created_at (created_at,id)
		) ENGINE=InnoDB {$wpdb->get_charset_collate()};"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify the additive migration.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		if ( array_diff( array( 'id', 'computation_uuid', 'configuration_id', 'provider', 'diagnostic_uuid', 'kind', 'weights_version', 'payload', 'created_at' ), $columns ?? array() ) ) {
			throw new \RuntimeException( 'Deliverability schema could not be verified.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify transactional storage rather than trusting CREATE TABLE alone.
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( 'InnoDB' !== $engine ) {
			throw new \RuntimeException( 'Deliverability schema could not be verified.' );
		}
		foreach ( array(
			'PRIMARY'            => array( 0, array( 'id' ) ),
			'computation_uuid'   => array( 0, array( 'computation_uuid' ) ),
			'configuration_kind' => array( 1, array( 'configuration_id', 'provider', 'kind', 'id' ) ),
			'created_at'         => array( 1, array( 'created_at', 'id' ) ),
		) as $name => $expected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify exact index ordering and uniqueness.
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $name ), ARRAY_A );
			$actual = array();
			foreach ( $rows ?? array() as $row ) {
				if ( (int) $row['Non_unique'] !== $expected[0] || null !== ( $row['Sub_part'] ?? null ) ) {
					throw new \RuntimeException( 'Deliverability schema could not be verified.' );
				}
				$actual[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			}
			ksort( $actual );
			if ( array_values( $actual ) !== $expected[1] ) {
				throw new \RuntimeException( 'Deliverability schema could not be verified.' );
			}
		}
	}
}
