<?php
/**
 * Email Logs admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Capability-gated Email Logs admin page.
 *
 * Reads GET parameters safely, resolves log and timeline data from the
 * injected repositories, and delegates rendering to view files.
 * No SQL or repository construction occurs in view files.
 *
 * Routing:
 *   No message_uuid GET param → paginated list view (logs.php).
 *   Valid message_uuid GET param → detail/timeline view (logs-detail.php).
 *   Invalid message_uuid GET param → detail view with error state; no DB queries made.
 *
 * Ownership: Kim / Admin.
 */
final class LogsPage {

	/**
	 * Number of log rows returned per page.
	 *
	 * Must not exceed MailLogRepository::MAX_PAGE_SIZE. Kept small so that
	 * the admin list remains readable on typical screens.
	 */
	private const PER_PAGE = 25;

	/**
	 * Creates a new LogsPage with the required repository dependencies.
	 *
	 * @param MailLogRepository  $log_repo      Repository for reading mail log rows.
	 * @param TimelineRepository $timeline_repo Repository for reading timeline events.
	 */
	public function __construct(
		private readonly MailLogRepository $log_repo,
		private readonly TimelineRepository $timeline_repo
	) {}

	/**
	 * Performs a capability check and renders the appropriate view.
	 *
	 * Routes to the detail view when a message_uuid GET parameter is present,
	 * otherwise renders the paginated list view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_LOGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view email logs.', 'scalyn-mail-relay' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only GET navigation; no state change. Value is validated by validate_uuid() before any use.
		$raw_uuid = isset( $_GET['message_uuid'] ) ? wp_unslash( (string) $_GET['message_uuid'] ) : '';

		if ( '' !== $raw_uuid ) {
			$this->render_detail( $raw_uuid );
		} else {
			$this->render_list();
		}
	}

	/**
	 * Prepares paginated list data and renders the log table view.
	 *
	 * Variables passed to logs.php:
	 *   array $rows          Log rows, newest first; may be empty.
	 *   int   $page          Current page number (≥ 1).
	 *   bool  $has_next_page Whether additional rows may exist beyond this page.
	 */
	private function render_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET navigation; no state change.
		$paged  = isset( $_GET['paged'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['paged'] ) ) : '1';
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';

		// Validate status filter against whitelist.
		if ( $status && ! in_array( $status, array( 'accepted', 'failed' ), true ) ) {
			$status = '';
		}

		$page   = max( 1, absint( $paged ) );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		// Fetch one extra row to determine if there's a next page.
		$rows          = $this->log_repo->find_recent( self::PER_PAGE + 1, $offset );
		$has_next_page = count( $rows ) > self::PER_PAGE;

		// Trim to page size.
		$rows = array_slice( $rows, 0, self::PER_PAGE, true );

		// Filter by status if requested.
		if ( $status ) {
			$rows = array_filter(
				$rows,
				function ( $row ) use ( $status ) {
					return ( $row['status'] ?? '' ) === $status;
				}
			);
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/logs.php';
	}

	/**
	 * Validates the raw UUID, retrieves data if valid, and renders the detail view.
	 *
	 * When UUID validation fails no repository calls are made, preventing any
	 * possibility of injected input reaching the database layer.
	 *
	 * Variables passed to logs-detail.php:
	 *   string     $message_uuid Validated UUID string, or '' when $uuid_error is true.
	 *   bool       $uuid_error   True when the supplied UUID failed format validation.
	 *   array|null $log          Mail log row, or null when not found or UUID invalid.
	 *   array      $timeline     Timeline events; empty array when not found or UUID invalid.
	 *
	 * @param string $raw_uuid Untrusted UUID string from GET; must not be echoed directly.
	 */
	private function render_detail( string $raw_uuid ): void {
		$validated    = $this->validate_uuid( $raw_uuid );
		$uuid_error   = null === $validated;
		$message_uuid = null !== $validated ? $validated : '';
		$log          = null;
		$timeline     = array();

		if ( ! $uuid_error ) {
			$log      = $this->log_repo->find_by_uuid( $message_uuid );
			$timeline = $this->timeline_repo->find_by_uuid( $message_uuid );
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/logs-detail.php';
	}

	/**
	 * Validates a UUID string against the standard 8-4-4-4-12 hexadecimal format.
	 *
	 * Returns the UUID unchanged if valid, or null if the format does not match.
	 * Case-insensitive to accommodate both upper- and lower-case hex from different
	 * generator implementations (e.g. wp_generate_uuid4() returns lower-case).
	 *
	 * @param string $uuid The UUID string to validate.
	 * @return string|null The UUID if valid; null if the format is invalid.
	 */
	public function validate_uuid( string $uuid ): ?string {
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			return $uuid;
		}
		return null;
	}
}
