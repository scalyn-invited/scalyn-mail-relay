<?php
/** Additive logical-message/attempt evidence, schema 0.12.0.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** No historical submissions are relabelled or inferred. */
final class SubmissionAttemptSchema {

	/**
	 * Creates transactional attempt evidence and verifies schema before version advance.
	 *
	 * @throws \RuntimeException For an incomplete migration.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_submission_attempts';
		dbDelta(
			"CREATE TABLE {$table} (
		attempt_uuid char(36) NOT NULL,
		logical_uuid char(36) NOT NULL,
		configuration_id char(36) NULL,
		provider varchar(100) NOT NULL,
		origin varchar(81) NOT NULL,
		attempt_number tinyint unsigned NOT NULL,
		status varchar(16) NOT NULL,
		failure_category varchar(32) NULL,
		safe_to_retry tinyint NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		finished_at datetime NULL,
		PRIMARY KEY  (attempt_uuid),
		UNIQUE KEY logical_number (logical_uuid,attempt_number),
		KEY created_at (created_at,attempt_uuid)
		) ENGINE=InnoDB {$wpdb->get_charset_collate()};"
		);
		self::verify(
			$table,
			array( 'attempt_uuid', 'logical_uuid', 'configuration_id', 'provider', 'origin', 'attempt_number', 'status', 'failure_category', 'safe_to_retry', 'created_at', 'finished_at' ),
			array(
				'PRIMARY'        => array( 0, array( 'attempt_uuid' ) ),
				'logical_number' => array( 0, array( 'logical_uuid', 'attempt_number' ) ),
				'created_at'     => array( 1, array( 'created_at', 'attempt_uuid' ) ),
			)
		);
	}

	/**
	 * Shared exact verification for the owned recovery tables only.
	 *
	 * @param string $table Trusted migration table name.
	 * @param array  $required Required columns.
	 * @param array  $indexes Exact ordered indexes.
	 * @throws \RuntimeException When schema or transactional storage is unavailable.
	 */
	public static function verify( string $table, array $required, array $indexes ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify additive schema.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify transactional storage.
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( array_diff( $required, $columns ?? array() ) || 'InnoDB' !== $engine ) {
			throw new \RuntimeException( 'Submission storage migration failed.' );
		}
		foreach ( $indexes as $name => $expected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact index verification.
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $name ), ARRAY_A );
			$actual = array();
			foreach ( $rows ?? array() as $row ) {
				if ( (int) $row['Non_unique'] !== $expected[0] || null !== ( $row['Sub_part'] ?? null ) ) {
					throw new \RuntimeException( 'Submission storage migration failed.' );
				}
				$actual[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			}
			ksort( $actual );
			if ( array_values( $actual ) !== $expected[1] ) {
				throw new \RuntimeException( 'Submission storage migration failed.' );
			}
		}
	}
}
