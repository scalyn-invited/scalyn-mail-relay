<?php
/**
 * Versioned repair of legacy nontransactional operational tables.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Keeps atomic diagnostics, reports and delivery evidence transactional. */
final class TransactionalTablesSchema {

	/** Only these existing plugin-owned tables require engine conversion. */
	private const TABLES = array( 'scalyn_diagnostics', 'scalyn_health_scores', 'scalyn_mail_logs', 'scalyn_mail_timeline' );

	/**
	 * Repairs MyISAM tables without replacing rows or changing their structure.
	 *
	 * WordPress dbDelta handles columns/indexes but does not convert storage engines.
	 * DDL is not atomic across tables: verify each result and safely resume on retry.
	 *
	 * @throws \RuntimeException When introspection or conversion fails.
	 */
	public static function migrate(): void {
		global $wpdb;
		foreach ( self::TABLES as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			$engine = self::engine( $table );
			if ( 'InnoDB' === $engine ) {
				continue;
			}
			if ( 'MyISAM' !== $engine ) {
				throw new \RuntimeException( 'Transactional table migration failed.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Versioned, allowlisted engine conversion; dbDelta cannot perform it.
			$converted = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
			if ( false === $converted || ! empty( $wpdb->last_error ) || 'InnoDB' !== self::engine( $table ) ) {
				throw new \RuntimeException( 'Transactional table migration failed.' );
			}
		}
	}

	/**
	 * Reads one exact table engine, never cached or inferred from the server default.
	 *
	 * @param string $table Qualified plugin table name.
	 * @return string|null Observed storage engine.
	 * @throws \RuntimeException When engine metadata cannot be read.
	 */
	private static function engine( string $table ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact metadata lookup at the migration boundary.
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Transactional table migration failed.' );
		}
		return is_string( $engine ) ? $engine : null;
	}
}
