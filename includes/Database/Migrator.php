<?php
/**
 * Database schema migrations.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Handles database schema creation and versioned upgrades via dbDelta().
 *
 * Ownership: Yaj / Database. Kim may add new migration version gates here
 * when a schema change requires it.
 */
final class Migrator {

	/**
	 * Runs outstanding migrations if the installed DB version is behind the target.
	 *
	 * @throws \RuntimeException When schema or version persistence fails.
	 */
	public static function migrate(): void {
		$installed = (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' );

		if ( version_compare( $installed, SCALYN_MAIL_RELAY_DB_VERSION, '>=' ) ) {
			return;
		}

		if ( version_compare( $installed, '0.1.0', '<' ) ) {
			self::create_foundation_tables();
		}
		if ( version_compare( $installed, '0.2.0', '<' ) ) {
			self::create_alert_notifications();
		}
		update_option( 'scalyn_mail_relay_db_version', SCALYN_MAIL_RELAY_DB_VERSION, false );
		if ( SCALYN_MAIL_RELAY_DB_VERSION !== get_option( 'scalyn_mail_relay_db_version' ) ) {
			throw new \RuntimeException( 'Database version could not be saved.' );
		}
	}

	/** Upgrades from an authenticated settings administrator request. */
	public static function maybe_upgrade(): void {
		if ( ! current_user_can( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		try {
			self::migrate();
		} catch ( \Throwable $error ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Mail Relay database upgrade could not complete. Check database permissions and retry.', 'scalyn-mail-relay' ) . '</p></div>';
				}
			);
		}
	}

	/** Adds the bounded notification outbox with idempotent dbDelta.
	 *
	 * @throws \RuntimeException When the new table is unavailable.
	 */
	private static function create_alert_notifications(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'scalyn_alert_notifications';
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			notification_uuid char(36) NOT NULL,
			alert_uuid char(36) NOT NULL,
			event varchar(20) NOT NULL,
			status varchar(20) NOT NULL,
			attempts smallint unsigned NOT NULL DEFAULT 0,
			response_code smallint unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NOT NULL,
			last_attempt_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY notification_uuid (notification_uuid),
			UNIQUE KEY alert_event (alert_uuid,event),
			KEY due_status (status,next_attempt_at)
		) ENGINE=InnoDB {$collate};"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify migration before advancing the version.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			throw new \RuntimeException( 'Alert migration failed.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify required columns before advancing the schema version.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		if ( array_diff( array( 'id', 'notification_uuid', 'alert_uuid', 'event', 'status', 'attempts', 'response_code', 'next_attempt_at', 'last_attempt_at', 'created_at' ), $columns ?? array() ) ) {
			throw new \RuntimeException( 'Alert migration failed.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify uniqueness required for outbox idempotency.
		$indexes = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND NON_UNIQUE=0 AND INDEX_NAME IN ('PRIMARY','notification_uuid','alert_event')", $table ) );
		if ( 3 !== (int) $indexes ) {
			throw new \RuntimeException( 'Alert migration failed.' );
		}
	}

	/**
	 * Creates the full set of foundation tables using dbDelta().
	 * dbDelta() is safe to run on existing tables — it only adds missing structures.
	 */
	private static function create_foundation_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql   = array();
		$sql[] = "CREATE TABLE {$prefix}scalyn_mail_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_uuid char(36) NOT NULL,
			mailer varchar(100) NOT NULL DEFAULT '',
			provider varchar(100) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'generated',
			source_type varchar(50) NOT NULL DEFAULT '',
			source_name varchar(191) NOT NULL DEFAULT '',
			response_code varchar(50) NOT NULL DEFAULT '',
			response_message text NULL,
			attachment_count smallint(5) unsigned NOT NULL DEFAULT 0,
			retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			sent_at datetime NULL,
			failed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY message_uuid (message_uuid),
			KEY status_created (status, created_at),
			KEY provider_created (provider, created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}scalyn_mail_timeline (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_uuid char(36) NOT NULL,
			event_type varchar(50) NOT NULL,
			event_status varchar(32) NOT NULL DEFAULT '',
			event_label varchar(191) NOT NULL DEFAULT '',
			event_message text NULL,
			event_data longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY message_created (message_uuid, created_at),
			KEY event_created (event_type, created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}scalyn_diagnostics (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			diagnostic_uuid char(36) NOT NULL,
			check_type varchar(50) NOT NULL,
			check_name varchar(191) NOT NULL,
			status varchar(32) NOT NULL,
			severity varchar(32) NOT NULL,
			score smallint(3) unsigned NULL,
			result_message text NULL,
			recommended_action text NULL,
			raw_result longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY diagnostic_uuid (diagnostic_uuid),
			KEY type_created (check_type, created_at),
			KEY severity_created (severity, created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}scalyn_health_scores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			score_uuid char(36) NOT NULL,
			overall_score smallint(3) unsigned NOT NULL,
			deliverability_score smallint(3) unsigned NULL,
			dns_score smallint(3) unsigned NULL,
			provider_score smallint(3) unsigned NULL,
			failure_score smallint(3) unsigned NULL,
			security_score smallint(3) unsigned NULL,
			summary text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY score_uuid (score_uuid),
			KEY overall_created (overall_score, created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}scalyn_alerts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			alert_uuid char(36) NOT NULL,
			alert_type varchar(50) NOT NULL,
			severity varchar(32) NOT NULL,
			title varchar(191) NOT NULL,
			message text NULL,
			status varchar(32) NOT NULL DEFAULT 'active',
			related_resource varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			resolved_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY alert_uuid (alert_uuid),
			KEY status_created (status, created_at),
			KEY severity_created (severity, created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}scalyn_audit_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(100) NOT NULL,
			resource_type varchar(100) NOT NULL DEFAULT '',
			resource_id varchar(191) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			metadata longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at),
			KEY action_created (action, created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
