<?php
/**
 * Health score database repository.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Diagnostics\HealthScoreResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the scalyn_health_scores table.
 *
 * Each row is one HealthScorer::score() snapshot. deliverability_score is
 * intentionally never written here — the Engineering Handbook (§12.3)
 * treats Deliverability Score as a separate, later concept from Health
 * Score, out of scope for this repository.
 *
 * Ownership: Yaj / Database.
 */
final class HealthScoreRepository {

	/**
	 * Maximum number of rows returned by find_recent().
	 *
	 * Prevents unbounded queries from saturating memory or overwhelming the UI.
	 */
	public const MAX_PAGE_SIZE = 250;

	/**
	 * Returns site-wide daily configuration-score trends, never provider attribution.
	 *
	 * Missing days are absent, not zero. Null scores do not contribute to averages.
	 *
	 * @param \Scalyn\MailRelay\Reporting\ReportPeriod $period Site-local period, at most 366 days.
	 * @return array Daily count, scored count, average/min/max, and latest evidence time.
	 * @throws \RuntimeException When reading fails.
	 */
	public function daily_trend( \Scalyn\MailRelay\Reporting\ReportPeriod $period ): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT DATE(created_at) AS day, COUNT(*) AS snapshot_count, COUNT(overall_score) AS scored_count, AVG(overall_score) AS average_score, MIN(overall_score) AS minimum_score, MAX(overall_score) AS maximum_score, MAX(created_at) AS latest_at FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY DATE(created_at) ORDER BY day ASC LIMIT 367',
			$wpdb->prefix . 'scalyn_health_scores',
			$period->start,
			$period->end
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded repository aggregation, no cached evidence.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Reporting data unavailable.' );
		}
		foreach ( $rows as &$row ) {
			$row['snapshot_count'] = (int) $row['snapshot_count'];
			$row['scored_count']   = (int) $row['scored_count'];
			foreach ( array( 'average_score', 'minimum_score', 'maximum_score' ) as $key ) {
				$row[ $key ] = null === $row[ $key ] ? null : (float) $row[ $key ];
			}
		}
		return $rows;
	}

	/**
	 * Persists a health score snapshot as one row in scalyn_health_scores.
	 *
	 * @param HealthScoreResult $result The score to persist.
	 * @param string|null       $run_uuid Optional diagnostic run UUID for new correlated snapshots.
	 * @param string|null       $created_at Shared publication timestamp.
	 * @throws \RuntimeException When the DB write fails. Message is a fixed safe string
	 *                           with no SQL, last_error, or credential content.
	 */
	public function persist( HealthScoreResult $result, ?string $run_uuid = null, ?string $created_at = null ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_health_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional repository INSERT; health score history is never cached.
		$inserted = $wpdb->insert(
			$table,
			array(
				'score_uuid'           => $run_uuid ?? wp_generate_uuid4(),
				'overall_score'        => $result->overall_score,
				'deliverability_score' => null,
				'dns_score'            => $result->dns_score,
				'provider_score'       => $result->provider_score,
				'failure_score'        => $result->failure_score,
				'security_score'       => $result->security_score,
				'summary'              => $result->summary,
				'created_at'           => $created_at ?? current_time( 'mysql' ),
			)
		);
		if ( 1 !== $inserted ) {
			// Fixed safe message: $wpdb->last_error and SQL are deliberately excluded.
			throw new \RuntimeException( 'Health score insert failed.' );
		}
	}

	/**
	 * Returns the single most recent health score row, or null if none exist yet.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_latest(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_health_scores';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", 1 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); health score rows must not be cached.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the most recent health score rows, newest first, bounded by MAX_PAGE_SIZE.
	 *
	 * The $limit parameter is silently clamped to 1–MAX_PAGE_SIZE. Callers requesting
	 * more than MAX_PAGE_SIZE rows receive MAX_PAGE_SIZE rows.
	 *
	 * @param int $limit  Number of rows to return; clamped to 1–MAX_PAGE_SIZE.
	 * @param int $offset Zero-based row offset for pagination; negative values treated as 0.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_recent( int $limit = 25, int $offset = 0 ): array {
		global $wpdb;

		$limit  = min( max( 1, $limit ), self::MAX_PAGE_SIZE );
		$offset = max( 0, $offset );

		$table = $wpdb->prefix . 'scalyn_health_scores';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); health score rows must not be cached.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results ? $results : array();
	}
}
