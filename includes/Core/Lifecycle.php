<?php
/**
 * Plugin lifecycle operations.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation and environment assertion.
 *
 * Activation: runs database migrations, grants capabilities, schedules cron events.
 * Deactivation: clears cron events (data is preserved).
 * Uninstall: handled separately by uninstall.php.
 */
final class Lifecycle {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::assert_environment();
		Migrator::migrate();
		self::grant_capabilities();
		self::schedule_events();
		update_option( 'scalyn_mail_relay_version', SCALYN_MAIL_RELAY_VERSION, false );
	}

	/**
	 * Runs on plugin deactivation. Preserves all data.
	 */
	public static function deactivate(): void {
		foreach ( self::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Verifies minimum PHP and WordPress version requirements.
	 * Calls wp_die() if requirements are not met.
	 */
	private static function assert_environment(): void {
		global $wp_version;

		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			wp_die( esc_html__( 'Scalyn Mail Relay requires PHP 8.2 or newer.', 'scalyn-mail-relay' ) );
		}

		if ( version_compare( $wp_version, '6.5', '<' ) ) {
			wp_die( esc_html__( 'Scalyn Mail Relay requires WordPress 6.5 or newer.', 'scalyn-mail-relay' ) );
		}
	}

	/**
	 * Adds all plugin capabilities to the Administrator role.
	 */
	private static function grant_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		foreach ( Capabilities::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Schedules recurring cron events if not already scheduled.
	 */
	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'scalyn_mail_relay_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'scalyn_mail_relay_cleanup_logs' );
		}

		if ( ! wp_next_scheduled( 'scalyn_mail_relay_run_daily_diagnostics' ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', 'scalyn_mail_relay_run_daily_diagnostics' );
		}
	}

	/**
	 * Returns all cron hook names owned by this plugin.
	 *
	 * @return string[]
	 */
	private static function cron_hooks(): array {
		return array(
			'scalyn_mail_relay_cleanup_logs',
			'scalyn_mail_relay_run_daily_diagnostics',
			'scalyn_mail_relay_generate_health_snapshot',
			'scalyn_mail_relay_send_alerts',
		);
	}
}
