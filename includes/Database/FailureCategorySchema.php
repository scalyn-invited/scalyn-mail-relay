<?php
/**
 * Additive, allowlisted failure evidence (ADR-0027).
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Old outcomes stay unclassified; no backfill from response text. */
final class FailureCategorySchema {
	/** Adds nullable columns and verifies both before advancing the version.
	 *
	 * @throws \RuntimeException When either column is unavailable.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( array( 'scalyn_mail_logs', 'scalyn_connection_evidence' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			dbDelta( "CREATE TABLE {$table} (\nfailure_category varchar(32) NULL\n) {$wpdb->get_charset_collate()};" );
		 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify additive nullable column before version advancement.
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			$valid   = false;
			foreach ( $columns ?? array() as $column ) {
				if ( 'failure_category' === ( $column['Field'] ?? '' ) ) {
					$valid = 'varchar(32)' === strtolower( $column['Type'] ?? '' ) && 'YES' === ( $column['Null'] ?? '' );
				}
			}
			if ( ! $valid ) {
				throw new \RuntimeException( 'Failure category migration failed.' ); }
		}
	}
}
