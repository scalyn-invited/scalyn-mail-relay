<?php
/**
 * Deterministic email health scoring service.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Mail\MailStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Computes a deterministic, explainable Health Score from diagnostic check
 * results and recent mail history.
 *
 * Pure computation only: this class does not read from or write to the
 * database. Callers supply already-fetched diagnostic rows (e.g. from
 * DiagnosticRepository::find_latest_run()) and mail status counts (e.g.
 * from MailLogRepository::count_recent_by_status()).
 *
 * Scoring policy (V1, documented here rather than scattered across checks):
 * - Each check's status maps to points: pass=100, warn=60, fail=0. Checks
 *   with status 'unknown' or 'error' are excluded from their category's
 *   average rather than penalized — an inability to check is not evidence
 *   of a problem.
 * - Component weights follow the Engineering Handbook's five-part formula
 *   (DNS & auth 30%, Provider & transport 25%, Operational reliability 25%,
 *   Security posture 10%, WordPress/system health 10%), but only the first
 *   three currently have a real evidence source in this codebase:
 *     - Security posture has no dedicated check/category yet.
 *     - WordPress/system health has no check AND no column in
 *       wp_scalyn_health_scores to persist it into.
 *   Both are excluded from V1 entirely (never computed, never guessed) —
 *   not merely "unknown for this run." $summary documents this explicitly.
 * - The overall score is a weighted average renormalized across whichever
 *   of the three available components actually have evidence in a given
 *   run. If none do, score() returns null rather than fabricating a score
 *   (overall_score is NOT NULL in the schema, so "no data" must mean "no
 *   row persisted", not "a meaningless zero").
 *
 * Ownership: Yaj / Diagnostics.
 */
final class HealthScorer {

	private const DNS_WEIGHT      = 30;
	private const PROVIDER_WEIGHT = 25;
	private const FAILURE_WEIGHT  = 25;

	private const STATUS_POINTS = array(
		'pass' => 100,
		'warn' => 60,
		'fail' => 0,
	);

	/**
	 * Computes a Health Score from diagnostic results and recent mail history.
	 *
	 * @param array<int, array<string, mixed>> $diagnostic_rows    Rows from a single diagnostic
	 *                                                              run (e.g. find_latest_run()['results']).
	 *                                                              Each row must have 'check_type'
	 *                                                              and 'status' keys.
	 * @param array<string, int>               $mail_status_counts Status => count, e.g. from
	 *                                                              MailLogRepository::count_recent_by_status().
	 * @return HealthScoreResult|null Null when no component has any evidence to score from.
	 */
	public function score( array $diagnostic_rows, array $mail_status_counts ): ?HealthScoreResult {
		$dns_score      = self::category_score( $diagnostic_rows, 'dns' );
		$provider_score = self::category_score( $diagnostic_rows, 'smtp' );
		$failure_score  = self::failure_score( $mail_status_counts );

		$components = array();
		if ( null !== $dns_score ) {
			$components['DNS & authentication'] = array( $dns_score, self::DNS_WEIGHT );
		}
		if ( null !== $provider_score ) {
			$components['Provider & transport'] = array( $provider_score, self::PROVIDER_WEIGHT );
		}
		if ( null !== $failure_score ) {
			$components['Operational reliability'] = array( $failure_score, self::FAILURE_WEIGHT );
		}

		if ( array() === $components ) {
			return null;
		}

		$weighted_sum = 0;
		$weight_total = 0;
		foreach ( $components as list( $score, $weight ) ) {
			$weighted_sum += $score * $weight;
			$weight_total += $weight;
		}

		return new HealthScoreResult(
			overall_score: intval( $weighted_sum / $weight_total ),
			dns_score: $dns_score,
			provider_score: $provider_score,
			failure_score: $failure_score,
			security_score: null,
			summary: self::build_summary( array_keys( $components ) )
		);
	}

	/**
	 * Averages check-status points for a given check_type category.
	 *
	 * Rows with status 'unknown' or 'error' are excluded, not scored as zero.
	 * Returns null when no countable row exists for this category.
	 *
	 * @param array<int, array<string, mixed>> $rows       Diagnostic result rows.
	 * @param string                           $check_type The check_type to filter by (e.g. 'dns').
	 */
	private static function category_score( array $rows, string $check_type ): ?int {
		$total = 0;
		$count = 0;

		foreach ( $rows as $row ) {
			if ( ( $row['check_type'] ?? '' ) !== $check_type ) {
				continue;
			}

			$status = (string) ( $row['status'] ?? '' );
			if ( ! isset( self::STATUS_POINTS[ $status ] ) ) {
				continue;
			}

			$total += self::STATUS_POINTS[ $status ];
			++$count;
		}

		return $count > 0 ? intval( $total / $count ) : null;
	}

	/**
	 * Derives the operational-reliability score from recent accepted/failed
	 * mail counts as a simple success rate.
	 *
	 * Returns null when there is no recent mail history to derive a rate from.
	 *
	 * @param array<string, int> $mail_status_counts Status => count.
	 */
	private static function failure_score( array $mail_status_counts ): ?int {
		$accepted = $mail_status_counts[ MailStatus::ACCEPTED ] ?? 0;
		$failed   = $mail_status_counts[ MailStatus::FAILED ] ?? 0;
		$total    = $accepted + $failed;

		return $total > 0 ? intval( ( $accepted / $total ) * 100 ) : null;
	}

	/**
	 * Builds a plain-English summary of which components were included and
	 * excluded, and why — required so the result is traceable to evidence
	 * rather than an opaque number.
	 *
	 * @param string[] $included_labels Human-readable labels of components with evidence.
	 */
	private static function build_summary( array $included_labels ): string {
		$parts   = array();
		$parts[] = 'Health score based on: ' . implode( ', ', $included_labels ) . '.';
		$parts[] = 'Security posture and WordPress/system health are not yet evaluated by this version and are excluded, not scored as zero.';

		return implode( ' ', $parts );
	}
}
