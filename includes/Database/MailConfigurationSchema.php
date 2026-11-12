<?php
/**
 * Additive dispatch configuration attribution (ADR-0027).
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Leaves existing mail records unattributed; never backfills a revision. */
final class MailConfigurationSchema {

	/**
	 * Adds and verifies the nullable revision and bounded-read index.
	 *
	 * @throws \RuntimeException When schema verification fails.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_mail_logs';
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			configuration_id char(36) NULL,
			PRIMARY KEY  (id),
			KEY configuration_created (configuration_id,created_at,id)
		) {$wpdb->get_charset_collate()};"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify additive schema before advancing version.
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$valid   = false;
		foreach ( $columns ?? array() as $column ) {
			if ( 'configuration_id' === ( $column['Field'] ?? '' ) ) {
				$valid = 'char(36)' === strtolower( $column['Type'] ?? '' ) && 'YES' === ( $column['Null'] ?? '' );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify exact index order and no prefix truncation.
		$index    = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'configuration_created' ), ARRAY_A );
		$expected = array( 'configuration_id', 'created_at', 'id' );
		foreach ( $index ?? array() as $position => $part ) {
			$valid = $valid && ( $expected[ $position ] ?? null ) === ( $part['Column_name'] ?? null )
				&& (int) ( $part['Seq_in_index'] ?? 0 ) === $position + 1
				&& 1 === (int) ( $part['Non_unique'] ?? 0 ) && null === ( $part['Sub_part'] ?? null );
		}
		if ( ! $valid || 3 !== count( $index ?? array() ) ) {
			throw new \RuntimeException( 'Mail configuration migration failed.' );
		}
	}
}
