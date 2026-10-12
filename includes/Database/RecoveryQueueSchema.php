<?php
/** Opt-in encrypted outbox, schema 0.13.0.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** No payload is created by installing or upgrading the schema. */
final class RecoveryQueueSchema {

	/** Creates and verifies the isolated outbox.
	 *
	 * @throws \RuntimeException For incomplete schema.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_recovery_queue';
		dbDelta(
			"CREATE TABLE {$table} (
		logical_uuid char(36) NOT NULL,
		payload mediumtext NOT NULL,
		state varchar(16) NOT NULL,
		attempt_count tinyint unsigned NOT NULL DEFAULT 0,
		claim_token char(36) NULL,
		claimed_at datetime NULL,
		next_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		reason varchar(32) NOT NULL,
		PRIMARY KEY  (logical_uuid),
		KEY due_work (state,next_at,logical_uuid),
		KEY expiry (expires_at,logical_uuid),
		KEY created_at (created_at,logical_uuid)
		) ENGINE=InnoDB {$wpdb->get_charset_collate()};"
		);
		SubmissionAttemptSchema::verify(
			$table,
			array( 'logical_uuid', 'payload', 'state', 'attempt_count', 'claim_token', 'claimed_at', 'next_at', 'expires_at', 'created_at', 'updated_at', 'reason' ),
			array(
				'PRIMARY'    => array( 0, array( 'logical_uuid' ) ),
				'due_work'   => array( 1, array( 'state', 'next_at', 'logical_uuid' ) ),
				'expiry'     => array( 1, array( 'expires_at', 'logical_uuid' ) ),
				'created_at' => array( 1, array( 'created_at', 'logical_uuid' ) ),
			)
		);
	}
}
