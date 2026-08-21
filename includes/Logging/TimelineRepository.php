<?php
/**
 * Mail timeline database repository.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the scalyn_mail_timeline table.
 *
 * Each row represents a single lifecycle event appended to the timeline for a
 * given message_uuid. The timeline is append-only: rows are never updated or
 * deleted by this module. Chronological ordering is preserved by (created_at, id).
 *
 * Privacy: event_data must contain only pre-allowlisted, non-credential fields.
 * Callers are responsible for allowlisting before passing event_data to this method.
 *
 * Ownership: Kim / Logging.
 */
final class TimelineRepository {

	/**
	 * Appends a lifecycle event to the timeline for the given message UUID.
	 *
	 * The event_data argument should be a pre-allowlisted array containing only
	 * safe, non-credential fields. The repository JSON-encodes the array before
	 * storage. Passing null stores NULL in the column.
	 *
	 * @param string      $uuid          MailMessage UUID (correlation key with mail_logs).
	 * @param string      $event_type    Event identifier (e.g. 'mail_sent', 'mail_failed').
	 * @param string      $event_status  MailStatus constant value at the time of the event.
	 * @param string      $event_label   Human-readable event label.
	 * @param string|null $event_message Optional descriptive message from the provider.
	 * @param array|null  $event_data    Allowlisted supplemental data; must not contain credentials.
	 * @throws \RuntimeException When the DB write fails. Message is a fixed safe string
	 *                           with no SQL, last_error, or credential content.
	 */
	public function insert_event(
		string $uuid,
		string $event_type,
		string $event_status,
		string $event_label,
		?string $event_message,
		?array $event_data
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_mail_timeline';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional append-only repository INSERT; timeline events are never cached.
		$inserted = $wpdb->insert(
			$table,
			array(
				'message_uuid'  => $uuid,
				'event_type'    => $event_type,
				'event_status'  => $event_status,
				'event_label'   => $event_label,
				'event_message' => $event_message,
				'event_data'    => null !== $event_data ? wp_json_encode( $event_data ) : null,
				'created_at'    => current_time( 'mysql' ),
			)
		);
		if ( false === $inserted ) {
			// Fixed safe message: $wpdb->last_error and SQL are deliberately excluded.
			throw new \RuntimeException( 'Timeline event insert failed.' );
		}
	}

	/**
	 * Returns all timeline events for a message UUID in chronological order.
	 *
	 * Results are ordered by (created_at ASC, id ASC). The id tiebreaker ensures
	 * deterministic ordering when two events share an identical created_at timestamp.
	 *
	 * @param string $uuid The MailMessage UUID to look up.
	 * @return array<int, array<string, mixed>> Events as associative arrays, oldest first.
	 */
	public function find_by_uuid( string $uuid ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'scalyn_mail_timeline';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is derived from $wpdb->prefix, not user input.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE message_uuid = %s ORDER BY created_at ASC, id ASC", $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare(); timeline events must not be cached.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results ? $results : array();
	}
}
