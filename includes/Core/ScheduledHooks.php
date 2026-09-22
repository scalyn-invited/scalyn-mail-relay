<?php
/**
 * Scheduled hook ownership, also available during uninstall.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/** Single source of truth for current and historical scheduled hooks. */
final class ScheduledHooks {

	public const CLEANUP     = 'scalyn_mail_relay_cleanup_logs';
	public const ALERTS      = 'scalyn_mail_relay_send_alerts';
	public const DIAGNOSTICS = 'scalyn_mail_relay_run_daily_diagnostics';

	/**
	 * Lists every hook ever scheduled by the plugin.
	 *
	 * @return string[] Owned hook names.
	 */
	public static function all(): array {
		return array( self::CLEANUP, self::DIAGNOSTICS, 'scalyn_mail_relay_generate_health_snapshot', self::ALERTS );
	}

	/** Clears scheduled work without deleting operational data. */
	public static function clear(): void {
		foreach ( self::all() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
