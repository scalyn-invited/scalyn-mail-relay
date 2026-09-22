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
 * Activation migrates, grants capabilities and schedules hourly retention.
 * Deactivation stops owned scheduled work and preserves data.
 * ScheduledHooks also supplies the uninstall hook list.
 */
final class Lifecycle {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::assert_environment();
		Migrator::migrate();
		self::grant_capabilities();
		ScheduledHooks::clear();
		RetentionService::ensure_scheduled();
		DiagnosticSchedule::reconcile();
		update_option( 'scalyn_mail_relay_version', SCALYN_MAIL_RELAY_VERSION, false );
	}

	/**
	 * Runs on plugin deactivation. Preserves all data.
	 */
	public static function deactivate(): void {
		ScheduledHooks::clear();
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
}
