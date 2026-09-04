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
 * Activation: runs database migrations, grants capabilities, clears stale cron
 * events (data is preserved).
 * Deactivation: clears cron events (data is preserved).
 * Uninstall: handled separately by uninstall.php.
 *
 * Scheduled events: this plugin schedules no recurring events in the 0.1.0 MVP.
 * See docs/adr/0002-mvp-release-hardening-accepted-risks.md — the retention job
 * that scalyn_mail_relay_cleanup_logs was scheduled for is not implemented, so
 * scheduling it registered a daily WP-Cron event with no listener. cron_hooks()
 * is retained as the authoritative list of hook names this plugin has ever
 * owned, so activation, deactivation and uninstall can all clear events left
 * behind by an earlier install.
 */
final class Lifecycle {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::assert_environment();
		Migrator::migrate();
		self::grant_capabilities();
		self::clear_scheduled_events();
		update_option( 'scalyn_mail_relay_version', SCALYN_MAIL_RELAY_VERSION, false );
	}

	/**
	 * Runs on plugin deactivation. Preserves all data.
	 */
	public static function deactivate(): void {
		self::clear_scheduled_events();
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
	 * Clears every cron event this plugin owns.
	 *
	 * Called on activation as well as deactivation: an install upgraded from a
	 * build that scheduled scalyn_mail_relay_cleanup_logs and
	 * scalyn_mail_relay_run_daily_diagnostics still has those events in the
	 * cron array, and WordPress does not run the activation hook on a plugin
	 * update. Clearing on activation means a reactivation removes them; a
	 * leftover event is harmless in the meantime because no listener is
	 * registered for it.
	 */
	private static function clear_scheduled_events(): void {
		foreach ( self::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Returns all cron hook names owned by this plugin.
	 *
	 * This is the authoritative list of every hook name the plugin has ever
	 * scheduled, including names no longer scheduled by the current version.
	 * Removing a name here would strand its events on upgraded installs.
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
