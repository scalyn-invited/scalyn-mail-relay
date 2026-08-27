<?php
/**
 * Diagnostic result database repository.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the scalyn_diagnostics table.
 *
 * Each row is one DiagnosticCheckInterface result. Rows produced by the same
 * diagnostic run share the same diagnostic_uuid (the column is intentionally
 * not unique), forming a run snapshot per Engineering Handbook §6.3/§11.2.
 * Generating and grouping runs by diagnostic_uuid is the caller's
 * responsibility; this repository only persists what it is given.
 *
 * Storage note: the scalyn_diagnostics table (frozen schema) has no dedicated
 * columns for DiagnosticResult::$evidence or DiagnosticResult::$impact. Both are
 * folded into raw_result as structured JSON alongside DiagnosticResult::$raw
 * rather than widening the schema. Flagged to Kim in the PR description as the
 * smallest non-schema-changing option; result_message/recommended_action map
 * 1:1 to their own columns as before.
 *
 * Privacy: DiagnosticResult's own contract already forbids credentials/secrets
 * in evidence/raw; this repository does not add, strip, or otherwise interpret
 * that data, and never logs $wpdb->last_error or raw SQL on failure.
 *
 * Ownership: Yaj / Database.
 */
final class DiagnosticRepository {

	/**
	 * Maximum number of rows returned by find_recent().
	 *
	 * Prevents unbounded queries from saturating memory or overwhelming the UI.
	 */
	public const MAX_PAGE_SIZE = 250;

	/**
	 * Persists a single check result as one row in scalyn_diagnostics.
	 *
	 * @param string           $diagnostic_uuid Shared identifier for the run this result belongs to.
	 * @param string           $check_type      The check's category (DiagnosticCheckInterface::get_category()).
	 * @param string           $check_name      The check's identifier (DiagnosticCheckInterface::get_id()).
	 * @param DiagnosticResult $result          The normalized result to persist.
	 * @throws \RuntimeException When the DB write fails. Message is a fixed safe string
	 *                           with no SQL, last_error, or credential content.
	 */
	public function persist_result( string $diagnostic_uuid, string $check_type, string $check_name, DiagnosticResult $result ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_diagnostics';

		$raw_payload = array(
			'raw'      => $result->raw,
			'evidence' => $result->evidence,
			'impact'   => $result->impact,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional repository INSERT; diagnostic history is never cached.
		$inserted = $wpdb->insert(
			$table,
			array(
				'diagnostic_uuid'    => $diagnostic_uuid,
				'check_type'         => $check_type,
				'check_name'         => $check_name,
				'status'             => $result->status,
				'severity'           => $result->severity,
				'score'              => $result->score,
				'result_message'     => $result->message,
				'recommended_action' => $result->recommended_action,
				'raw_result'         => wp_json_encode( $raw_payload ),
				'created_at'         => current_time( 'mysql' ),
			)
		);
		if ( false === $inserted ) {
			// Fixed safe message: $wpdb->last_error and SQL are deliberately excluded.
			throw new \RuntimeException( 'Diagnostic result insert failed.' );
		}
	}

	/**
	 * Returns all rows for a given diagnostic run, oldest first.
	 *
	 * @param string $diagnostic_uuid The run identifier shared by all rows in the run.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_uuid( string $diagnostic_uuid ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_diagnostics';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE diagnostic_uuid = %s ORDER BY created_at ASC, id ASC", $diagnostic_uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); diagnostic rows must not be cached.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Returns the most recent diagnostic rows, newest first, bounded by MAX_PAGE_SIZE.
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

		$table = $wpdb->prefix . 'scalyn_diagnostics';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); diagnostic rows must not be cached.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results ? $results : array();
	}
}
