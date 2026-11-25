<?php
/**
 * Versioned repair of the audit log table storage engine.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Repairs only the plugin-owned audit log table; preserves rows, columns and indexes.
 *
 * ALTER TABLE … ENGINE=InnoDB rebuilds the table under a metadata lock. On servers
 * where this table already exists as MyISAM, concurrent audit-log inserts will block
 * until the rebuild completes. Row counts on affected installs are typically small
 * (new plugin at 0.1.1), so the window is expected to be sub-second in practice.
 */
final class AuditLogSchema {

	/**
	 * Converts a legacy MyISAM audit log table to InnoDB and verifies the result.
	 *
	 * WordPress dbDelta cannot convert engines. DDL implicitly commits; retries skip an
	 * already converted table if a later version-option write previously failed.
	 *
	 * @throws \RuntimeException When metadata or engine conversion fails.
	 */
	public static function migrate(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'scalyn_audit_logs';
		$engine = self::engine( $table );
		if ( 'InnoDB' === $engine ) {
			return;
		}
		if ( 'MyISAM' !== $engine ) {
			throw new \RuntimeException( 'Audit log engine migration failed.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Versioned conversion of one fixed plugin table; dbDelta cannot perform it.
		$converted = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		if ( false === $converted || ! empty( $wpdb->last_error ) || 'InnoDB' !== self::engine( $table ) ) {
			throw new \RuntimeException( 'Audit log engine migration failed.' );
		}
	}

	/**
	 * Reads exact table metadata in the current database, not the server default.
	 *
	 * @param string $table Prefixed plugin table name.
	 * @return string|null Observed engine, or null for unavailable metadata.
	 * @throws \RuntimeException When metadata cannot be read.
	 */
	private static function engine( string $table ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached verification at the schema migration boundary.
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Audit log engine migration failed.' );
		}
		return is_string( $engine ) ? $engine : null;
	}
}
