<?php
/**
 * Opt-in diagnostic scheduling.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Diagnostics\DiagnosticRunService;

defined( 'ABSPATH' ) || exit;

/** Reconciles a single recurring event and dispatches one run per tick. */
final class DiagnosticSchedule {

	/** Registers lifecycle reconciliation and scheduled execution. */
	public function register(): void {
		add_action( 'init', array( self::class, 'reconcile' ) );
		add_action( ScheduledHooks::DIAGNOSTICS, array( $this, 'run' ) );
	}

	/** Reconciles enabled cadence without executing diagnostics.
	 *
	 * @return bool Whether the requested schedule exists or is disabled.
	 */
	public static function reconcile(): bool {
		$cadence = ( new SettingsRepository() )->get_diagnostic_schedule();
		$current = wp_get_schedule( ScheduledHooks::DIAGNOSTICS );
		if ( wp_next_scheduled( ScheduledHooks::DIAGNOSTICS ) && $current !== $cadence ) {
			wp_clear_scheduled_hook( ScheduledHooks::DIAGNOSTICS );
		}
		if ( 'disabled' === $cadence ) {
			return ! wp_next_scheduled( ScheduledHooks::DIAGNOSTICS );
		}
		if ( wp_next_scheduled( ScheduledHooks::DIAGNOSTICS ) ) {
			return wp_get_schedule( ScheduledHooks::DIAGNOSTICS ) === $cadence;
		}
		$intervals = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
		);
		return true === wp_schedule_event( time() + $intervals[ $cadence ], $cadence, ScheduledHooks::DIAGNOSTICS );
	}

	/** Executes at most once; a disabled stale callback does no work. */
	public function run(): void {
		if ( 'disabled' === ( new SettingsRepository() )->get_diagnostic_schedule() ) {
			return;
		}
		Plugin::instance()->container()->get( DiagnosticRunService::class )->run();
	}
}
