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
	 * Persists a health score snapshot as one row in scalyn_health_scores.
	 *
	 * @param HealthScoreResult $result The score to persist.
	 * @throws \RuntimeException When the DB write fails. Message is a fixed safe string
	 *                           with no SQL, last_error, or credential content.
	 */
	public function persist( HealthScoreResult $result ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_health_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional repository INSERT; health score history is never cached.
		$inserted = $wpdb->insert(
			$table,
			array(
				'score_uuid'           => wp_generate_uuid4(),
				'overall_score'        => $result->overall_score,
				'deliverability_score' => null,
				'dns_score'            => $result->dns_score,
				'provider_score'       => $result->provider_score,
				'failure_score'        => $result->failure_score,
				'security_score'       => $result->security_score,
				'summary'              => $result->summary,
				'created_at'           => current_time( 'mysql' ),
			)
		);
		if ( false === $inserted ) {
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
