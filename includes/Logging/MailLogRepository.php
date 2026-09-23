<?php
/**
 * Mail log database repository.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Logging;

use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\MailStatus;
use Scalyn\MailRelay\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the scalyn_mail_logs table.
 *
 * Each mail log row represents the terminal outcome of a single dispatch attempt,
 * correlated by MailMessage::$uuid. Rows are inserted on first event and updated
 * if the same message_uuid is seen again (e.g. when a PREPARED row is later
 * resolved by a SENT or FAILED event).
 *
 * Privacy: this repository never stores subject lines, recipient addresses, message
 * bodies, attachment contents, or SMTP credentials. Only aggregate and diagnostic
 * fields permitted by the schema are written.
 *
 * Ownership: Kim / Logging.
 */
final class MailLogRepository {

	/**
	 * Maximum number of rows returned by find_recent().
	 *
	 * Prevents unbounded queries from saturating memory or overwhelming the UI.
	 */
	public const MAX_PAGE_SIZE = 250;

	/**
	 * Returns retained activity counts, not delivery counts, for a creation period.
	 *
	 * @param \Scalyn\MailRelay\Reporting\ReportPeriod $period Site-local boundaries.
	 * @param string|null                              $provider Exact provider ID; null means all.
	 * @return array Total, canonical status counts, and unrecognized status count.
	 * @throws \RuntimeException When reading fails.
	 */
	public function activity_totals( \Scalyn\MailRelay\Reporting\ReportPeriod $period, ?string $provider = null ): array {
		global $wpdb;
		$args  = array( $wpdb->prefix . 'scalyn_mail_logs', $period->start, $period->end );
		$where = $this->report_provider_filter( $provider, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed fragment; variadic args contain all 3 or 4 replacements, covered by query tests.
		$sql = $wpdb->prepare( "SELECT status, COUNT(*) AS row_count FROM %i WHERE created_at >= %s AND created_at < %s {$where} GROUP BY status", ...$args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared repository read, uncached operational evidence.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Reporting data unavailable.' );
		}
		$result = array(
			'total'        => 0,
			'statuses'     => array_fill_keys( MailStatus::all(), 0 ),
			'unrecognized' => 0,
		);
		foreach ( $rows as $row ) {
			$count            = max( 0, (int) $row['row_count'] );
			$result['total'] += $count;
			if ( array_key_exists( $row['status'], $result['statuses'] ) ) {
				$result['statuses'][ $row['status'] ] += $count;
			} else {
				$result['unrecognized'] += $count;
			}
		}
		return $result;
	}

	/**
	 * Returns a bounded recent-failure projection without provider response text.
	 *
	 * @param \Scalyn\MailRelay\Reporting\ReportPeriod $period Site-local creation period.
	 * @param string|null                              $provider Exact provider ID, null for all.
	 * @param int                                      $limit Maximum rows, clamped to 1–250.
	 * @return array Rows with correlation, provider, and timestamps only.
	 * @throws \RuntimeException When reading fails.
	 */
	public function report_failures( \Scalyn\MailRelay\Reporting\ReportPeriod $period, ?string $provider = null, int $limit = 25 ): array {
		global $wpdb;
		$args   = array( $wpdb->prefix . 'scalyn_mail_logs', $period->start, $period->end );
		$where  = $this->report_provider_filter( $provider, $args );
		$args[] = MailStatus::FAILED;
		$args[] = min( self::MAX_PAGE_SIZE, max( 1, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed fragment; variadic args contain all 5 or 6 replacements, covered by query tests.
		$sql = $wpdb->prepare( "SELECT id, message_uuid, provider, created_at, failed_at FROM %i WHERE created_at >= %s AND created_at < %s {$where} AND status = %s ORDER BY created_at DESC, id DESC LIMIT %d", ...$args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded repository read.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Reporting data unavailable.' );
		}
		return $rows;
	}

	/**
	 * Builds only a fixed SQL fragment and appends validated provider input.
	 *
	 * @param string|null $provider Provider ID; empty string explicitly selects unattributed rows.
	 * @param array       $args Prepared arguments.
	 * @return string Fixed SQL fragment.
	 * @throws \InvalidArgumentException When provider input is invalid.
	 */
	private function report_provider_filter( ?string $provider, array &$args ): string {
		if ( null === $provider ) {
			return '';
		}
		if ( strlen( $provider ) > 100 || ! preg_match( '/^[a-zA-Z0-9_-]*$/D', $provider ) ) {
			throw new \InvalidArgumentException( 'Invalid reporting provider.' );
		}
		$args[] = $provider;
		return 'AND provider = %s';
	}

	/**
	 * Inserts a new mail log row, or updates the existing row for the same message_uuid.
	 *
	 * The mailer column is intentionally stored as an empty string. The current
	 * mail event contract (MailMessage + SendResult) does not expose an authoritative
	 * mailer classification. Populating it from the provider ID would be an inference,
	 * not evidence, and could mislead future consumers of this column.
	 *
	 * @param MailMessage $message The dispatched message.
	 * @param SendResult  $result  The normalized send result.
	 * @param string      $status  A MailStatus constant value.
	 * @throws \RuntimeException When the DB write fails. Message is a fixed safe string
	 *                           with no SQL, last_error, or credential content.
	 */
	public function upsert( MailMessage $message, SendResult $result, string $status ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_mail_logs';
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for upsert; no appropriate cache layer for write-heavy log rows.
		$existing_id = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE message_uuid = %s", $message->uuid )
		);

		if ( null === $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional repository INSERT; no caching layer is appropriate for log writes.
			$inserted = $wpdb->insert(
				$table,
				array(
					'message_uuid'     => $message->uuid,
					// mailer is empty: the current event contract provides no authoritative
					// mailer classification. Do not infer from provider ID.
					'mailer'           => '',
					'provider'         => $result->provider,
					'status'           => $status,
					'source_type'      => (string) ( $message->context['source_type'] ?? '' ),
					'source_name'      => (string) ( $message->context['source_name'] ?? '' ),
					'response_code'    => (string) ( $result->response_code ?? '' ),
					'response_message' => $result->response_message,
					'attachment_count' => count( $message->attachments ),
					'retry_count'      => 0,
					'created_at'       => $now,
					'sent_at'          => MailStatus::ACCEPTED === $status ? $now : null,
					'failed_at'        => MailStatus::FAILED === $status ? $now : null,
				)
			);
			if ( false === $inserted ) {
				// Fixed safe message: $wpdb->last_error and SQL are deliberately excluded.
				throw new \RuntimeException( 'Mail log insert failed.' );
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional repository UPDATE; no cache layer is appropriate for log write-backs.
			$updated = $wpdb->update(
				$table,
				array(
					'status'           => $status,
					'provider'         => $result->provider,
					'response_code'    => (string) ( $result->response_code ?? '' ),
					'response_message' => $result->response_message,
					'sent_at'          => MailStatus::ACCEPTED === $status ? $now : null,
					'failed_at'        => MailStatus::FAILED === $status ? $now : null,
				),
				array( 'message_uuid' => $message->uuid )
			);
			if ( false === $updated ) {
				// Fixed safe message: $wpdb->last_error and SQL are deliberately excluded.
				throw new \RuntimeException( 'Mail log update failed.' );
			}
		}
	}

	/**
	 * Returns the mail log row for a given message UUID, or null if not found.
	 *
	 * @param string $uuid The MailMessage UUID to look up.
	 * @return array<string, mixed>|null Row as associative array, or null when absent.
	 */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_mail_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE message_uuid = %s", $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); log rows are write-heavy and must not be cached.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the most recent mail log rows, newest first, bounded by MAX_PAGE_SIZE.
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

		$table = $wpdb->prefix . 'scalyn_mail_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); log rows are write-heavy and must not be cached.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Returns recent mail log rows for one terminal status, newest first.
	 *
	 * The status is restricted to the public terminal outcomes exposed by the
	 * admin log filter. Invalid values return an empty result without querying.
	 *
	 * @param string $status MailStatus::ACCEPTED or MailStatus::FAILED.
	 * @param int    $limit  Number of rows to return; clamped to 1–MAX_PAGE_SIZE.
	 * @param int    $offset Zero-based row offset; negative values treated as 0.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_recent_by_status( string $status, int $limit = 25, int $offset = 0 ): array {
		global $wpdb;

		if ( ! in_array( $status, array( MailStatus::ACCEPTED, MailStatus::FAILED ), true ) ) {
			return array();
		}

		$limit  = min( max( 1, $limit ), self::MAX_PAGE_SIZE );
		$offset = max( 0, $offset );
		$table  = $wpdb->prefix . 'scalyn_mail_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $status, $limit, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); log rows are write-heavy and must not be cached.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Returns mail log row counts grouped by status for the last N days.
	 *
	 * Used as the operational-reliability evidence source for health scoring
	 * (HealthScorer): counts of MailStatus::ACCEPTED vs MailStatus::FAILED in
	 * the recent window. Statuses with zero rows in the window are omitted
	 * from the returned array rather than reported as zero.
	 *
	 * @param int $days Size of the recent window in days; clamped to a minimum of 1.
	 * @return array<string, int> Status value => row count.
	 */
	public function count_recent_by_status( int $days = 7 ): array {
		global $wpdb;

		$days  = max( 1, $days );
		$table = $wpdb->prefix . 'scalyn_mail_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT status, COUNT(*) as row_count FROM {$table} WHERE created_at >= DATE_SUB(%s, INTERVAL %d DAY) GROUP BY status", current_time( 'mysql' ), $days );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); log rows are write-heavy and must not be cached.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( '' !== $status ) {
				$counts[ $status ] = (int) ( $row['row_count'] ?? 0 );
			}
		}

		return $counts;
	}
}
