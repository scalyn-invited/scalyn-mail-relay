<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\LogsPage;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;

/**
 * Tests for LogsPage.
 *
 * Covers: capability gate, list view pagination, UUID validation, detail view
 * data retrieval, privacy invariants, status terminology, and output escaping.
 *
 * Repository interaction is verified via WpdbStub::prepare_calls (inspects
 * what queries were issued) and output buffering (inspects rendered HTML).
 *
 * Privacy invariants tested:
 *  - event_data column is never echoed.
 *  - subject, recipient, and body are not in the schema and cannot appear.
 *  - "Delivered" terminology never appears.
 *  - Raw unvalidated UUIDs are never echoed to the page.
 *  - XSS payloads in provider/source/event_label/event_message are escaped.
 */
final class LogsPageTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb                        = new WpdbStub();
		$GLOBALS['wpdb']                   = $this->wpdb;
		$GLOBALS['_test_current_user_can'] = array();
		$_GET                              = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_GET = array();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_page(): LogsPage {
		return new LogsPage( new MailLogRepository(), new TimelineRepository() );
	}

	/**
	 * Returns a minimal mail log row suitable for stubbing get_results_return.
	 *
	 * @param array $overrides Column overrides.
	 * @return array<string, mixed>
	 */
	private function make_log_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => '1',
				'message_uuid'     => '550e8400-e29b-41d4-a716-446655440000',
				'mailer'           => '',
				'provider'         => 'smtp',
				'status'           => 'accepted',
				'source_type'      => '',
				'source_name'      => '',
				'response_code'    => '',
				'response_message' => '',
				'attachment_count' => '0',
				'retry_count'      => '0',
				'created_at'       => '2026-08-24 10:00:00',
				'sent_at'          => '2026-08-24 10:00:00',
				'failed_at'        => null,
			),
			$overrides
		);
	}

	/**
	 * Returns a minimal timeline event row suitable for stubbing get_results_return.
	 *
	 * @param array $overrides Column overrides.
	 * @return array<string, mixed>
	 */
	private function make_timeline_event( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => '1',
				'message_uuid'  => '550e8400-e29b-41d4-a716-446655440000',
				'event_type'    => 'mail_sent',
				'event_status'  => 'accepted',
				'event_label'   => 'Message accepted by provider',
				'event_message' => 'OK',
				'event_data'    => '{"provider":"smtp","response_code":"250"}',
				'created_at'    => '2026-08-24 10:00:00',
			),
			$overrides
		);
	}

	/** Grants VIEW_LOGS capability for the current test request. */
	private function grant_view_logs(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::VIEW_LOGS ] = true;
	}

	/** Renders the page and returns captured HTML output. */
	private function render_and_capture(): string {
		ob_start();
		$this->make_page()->render();
		return (string) ob_get_clean();
	}

	// =========================================================================
	// CAPABILITY GATE
	// =========================================================================

	public function test_render_rejects_user_without_view_logs(): void {
		$this->expectException( RuntimeException::class );
		$this->make_page()->render();
	}

	public function test_render_rejects_user_with_unrelated_capability_only(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		$this->expectException( RuntimeException::class );
		$this->make_page()->render();
	}

	// =========================================================================
	// LIST VIEW — empty state
	// =========================================================================

	public function test_list_view_shows_no_activity_empty_state_when_no_rows(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'No email activity has been recorded yet', $output );
	}

	public function test_list_view_does_not_show_table_when_no_rows(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<table', $output );
	}

	// =========================================================================
	// LIST VIEW — pagination
	// =========================================================================

	public function test_list_view_page_one_passes_offset_zero_to_repository(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array();
		// No $_GET['paged'] — defaults to page 1.

		$this->render_and_capture();

		$last = end( $this->wpdb->prepare_calls );
		$this->assertSame( 0, $last['args'][1], 'Page 1 must pass offset 0 to find_recent().' );
	}

	public function test_list_view_page_two_passes_offset_25_to_repository(): void {
		$this->grant_view_logs();
		$_GET['paged']                  = '2';
		$this->wpdb->get_results_return = array();

		$this->render_and_capture();

		$last = end( $this->wpdb->prepare_calls );
		$this->assertSame( 25, $last['args'][1], 'Page 2 with PER_PAGE=25 must pass offset 25.' );
	}

	public function test_list_view_page_zero_clamps_to_page_one_offset_zero(): void {
		$this->grant_view_logs();
		$_GET['paged']                  = '0';
		$this->wpdb->get_results_return = array();

		$this->render_and_capture();

		$last = end( $this->wpdb->prepare_calls );
		$this->assertSame( 0, $last['args'][1], 'Page 0 must clamp to page 1 and pass offset 0.' );
	}

	public function test_list_view_non_numeric_paged_clamps_to_page_one_offset_zero(): void {
		// absint('abc') = 0; max(1, 0) = 1; offset = (1-1)*25 = 0.
		$this->grant_view_logs();
		$_GET['paged']                  = 'abc';
		$this->wpdb->get_results_return = array();

		$this->render_and_capture();

		$last = end( $this->wpdb->prepare_calls );
		$this->assertSame( 0, $last['args'][1], 'Non-numeric paged value must clamp to page 1 and pass offset 0.' );
	}

	public function test_list_view_limit_passed_to_repository_does_not_exceed_max_page_size(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array();

		$this->render_and_capture();

		$last = end( $this->wpdb->prepare_calls );
		$this->assertLessThanOrEqual(
			MailLogRepository::MAX_PAGE_SIZE,
			$last['args'][0],
			'Limit passed to repository must not exceed MAX_PAGE_SIZE.'
		);
	}

	public function test_list_view_shows_next_link_when_full_page_returned(): void {
		$this->grant_view_logs();
		// Fetch PER_PAGE + 1 (26 rows) to detect if more exist. 26 rows signals next page.
		$this->wpdb->get_results_return = array_fill( 0, 26, $this->make_log_row() );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Next', $output );
	}

	public function test_list_view_no_next_link_when_partial_page_returned(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array( $this->make_log_row() ); // Only 1 row.

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'next-page', $output );
	}

	public function test_list_view_shows_prev_link_on_page_two(): void {
		$this->grant_view_logs();
		$_GET['paged'] = '2';
		// Need at least one row so the table (with pagination) renders.
		$this->wpdb->get_results_return = array( $this->make_log_row() );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Previous', $output );
	}

	// =========================================================================
	// DETAIL VIEW — UUID validation
	// =========================================================================

	public function test_invalid_uuid_does_not_issue_any_db_queries(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = 'not-a-valid-uuid';
		$count_before         = count( $this->wpdb->prepare_calls );

		$this->render_and_capture();

		$this->assertCount(
			$count_before,
			$this->wpdb->prepare_calls,
			'An invalid UUID must not trigger any repository DB calls.'
		);
	}

	public function test_invalid_uuid_does_not_echo_the_raw_input(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = 'not-a-valid-uuid-injection-attempt';

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'not-a-valid-uuid-injection-attempt', $output );
	}

	public function test_invalid_uuid_shows_safe_error_state(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = 'bad-uuid';

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'not valid', $output );
	}

	public function test_valid_uuid_queries_log_repository(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = null;
		$this->wpdb->get_results_return = array();

		$this->render_and_capture();

		$uuid_found = false;
		foreach ( $this->wpdb->prepare_calls as $call ) {
			if ( in_array( $uuid, $call['args'], true ) ) {
				$uuid_found = true;
				break;
			}
		}
		$this->assertTrue( $uuid_found, 'UUID must appear in at least one prepare() call to the log repository.' );
	}

	public function test_valid_uuid_queries_both_log_and_timeline_repositories(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = '550e8400-e29b-41d4-a716-446655440000';

		$this->wpdb->get_row_return     = null;
		$this->wpdb->get_results_return = array();

		$count_before = count( $this->wpdb->prepare_calls );

		$this->render_and_capture();

		$this->assertGreaterThanOrEqual(
			$count_before + 2,
			count( $this->wpdb->prepare_calls ),
			'Detail view must issue prepare() calls to both log and timeline repositories.'
		);
	}

	public function test_unknown_uuid_shows_timeline_empty_state(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = '550e8400-e29b-41d4-a716-446655440000';

		$this->wpdb->get_row_return     = null;
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'No timeline events are available', $output );
	}

	// =========================================================================
	// validate_uuid() — direct unit coverage
	// =========================================================================

	public function test_validate_uuid_accepts_standard_lowercase_uuid(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$this->assertSame( $uuid, $this->make_page()->validate_uuid( $uuid ) );
	}

	public function test_validate_uuid_accepts_uppercase_uuid(): void {
		$uuid = '550E8400-E29B-41D4-A716-446655440000';
		$this->assertSame( $uuid, $this->make_page()->validate_uuid( $uuid ) );
	}

	public function test_validate_uuid_accepts_mixed_case_uuid(): void {
		$uuid = '550E8400-e29b-41D4-a716-446655440000';
		$this->assertSame( $uuid, $this->make_page()->validate_uuid( $uuid ) );
	}

	public function test_validate_uuid_rejects_empty_string(): void {
		$this->assertNull( $this->make_page()->validate_uuid( '' ) );
	}

	public function test_validate_uuid_rejects_plain_string(): void {
		$this->assertNull( $this->make_page()->validate_uuid( 'not-a-uuid' ) );
	}

	public function test_validate_uuid_rejects_uuid_with_wrong_segment_lengths(): void {
		$this->assertNull( $this->make_page()->validate_uuid( '550e8400-e29b-41d4-a716-4466554400' ) ); // last segment too short.
	}

	public function test_validate_uuid_rejects_sql_injection_attempt(): void {
		$this->assertNull( $this->make_page()->validate_uuid( "'; DROP TABLE wp_users; --" ) );
	}

	public function test_validate_uuid_rejects_xss_attempt(): void {
		$this->assertNull( $this->make_page()->validate_uuid( '<script>alert(1)</script>' ) );
	}

	public function test_validate_uuid_rejects_uuid_with_extra_characters(): void {
		$this->assertNull( $this->make_page()->validate_uuid( '550e8400-e29b-41d4-a716-446655440000-extra' ) );
	}

	// =========================================================================
	// STATUS TERMINOLOGY
	// =========================================================================

	public function test_accepted_row_renders_accepted_label(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'status' => 'accepted' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Accepted', $output );
	}

	public function test_failed_row_renders_failed_label(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array(
			$this->make_log_row(
				array(
					'status'    => 'failed',
					'sent_at'   => null,
					'failed_at' => '2026-08-24 10:00:00',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Failed', $output );
	}

	public function test_list_view_never_outputs_delivered(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'status' => 'accepted' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringNotContainsStringIgnoringCase( 'delivered', $output );
	}

	public function test_detail_view_never_outputs_delivered(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'message_uuid' => $uuid,
					'event_status' => 'accepted',
					'event_label'  => 'Message accepted by provider',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsStringIgnoringCase( 'delivered', $output );
	}

	// =========================================================================
	// PRIVACY — event_data never rendered
	// =========================================================================

	public function test_event_data_json_is_not_rendered_in_timeline(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'message_uuid' => $uuid,
					'event_data'   => '{"provider":"smtp","response_code":"250","secret":"SENSITIVE"}',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'event_data', $output );
		$this->assertStringNotContainsString( 'SENSITIVE', $output );
	}

	public function test_event_data_key_is_not_rendered_even_when_null(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'message_uuid' => $uuid,
					'event_data'   => null,
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'event_data', $output );
	}

	// =========================================================================
	// PRIVACY — response_message not rendered in list
	// =========================================================================

	public function test_response_message_is_not_rendered_in_list_view(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array(
			$this->make_log_row(
				array( 'response_message' => 'SMTP OK: queued as abc123SENSITIVITY' )
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'abc123SENSITIVITY', $output );
	}

	// =========================================================================
	// OUTPUT ESCAPING
	// =========================================================================

	public function test_provider_with_xss_payload_is_escaped_in_list(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'provider' => '<script>alert("xss")</script>' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_source_type_with_xss_payload_is_escaped_in_list(): void {
		$this->grant_view_logs();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'source_type' => '<img onerror="xss">' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<img', $output );
	}

	public function test_event_label_with_xss_payload_is_escaped_in_timeline(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'message_uuid' => $uuid,
					'event_label'  => '<script>alert("label-xss")</script>',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_event_message_with_xss_payload_is_escaped_in_timeline(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'message_uuid'  => $uuid,
					'event_message' => '<script>alert("msg-xss")</script>',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<script>', $output );
	}

	// =========================================================================
	// DETAIL VIEW — timeline ordering
	// =========================================================================

	public function test_detail_view_renders_timeline_events_in_repository_order(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'id'           => '1',
					'message_uuid' => $uuid,
					'event_label'  => 'First Event',
					'created_at'   => '2026-08-24 10:00:00',
				)
			),
			$this->make_timeline_event(
				array(
					'id'           => '2',
					'message_uuid' => $uuid,
					'event_label'  => 'Second Event',
					'created_at'   => '2026-08-24 10:00:01',
				)
			),
		);

		$output = $this->render_and_capture();

		$pos_first  = strpos( $output, 'First Event' );
		$pos_second = strpos( $output, 'Second Event' );

		$this->assertNotFalse( $pos_first );
		$this->assertNotFalse( $pos_second );
		$this->assertLessThan( $pos_second, $pos_first, 'First event must appear before second event in output.' );
	}

	// =========================================================================
	// DETAIL VIEW — log summary fields
	// =========================================================================

	public function test_detail_view_shows_uuid_in_summary(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row( array( 'message_uuid' => $uuid ) );
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( $uuid, $output );
	}

	public function test_detail_view_shows_empty_source_as_placeholder(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row(
			array(
				'message_uuid' => $uuid,
				'source_type'  => '',
				'source_name'  => '',
			)
		);
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		// Source column should show em-dash placeholder when both are empty.
		$this->assertStringContainsString( '—', $output );
	}

	// =========================================================================
	// ACCEPTED DISCLAIMER — conditional on log status
	// =========================================================================

	public function test_accepted_disclaimer_is_present_for_accepted_timeline(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row(
			array(
				'message_uuid' => $uuid,
				'status'       => 'accepted',
			)
		);
		$this->wpdb->get_results_return = array( $this->make_timeline_event( array( 'message_uuid' => $uuid ) ) );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'does not guarantee inbox delivery', $output );
	}

	public function test_accepted_disclaimer_is_absent_for_failed_timeline(): void {
		$this->grant_view_logs();
		$uuid                 = '550e8400-e29b-41d4-a716-446655440000';
		$_GET['message_uuid'] = $uuid;

		$this->wpdb->get_row_return     = $this->make_log_row(
			array(
				'message_uuid' => $uuid,
				'status'       => 'failed',
				'sent_at'      => null,
				'failed_at'    => '2026-08-24 10:00:00',
			)
		);
		$this->wpdb->get_results_return = array(
			$this->make_timeline_event(
				array(
					'message_uuid' => $uuid,
					'event_type'   => 'mail_failed',
					'event_status' => 'failed',
					'event_label'  => 'Message failed',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'does not guarantee inbox delivery', $output );
	}

	public function test_accepted_disclaimer_is_absent_when_log_not_found(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = '550e8400-e29b-41d4-a716-446655440000';

		$this->wpdb->get_row_return     = null;
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'does not guarantee inbox delivery', $output );
	}

	public function test_accepted_disclaimer_is_absent_for_invalid_uuid(): void {
		$this->grant_view_logs();
		$_GET['message_uuid'] = 'not-a-valid-uuid';

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'does not guarantee inbox delivery', $output );
	}
}
