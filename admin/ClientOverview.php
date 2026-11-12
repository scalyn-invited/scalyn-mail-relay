<?php
/**
 * Minimal client read model; raw evidence never reaches the view or download.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Diagnostics\CurrentDiagnostics;
use Scalyn\MailRelay\Logging\MailLogRepository;

defined( 'ABSPATH' ) || exit;

/** Configuration health is revision-scoped; activity counts are explicitly site-wide. */
final class ClientOverview {

	/** Reads existing services without running diagnostics or reading credentials.
	 *
	 * @return array Fixed summary projection.
	 * @throws \RuntimeException When access is denied.
	 */
	public static function load(): array {
		if ( ! current_user_can( Capabilities::VIEW_CLIENT_REPORTS ) ) {
			throw new \RuntimeException( 'Client summary access denied.' );
		}
		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		try {
			$scope = $container->get( CurrentDiagnostics::class )->snapshot();
		} catch ( \Throwable $error ) {
			$scope = array();
		}
		$activity = ActivitySummaryPresenter::load( $container->get( MailLogRepository::class ), current_time( 'mysql' ) );
		return self::project( $scope, $activity, $settings->get_diagnostic_schedule(), time() );
	}

	/** Reduces repository data to numeric summaries and fixed status vocabulary.
	 *
	 * @param array  $scope CurrentDiagnostics snapshot.
	 * @param array  $activity Seven-day site-wide counts.
	 * @param string $cadence Diagnostic cadence.
	 * @param int    $now UTC time.
	 * @return array No domains, identifiers, addresses, free text or raw evidence.
	 */
	public static function project( array $scope, array $activity, string $cadence, int $now ): array {
		$row     = is_array( $scope['health'] ?? null ) ? $scope['health'] : array();
		$created = $row['created_at'] ?? null;
		$date    = is_string( $created ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created, wp_timezone() ) : false;
		$valid   = false !== $date && $date->format( 'Y-m-d H:i:s' ) === $created;
		$age     = $valid ? $now - $date->getTimestamp() : -1;
		$limits  = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
		);
		$state   = $age < 0 ? 'unknown' : ( $age > ( $limits[ $cadence ] ?? 86400 ) + 300 ? 'stale' : 'recent' );
		$score   = $row['overall_score'] ?? null;
		$score   = ( is_int( $score ) || ( is_string( $score ) && preg_match( '/^[0-9]{1,3}$/D', $score ) ) ) && (int) $score <= 100 && (int) $score >= 0 ? (int) $score : null;
		$score   = 'recent' === $state ? $score : null;
		$health  = 'stale' === $state ? 'stale' : HealthScorePresenter::ui_status( $score );
		$counts  = array();
		foreach ( array( 'total', 'accepted', 'failed', 'other' ) as $key ) {
			$value          = $activity[ $key ] ?? null;
			$counts[ $key ] = true === ( $activity['available'] ?? false ) && is_int( $value ) && $value >= 0 ? $value : null;
		}
		$available = ! in_array( null, $counts, true );
		if ( $available && $counts['total'] !== $counts['accepted'] + $counts['failed'] + $counts['other'] ) {
			$available = false;
		}
		$period = array(
			'start' => null,
			'end'   => null,
		);
		try {
			$bounded = new \Scalyn\MailRelay\Reporting\ReportPeriod( $activity['start'] ?? '', $activity['end'] ?? '' );
			$period  = array(
				'start' => $bounded->start,
				'end'   => $bounded->end,
			);
		} catch ( \Throwable $error ) {
			$available = false;
		}
		if ( ! $available ) {
			$counts = array_fill_keys( array_keys( $counts ), null );
		}
		return array(
			'health'         => $health,
			'score'          => $score,
			'recorded_at'    => $valid && $age >= 0 ? $created : null,
			'activity'       => array_merge( array( 'available' => $available ), $period, $counts ),
			'recommendation' => match ( $health ) {
				'healthy' => 'monitor',
				'warning', 'critical' => 'contact_support',
				default => 'request_check',
			},
		);
	}
}
