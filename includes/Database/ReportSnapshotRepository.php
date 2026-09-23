<?php
/**
 * Coordinates consistent read-only reporting across owned repositories.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Diagnostics\RecommendationEngine;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Reporting\ReportPeriod;
use Scalyn\MailRelay\Reporting\ReportSnapshot;

defined( 'ABSPATH' ) || exit;

/** Internal read contract; privileged external callers must enforce authorization. */
final class ReportSnapshotRepository {

	/**
	 * Receives the shared services without exposing tables to report consumers.
	 *
	 * @param MailLogRepository     $mail Mail read model.
	 * @param HealthScoreRepository $health Health read model.
	 * @param DiagnosticRepository  $diagnostics Diagnostic read model.
	 * @param RecommendationEngine  $recommendations Safe rule-based guidance.
	 */
	public function __construct(
		private readonly MailLogRepository $mail,
		private readonly HealthScoreRepository $health,
		private readonly DiagnosticRepository $diagnostics,
		private readonly RecommendationEngine $recommendations
	) {}

	/**
	 * Captures one InnoDB consistent view. Never joins an existing transaction.
	 *
	 * @param ReportPeriod $period Site-local half-open period.
	 * @param string|null  $provider Mail provider only; null means all.
	 * @param string       $cadence Cadence used to assess evidence freshness at capture.
	 * @return ReportSnapshot Immutable in-memory payload, not a persisted report file.
	 * @throws \RuntimeException When a consistent capture cannot be obtained.
	 */
	public function capture( ReportPeriod $period, ?string $provider = null, string $cadence = 'daily' ): ReportSnapshot {
		global $wpdb;
		$started = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Engine check is required for cross-table snapshot consistency.
			$engines = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s) AND ENGINE = 'InnoDB'",
					$wpdb->prefix . 'scalyn_mail_logs',
					$wpdb->prefix . 'scalyn_diagnostics',
					$wpdb->prefix . 'scalyn_health_scores'
				)
			);
			if ( '3' !== (string) $engines || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			// SET TRANSACTION fails inside an active transaction, avoiding an implicit commit.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit read transaction boundary, no cached state.
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit read transaction boundary, no cached state.
			if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) {
				throw new \RuntimeException();
			}
			$started = true;
			$now     = time();
			$totals  = $this->mail->activity_totals( $period, $provider );
			$failed  = $this->mail->report_failures( $period, $provider, 25 );
			$trend   = $this->health->daily_trend( $period );
			$score   = $this->health->report_latest( $period );
			$rows    = $this->diagnostics->report_findings( $period );
			$data    = array(
				'version'          => 1,
				'report_uuid'      => wp_generate_uuid4(),
				'generated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
				'period'           => array(
					'start'         => $period->start,
					'end_exclusive' => $period->end,
					'timezone'      => wp_timezone()->getName(),
				),
				'mail'             => array(
					'provider'         => $provider,
					'totals'           => $totals,
					'recent_failures'  => $failed,
					'failure_limit'    => 25,
					'failures_omitted' => max( 0, $totals['statuses']['failed'] - count( $failed ) ),
				),
				'health'           => array(
					'scope'        => 'site-wide',
					'daily_trend'  => $trend,
					'latest_score' => $score,
				),
				'diagnostics'      => array(
					'scope'     => 'site-wide',
					'selection' => 'latest-retained-run-in-period',
					'findings'  => $rows,
					'limit'     => 250,
				),
				'recommendations'  => $this->recommendations->recommend( $rows, $cadence, $now ),
				'freshness'        => array(
					'evaluated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
					'cadence'          => $cadence,
				),
				'limitations'      => array(
					'Accepted is provider acknowledgement, not confirmed delivery or inbox placement.',
					'Configuration scores do not verify message authentication or delivery.',
					'Only retained evidence is captured; missing evidence is not a pass. Retention may remove individual findings and references.',
					'Mail counts are current outcomes by creation time, not lifetime totals or lifecycle event counts.',
					'Health and diagnostics are site-wide, independent of the mail provider filter. Latest score and run may not correlate.',
					'Daily trends aggregate retained scores; individual references are supplied only for the latest score, selected findings and recent failures.',
					'Timestamps were stored in site-local time; historical timezone changes and DST ambiguity cannot be reconstructed.',
					'Recommendation freshness is evaluated at generation, not at the end of a historical reporting period.',
				),
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Ends the owned read transaction.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException();
			}
			$started = false;
			return new ReportSnapshot( $data );
		} catch ( \Throwable $error ) {
			if ( $started ) {
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Releases only the transaction owned by this capture.
					$wpdb->query( 'ROLLBACK' );
				} catch ( \Throwable $rollback_error ) {
					// Never leak database error text through the reporting boundary.
					$started = false;
				}
			}
			throw new \RuntimeException( 'Report snapshot unavailable.' );
		}
	}
}
