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
}
