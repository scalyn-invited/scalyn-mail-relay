<?php
/**
 * Read-only monitoring status presentation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Database\DiagnosticRunStateRepository;

defined( 'ABSPATH' ) || exit;

/** Keeps scheduling, execution and freshness distinct from diagnostic health. */
final class MonitoringStatusPresenter {

	/** Labels retained evidence independently of execution status.
	 *
	 * @param string|null $created_at Site-time evidence timestamp.
	 * @param string      $cadence Configured cadence; disabled uses a daily reference.
	 * @param int         $now UTC Unix time.
	 * @return string Safe freshness explanation.
	 */
	public static function evidence( ?string $created_at, string $cadence, int $now ): string {
		$date = is_string( $created_at ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created_at ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, wp_timezone() ) : false;
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $created_at ) {
			return __( 'No valid retained evidence timestamp; freshness is unknown.', 'scalyn-mail-relay' );
		}
		$intervals = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
		);
		$age       = $now - $date->getTimestamp();
		$label     = $age < 0 ? __( 'Future timestamp: freshness unknown.', 'scalyn-mail-relay' )
			: ( $age > ( $intervals[ $cadence ] ?? 86400 ) + 300
				? __( 'Stale evidence.', 'scalyn-mail-relay' )
				: __( 'Recent evidence; this is not a health or delivery guarantee.', 'scalyn-mail-relay' ) );
		return $label . ' ' . __( 'Recorded (site time):', 'scalyn-mail-relay' ) . ' ' . $created_at;
	}

	/** Reads safe repository state without running or rescheduling diagnostics.
	 *
	 * @return array View model.
	 */
	public static function load(): array {
		try {
			return self::present(
				( new SettingsRepository() )->get_diagnostic_schedule(),
				( new DiagnosticRunStateRepository() )->get(),
				wp_next_scheduled( ScheduledHooks::DIAGNOSTICS ),
				wp_get_schedule( ScheduledHooks::DIAGNOSTICS ),
				time()
			);
		} catch ( \Throwable $error ) {
			return array( 'unavailable' => true );
		}
	}

	/** Builds deterministic labels from a normalized repository record.
	 *
	 * @param string       $cadence Configured cadence.
	 * @param array        $state Normalized execution state.
	 * @param int|false    $next Next scheduled timestamp.
	 * @param string|false $actual Registered recurrence.
	 * @param int          $now Current UTC Unix time.
	 * @return array Read-only view model.
	 */
	public static function present( string $cadence, array $state, int|false $next, string|false $actual, int $now ): array {
		$intervals = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
		);
		$enabled   = isset( $intervals[ $cadence ] );
		$labels    = array(
			'disabled'   => __( 'Disabled', 'scalyn-mail-relay' ),
			'hourly'     => __( 'Hourly', 'scalyn-mail-relay' ),
			'twicedaily' => __( 'Twice daily', 'scalyn-mail-relay' ),
			'daily'      => __( 'Daily', 'scalyn-mail-relay' ),
		);
		$schedule  = __( 'Automatic monitoring is disabled.', 'scalyn-mail-relay' );
		if ( $enabled ) {
			$schedule = ! $next || $actual !== $cadence
				? __( 'Schedule missing or mismatched. Save the cadence in Settings and check WP-Cron.', 'scalyn-mail-relay' )
				: ( $next < $now - 300
					? __( 'Scheduled monitoring is overdue. Check the server cron trigger and WordPress cron lock.', 'scalyn-mail-relay' )
					: __( 'An event is scheduled; this does not prove it will execute on time.', 'scalyn-mail-relay' ) );
		}
		$success   = $state['last_scheduled_success'] ?? null;
		$freshness = __( 'No scheduled completion has been recorded.', 'scalyn-mail-relay' );
		if ( ! $enabled ) {
			$freshness = __( 'Automatic freshness is not evaluated while monitoring is disabled.', 'scalyn-mail-relay' );
		} elseif ( null !== $success ) {
			$freshness = $success['finished_at'] > $now
				? __( 'Recorded completion is in the future. Check the server clock; freshness is unknown.', 'scalyn-mail-relay' )
				: ( $now - $success['finished_at'] > $intervals[ $cadence ] + 300
					? __( 'Scheduled results are stale. No confirmed completion within the cadence plus five minutes.', 'scalyn-mail-relay' )
					: __( 'A scheduled execution completed within the cadence plus five minutes. Check findings separately.', 'scalyn-mail-relay' ) );
		}
		return array(
			'unavailable'  => false,
			'cadence'      => $labels[ $cadence ] ?? $labels['disabled'],
			'schedule'     => $schedule,
			'freshness'    => $freshness,
			'next'         => $enabled && $next ? $next : 0,
			'latest'       => self::describe( $state['latest'] ?? null ),
			'scheduled'    => self::describe( $state['scheduled'] ?? null ),
			'last_success' => $success['finished_at'] ?? 0,
			'unfinished'   => null !== ( $state['previous_unfinished'] ?? null ),
		);
	}

	/** Describes an execution without treating completed checks as a health pass.
	 *
	 * @param array|null $record Normalized status record.
	 * @return string Fixed labels and UTC timestamps.
	 */
	private static function describe( ?array $record ): string {
		if ( null === $record ) {
			return __( 'None recorded.', 'scalyn-mail-relay' );
		}
		$labels   = array(
			'running'   => __( 'Completion unconfirmed: it may still be running, have been interrupted, or have failed to save its final status.', 'scalyn-mail-relay' ),
			'completed' => __( 'Execution completed; findings may include failed or unknown checks.', 'scalyn-mail-relay' ),
			'failed'    => __( 'Execution failed.', 'scalyn-mail-relay' ),
		);
		$failures = array(
			''                   => '',
			'context_failed'     => __( 'Check provider configuration and plugin compatibility.', 'scalyn-mail-relay' ),
			'checks_failed'      => __( 'Check diagnostic extensions and execution limits; retry the run.', 'scalyn-mail-relay' ),
			'publication_failed' => __( 'Check database availability, InnoDB tables and permissions; the new publication was not confirmed.', 'scalyn-mail-relay' ),
		);
		return sprintf(
			/* translators: 1: outcome, 2: remediation, 3: start UTC, 4: finish UTC. */
			__( '%1$s %2$s Started (UTC): %3$s. Finished (UTC): %4$s.', 'scalyn-mail-relay' ),
			$labels[ $record['state'] ],
			$failures[ $record['failure_code'] ],
			gmdate( 'Y-m-d H:i:s', $record['started_at'] ),
			$record['finished_at'] ? gmdate( 'Y-m-d H:i:s', $record['finished_at'] ) : __( 'Not recorded', 'scalyn-mail-relay' )
		);
	}
}
