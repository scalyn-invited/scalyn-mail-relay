<?php
/** AI session ownership and usage counters, schema 0.14.0.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Additive local tables, never creates conversations or enables AI. */
final class AiSchema {

	/** Creates and verifies both owned InnoDB tables. */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_ai_sessions';
		dbDelta(
			"CREATE TABLE {$table} (
			uuid char(36) NOT NULL,
			owner bigint unsigned NOT NULL,
			payload mediumtext NOT NULL,
			claim char(36) NOT NULL DEFAULT '',
			claim_until datetime NOT NULL,
			expires_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (uuid),
			KEY owner_updated (owner,updated_at,uuid),
			KEY expiry (expires_at,uuid)
		) ENGINE=InnoDB {$wpdb->get_charset_collate()};"
		);
		SubmissionAttemptSchema::verify(
			$table,
			array( 'uuid', 'owner', 'payload', 'claim', 'claim_until', 'expires_at', 'updated_at' ),
			array(
				'PRIMARY'       => array( 0, array( 'uuid' ) ),
				'owner_updated' => array( 1, array( 'owner', 'updated_at', 'uuid' ) ),
				'expiry'        => array( 1, array( 'expires_at', 'uuid' ) ),
			)
		);
		$table = $wpdb->prefix . 'scalyn_ai_usage';
		dbDelta(
			"CREATE TABLE {$table} (
			scope varchar(96) NOT NULL,
			used int unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (scope),
			KEY expiry (expires_at,scope)
		) ENGINE=InnoDB {$wpdb->get_charset_collate()};"
		);
		SubmissionAttemptSchema::verify(
			$table,
			array( 'scope', 'used', 'expires_at' ),
			array(
				'PRIMARY' => array( 0, array( 'scope' ) ),
				'expiry'  => array( 1, array( 'expires_at', 'scope' ) ),
			)
		);
	}
}
