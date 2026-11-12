<?php
/**
 * Minimal alert observations.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Alerts;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Database\DiagnosticRunStateRepository;

defined( 'ABSPATH' ) || exit;

/** Reads status and score only, never message content or diagnostic raw data. */
final class ObservationRepository {

	/**
	 * Returns tri-state conditions from persisted observations.
	 *
	 * @param int $now Current epoch.
	 * @return array Conditions.
	 */
	public function conditions( int $now ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded repository observation.
		$mail = $wpdb->get_results( "SELECT status,created_at FROM {$wpdb->prefix}scalyn_mail_logs WHERE status IN ('accepted','failed') ORDER BY created_at DESC,id DESC LIMIT 3", ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			$mail = array();
		}
		$mail = array_map(
			static fn( $row ) => array(
				'status' => $row['status'],
				'at'     => self::epoch( $row['created_at'], wp_timezone() ),
			),
			$mail ?? array()
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded repository observation.
		$rows     = $wpdb->get_results( "SELECT overall_score,created_at FROM {$wpdb->prefix}scalyn_health_scores ORDER BY created_at DESC,id DESC LIMIT 1", ARRAY_A );
		$health   = empty( $wpdb->last_error ) && ! empty( $rows ) ? array(
			'score' => (int) $rows[0]['overall_score'],
			'at'    => self::epoch( $rows[0]['created_at'], wp_timezone() ),
		) : array();
		$settings = new SettingsRepository();
		$cadence  = $settings->get_diagnostic_schedule();
		$state    = ( new DiagnosticRunStateRepository() )->get();
		$run      = $state['scheduled'] ?? array();
		$monitor  = array(
			'enabled'  => 'disabled' !== $cadence,
			'due'      => wp_get_schedule( ScheduledHooks::DIAGNOSTICS ) === $cadence ? wp_next_scheduled( ScheduledHooks::DIAGNOSTICS ) : 0,
			'interval' => array(
				'hourly'     => 3600,
				'twicedaily' => 43200,
				'daily'      => 86400,
			)[ $cadence ] ?? 86400,
			'run'      => array(
				'state'    => $run['state'] ?? '',
				'started'  => $run['started_at'] ?? 0,
				'finished' => $run['finished_at'] ?? 0,
			),
		);
		return ( new IncidentRules() )->evaluate( $mail, $monitor, $health, $now );
	}

	/**
	 * Parses a strict database timestamp.
	 *
	 * @param string        $value Date.
	 * @param \DateTimeZone $zone Storage timezone.
	 * @return int Epoch, or zero for invalid data.
	 */
	public static function epoch( string $value, \DateTimeZone $zone ): int {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $zone );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $date->getTimestamp() : 0;
	}
}
