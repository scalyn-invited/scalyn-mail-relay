<?php
/**
 * Additive, opt-in delivery evidence storage.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Owns schema only; creates no sources, keys, associations or delivery claims. */
final class DeliveryEvidenceSchema {

	/**
	 * Adds explicit key retirement without rewriting applied version 0.5.0.
	 *
	 * @throws \RuntimeException When the additive lifecycle migration fails.
	 */
	public static function migrate_key_retirement(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'scalyn_delivery_keys';
		dbDelta( "CREATE TABLE {$table} (\nkey_version char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nretired_at datetime NULL,\nPRIMARY KEY  (key_version),\nKEY retired_version (retired_at,key_version)\n) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Additive migration verification.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify the bounded retirement index.
		$index  = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $table, 'retired_version' ), ARRAY_A );
		$actual = array();
		foreach ( $index ?? array() as $row ) {
			if ( 1 !== (int) $row['Non_unique'] || null !== ( $row['Sub_part'] ?? null ) ) {
				throw new \RuntimeException( 'Delivery key retirement migration failed.' );
			}
			$actual[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		ksort( $actual );
		if ( ! in_array( 'retired_at', $columns ?? array(), true ) || array_values( $actual ) !== array( 'retired_at', 'key_version' ) ) {
			throw new \RuntimeException( 'Delivery key retirement migration failed.' );
		}
	}

	/**
	 * Creates and verifies transactional tables before the schema version advances.
	 *
	 * @throws \RuntimeException When a table, engine, column or index is unavailable.
	 */
	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::definitions() as $suffix => $definition ) {
			$table = $wpdb->prefix . $suffix;
			dbDelta( "CREATE TABLE {$table} (\n{$definition['sql']}\n) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Migration verification is never cached.
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify columns before advancing the schema version.
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) || array_diff( $definition['columns'], $columns ?? array() ) ) {
				throw new \RuntimeException( 'Delivery evidence migration failed.' );
			}
			foreach ( $definition['indexes'] as $name => $expected ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify order and uniqueness, not just index presence.
				$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $table, $name ), ARRAY_A );
				$actual = array();
				foreach ( $rows ?? array() as $row ) {
					if ( (int) $row['Non_unique'] !== $expected[0] || null !== ( $row['Sub_part'] ?? null ) ) {
						throw new \RuntimeException( 'Delivery evidence migration failed.' );
					}
					$actual[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
				}
				ksort( $actual );
				if ( array_values( $actual ) !== $expected[1] ) {
					throw new \RuntimeException( 'Delivery evidence migration failed.' );
				}
			}
		}
	}

	/**
	 * Internal definitions shared with isolated migration tests.
	 *
	 * @return array Table definitions; identifiers contain no user input.
	 */
	public static function definitions(): array {
		return array(
			'scalyn_delivery_keys'       => array(
				'sql'     => "key_version char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nkey_envelope varchar(1024) NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (key_version)",
				'columns' => array( 'key_version', 'key_envelope', 'created_at' ),
				'indexes' => array( 'PRIMARY' => array( 0, array( 'key_version' ) ) ),
			),
			'scalyn_delivery_attempts'   => array(
				'sql'     => "message_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nsource_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nprovider varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nconfiguration_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nprovider_message_id varchar(255) CHARACTER SET ascii COLLATE ascii_bin NULL,\nkey_version char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nexpected_recipients smallint unsigned NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (message_uuid),\nKEY source_message (source_id,provider,provider_message_id),\nKEY key_version (key_version),\nKEY created_message (created_at,message_uuid)",
				'columns' => array( 'message_uuid', 'source_id', 'provider', 'configuration_id', 'provider_message_id', 'key_version', 'expected_recipients', 'created_at' ),
				'indexes' => array(
					'PRIMARY'         => array( 0, array( 'message_uuid' ) ),
					'source_message'  => array( 1, array( 'source_id', 'provider', 'provider_message_id' ) ),
					'key_version'     => array( 1, array( 'key_version' ) ),
					'created_message' => array( 1, array( 'created_at', 'message_uuid' ) ),
				),
			),
			'scalyn_delivery_recipients' => array(
				'sql'     => "message_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nrecipient_token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nPRIMARY KEY  (message_uuid,recipient_token)",
				'columns' => array( 'message_uuid', 'recipient_token' ),
				'indexes' => array( 'PRIMARY' => array( 0, array( 'message_uuid', 'recipient_token' ) ) ),
			),
			'scalyn_delivery_events'     => array(
				'sql'     => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nschema_version smallint unsigned NOT NULL,\nsource_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nprovider varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nmessage_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nprovider_message_id varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nevent_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\nkind varchar(16) NOT NULL,\nrecipient_token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\noccurred_at datetime(6) NOT NULL,\nreceived_at datetime(6) NOT NULL,\nauthentication_method varchar(32) NOT NULL,\nreason_code varchar(32) NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY source_event (source_id,event_key),\nKEY message_receipt (message_uuid,received_at,id)",
				'columns' => array( 'id', 'schema_version', 'source_id', 'provider', 'message_uuid', 'provider_message_id', 'event_key', 'kind', 'recipient_token', 'occurred_at', 'received_at', 'authentication_method', 'reason_code' ),
				'indexes' => array(
					'PRIMARY'         => array( 0, array( 'id' ) ),
					'source_event'    => array( 0, array( 'source_id', 'event_key' ) ),
					'message_receipt' => array( 1, array( 'message_uuid', 'received_at', 'id' ) ),
				),
			),
		);
	}
}
