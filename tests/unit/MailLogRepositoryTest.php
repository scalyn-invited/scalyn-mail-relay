<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\MailStatus;
use Scalyn\MailRelay\Mail\SendResult;

/**
 * Unit tests for MailLogRepository.
 *
 * Verifies that upsert() writes the correct data to scalyn_mail_logs and that
 * find_by_uuid()/find_recent() delegate correctly to $wpdb.
 *
 * Privacy invariants verified:
 *  - mailer column is always '' (no inferred value).
 *  - subject, recipient, and body are never present in any insert or update.
 *  - retry_count is 0 on initial insert (not incremented without RETRIED evidence).
 *  - find_recent() enforces a bounded maximum page size.
 */
final class MailLogRepositoryTest extends TestCase {

	private MailLogRepository $repo;
	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb                    = new WpdbStub();
		$GLOBALS['wpdb']               = $this->wpdb;
		$GLOBALS['_test_current_time'] = '2026-08-21 12:00:00';
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_time'] = null;
		unset( $GLOBALS['wpdb'] );
		$this->repo = new MailLogRepository();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_repo(): MailLogRepository {
		return new MailLogRepository();
	}

	private function make_message( array $overrides = array() ): MailMessage {
		return new MailMessage(
			uuid: $overrides['uuid'] ?? 'test-uuid-0001',
			from: $overrides['from'] ?? 'sender@example.com',
			to: $overrides['to'] ?? array( 'recipient@example.com' ),
			subject: $overrides['subject'] ?? 'Test Subject',
			body: $overrides['body'] ?? '<p>Test body</p>',
			content_type: $overrides['content_type'] ?? 'text/html',
			headers: $overrides['headers'] ?? array(),
			attachments: $overrides['attachments'] ?? array(),
			context: $overrides['context'] ?? array(
				'source_type' => 'plugin',
				'source_name' => 'PHPUnit',
			)
		);
	}

	private function make_success_result( array $overrides = array() ): SendResult {
		return new SendResult(
			success: true,
			provider: $overrides['provider'] ?? 'smtp',
			provider_message_id: $overrides['provider_message_id'] ?? null,
			response_code: $overrides['response_code'] ?? null,
			response_message: $overrides['response_message'] ?? 'Message accepted by the configured SMTP server.',
			retryable: false,
			failure_category: null,
			metadata: $overrides['metadata'] ?? array()
		);
	}

	private function make_failed_result( array $overrides = array() ): SendResult {
		return new SendResult(
			success: false,
			provider: $overrides['provider'] ?? 'smtp',
			provider_message_id: null,
			response_code: $overrides['response_code'] ?? null,
			response_message: $overrides['response_message'] ?? 'SMTP transport failed.',
			retryable: $overrides['retryable'] ?? false,
			failure_category: $overrides['failure_category'] ?? 'network',
			metadata: array()
		);
	}

	/** Returns the data array from the first recorded INSERT. */
	private function first_insert_data(): array {
		$this->assertNotEmpty( $this->wpdb->inserts, 'Expected at least one INSERT.' );
		return $this->wpdb->inserts[0]['data'];
	}

	// -------------------------------------------------------------------------
	// upsert() — INSERT path (no existing row)
	// -------------------------------------------------------------------------

	public function test_upsert_inserts_new_row_when_uuid_not_found(): void {
		$this->wpdb->get_var_return = null; // No existing row.

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertCount( 1, $this->wpdb->inserts );
	}

	public function test_upsert_insert_targets_mail_logs_table(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertStringContainsString( 'scalyn_mail_logs', $this->wpdb->inserts[0]['table'] );
	}

	public function test_upsert_includes_message_uuid_in_insert(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'uuid' => 'uuid-abc-123' ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( 'uuid-abc-123', $this->first_insert_data()['message_uuid'] );
	}

	/**
	 * Privacy: mailer must remain '' — never inferred from provider ID or any other field.
	 */
	public function test_upsert_mailer_is_empty_string_not_inferred(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( '', $this->first_insert_data()['mailer'] );
	}

	/**
	 * Privacy: subject line must never be stored in the mail log row.
	 */
	public function test_upsert_does_not_store_subject(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'subject' => 'Sensitive Subject Line' ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertArrayNotHasKey( 'subject', $this->first_insert_data() );
	}

	/**
	 * Privacy: recipient addresses must never be stored in the mail log row.
	 */
	public function test_upsert_does_not_store_recipient(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'to' => array( 'user@example.com' ) ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$data = $this->first_insert_data();
		$this->assertArrayNotHasKey( 'to', $data );
		$this->assertArrayNotHasKey( 'recipient', $data );
		$this->assertArrayNotHasKey( 'recipients', $data );
	}

	/**
	 * Privacy: message body must never be stored in the mail log row.
	 */
	public function test_upsert_does_not_store_body(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'body' => 'Sensitive email body content.' ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertArrayNotHasKey( 'body', $this->first_insert_data() );
	}

	public function test_upsert_stores_accepted_status_for_successful_send(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( MailStatus::ACCEPTED, $this->first_insert_data()['status'] );
	}

	public function test_upsert_stores_failed_status_for_failed_send(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_failed_result(), MailStatus::FAILED );

		$this->assertSame( MailStatus::FAILED, $this->first_insert_data()['status'] );
	}

	public function test_upsert_sets_sent_at_for_accepted_status(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$data = $this->first_insert_data();
		$this->assertSame( '2026-08-21 12:00:00', $data['sent_at'] );
		$this->assertNull( $data['failed_at'] );
	}

	public function test_upsert_sets_failed_at_for_failed_status(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_failed_result(), MailStatus::FAILED );

		$data = $this->first_insert_data();
		$this->assertSame( '2026-08-21 12:00:00', $data['failed_at'] );
		$this->assertNull( $data['sent_at'] );
	}

	/**
	 * retry_count must be 0 on initial insert — never incremented without a RETRIED event.
	 */
	public function test_upsert_retry_count_is_zero_on_initial_insert(): void {
		$this->wpdb->get_var_return = null;

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( 0, $this->first_insert_data()['retry_count'] );
	}

	public function test_upsert_stores_attachment_count_as_integer(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message(
			array( 'attachments' => array( '/path/a.pdf', '/path/b.pdf', '/path/c.pdf' ) )
		);

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( 3, $this->first_insert_data()['attachment_count'] );
	}

	public function test_upsert_reads_source_type_from_context(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message(
			array(
				'context' => array(
					'source_type' => 'woocommerce',
					'source_name' => 'order_complete',
				),
			)
		);

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$data = $this->first_insert_data();
		$this->assertSame( 'woocommerce', $data['source_type'] );
		$this->assertSame( 'order_complete', $data['source_name'] );
	}

	public function test_upsert_source_type_falls_back_to_empty_when_context_missing(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'context' => array() ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( '', $this->first_insert_data()['source_type'] );
	}

	public function test_upsert_source_name_falls_back_to_empty_when_context_missing(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'context' => array() ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame( '', $this->first_insert_data()['source_name'] );
	}

	public function test_upsert_stores_provider_from_send_result(): void {
		$this->wpdb->get_var_return = null;
		$result                     = $this->make_success_result( array( 'provider' => 'mailgun' ) );

		$this->make_repo()->upsert( $this->make_message(), $result, MailStatus::ACCEPTED );

		$this->assertSame( 'mailgun', $this->first_insert_data()['provider'] );
	}

	// -------------------------------------------------------------------------
	// upsert() — UPDATE path (existing row)
	// -------------------------------------------------------------------------

	/**
	 * When a row already exists for the UUID, upsert() must UPDATE rather than INSERT.
	 * Duplicate/update behavior must be deterministic: INSERT on first call,
	 * UPDATE on subsequent calls for the same UUID.
	 */
	public function test_upsert_calls_update_when_row_exists(): void {
		$this->wpdb->get_var_return = '42'; // Simulate existing row with id=42.

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertCount( 0, $this->wpdb->inserts, 'Expected no INSERT when row exists.' );
		$this->assertCount( 1, $this->wpdb->updates, 'Expected one UPDATE when row exists.' );
	}

	public function test_upsert_update_targets_mail_logs_table(): void {
		$this->wpdb->get_var_return = '1';

		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertStringContainsString( 'scalyn_mail_logs', $this->wpdb->updates[0]['table'] );
	}

	public function test_upsert_update_where_clause_uses_message_uuid(): void {
		$this->wpdb->get_var_return = '1';
		$message                    = $this->make_message( array( 'uuid' => 'uuid-for-update' ) );

		$this->make_repo()->upsert( $message, $this->make_success_result(), MailStatus::ACCEPTED );

		$this->assertSame(
			array( 'message_uuid' => 'uuid-for-update' ),
			$this->wpdb->updates[0]['where']
		);
	}

	// -------------------------------------------------------------------------
	// find_by_uuid()
	// -------------------------------------------------------------------------

	public function test_find_by_uuid_returns_null_when_not_found(): void {
		$this->wpdb->get_row_return = null;

		$result = $this->make_repo()->find_by_uuid( 'nonexistent-uuid' );

		$this->assertNull( $result );
	}

	public function test_find_by_uuid_returns_row_when_found(): void {
		$expected                   = array(
			'id'           => '1',
			'message_uuid' => 'uuid-xyz',
			'status'       => 'accepted',
		);
		$this->wpdb->get_row_return = $expected;

		$result = $this->make_repo()->find_by_uuid( 'uuid-xyz' );

		$this->assertSame( $expected, $result );
	}

	// -------------------------------------------------------------------------
	// find_recent()
	// -------------------------------------------------------------------------

	public function test_find_recent_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_results_return = array();

		$result = $this->make_repo()->find_recent();

		$this->assertSame( array(), $result );
	}

	public function test_find_recent_returns_configured_rows(): void {
		$rows                           = array(
			array(
				'id'     => '2',
				'status' => 'accepted',
			),
			array(
				'id'     => '1',
				'status' => 'failed',
			),
		);
		$this->wpdb->get_results_return = $rows;

		$result = $this->make_repo()->find_recent( 2 );

		$this->assertSame( $rows, $result );
	}

	/**
	 * Pagination must have a bounded maximum: limits > MAX_PAGE_SIZE are clamped.
	 */
	public function test_find_recent_clamps_limit_to_max_page_size(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( MailLogRepository::MAX_PAGE_SIZE + 100, 0 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		// The first argument after the query template is the LIMIT value.
		$this->assertSame( MailLogRepository::MAX_PAGE_SIZE, $last_prepare['args'][0] );
	}

	public function test_find_recent_clamps_negative_limit_to_one(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( -10, 0 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( 1, $last_prepare['args'][0] );
	}

	public function test_find_recent_clamps_negative_offset_to_zero(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( 10, -5 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( 0, $last_prepare['args'][1] );
	}

	public function test_max_page_size_constant_is_bounded(): void {
		$this->assertLessThanOrEqual( 250, MailLogRepository::MAX_PAGE_SIZE );
		$this->assertGreaterThan( 0, MailLogRepository::MAX_PAGE_SIZE );
	}

	// -------------------------------------------------------------------------
	// DB write failure handling
	// -------------------------------------------------------------------------

	/**
	 * When $wpdb->insert() returns false, upsert() must throw RuntimeException.
	 * The subscriber's catch boundary absorbs it; mail delivery is unaffected.
	 */
	public function test_upsert_throws_runtime_exception_when_insert_fails(): void {
		$this->wpdb->get_var_return         = null; // No existing row → INSERT path.
		$this->wpdb->return_false_on_insert = true;

		$this->expectException( \RuntimeException::class );
		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );
	}

	/**
	 * The INSERT failure exception message must be a fixed safe string:
	 * no $wpdb->last_error, no SQL, no credentials, no provider metadata.
	 */
	public function test_upsert_insert_exception_message_is_safe(): void {
		$this->wpdb->get_var_return         = null;
		$this->wpdb->return_false_on_insert = true;

		try {
			$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertSame( 'Mail log insert failed.', $msg );
			$this->assertStringNotContainsString( 'last_error', $msg );
			$this->assertStringNotContainsString( 'INSERT', $msg );
			$this->assertStringNotContainsString( 'SELECT', $msg );
			$this->assertStringNotContainsString( 'password', $msg );
		}
	}

	/**
	 * When $wpdb->update() returns false, upsert() must throw RuntimeException.
	 */
	public function test_upsert_throws_runtime_exception_when_update_fails(): void {
		$this->wpdb->get_var_return         = '42'; // Existing row → UPDATE path.
		$this->wpdb->return_false_on_update = true;

		$this->expectException( \RuntimeException::class );
		$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );
	}

	/**
	 * The UPDATE failure exception message must be a fixed safe string.
	 */
	public function test_upsert_update_exception_message_is_safe(): void {
		$this->wpdb->get_var_return         = '1';
		$this->wpdb->return_false_on_update = true;

		try {
			$this->make_repo()->upsert( $this->make_message(), $this->make_success_result(), MailStatus::ACCEPTED );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertSame( 'Mail log update failed.', $msg );
			$this->assertStringNotContainsString( 'last_error', $msg );
			$this->assertStringNotContainsString( 'UPDATE', $msg );
			$this->assertStringNotContainsString( 'password', $msg );
		}
	}

	// -------------------------------------------------------------------------
	// count_recent_by_status()
	// -------------------------------------------------------------------------

	public function test_count_recent_by_status_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_results_return = array();

		$this->assertSame( array(), $this->make_repo()->count_recent_by_status() );
	}

	public function test_count_recent_by_status_maps_status_to_row_count(): void {
		$this->wpdb->get_results_return = array(
			array(
				'status'    => MailStatus::ACCEPTED,
				'row_count' => '42',
			),
			array(
				'status'    => MailStatus::FAILED,
				'row_count' => '3',
			),
		);

		$counts = $this->make_repo()->count_recent_by_status();

		$this->assertSame( 42, $counts[ MailStatus::ACCEPTED ] );
		$this->assertSame( 3, $counts[ MailStatus::FAILED ] );
	}

	public function test_count_recent_by_status_omits_statuses_with_no_rows(): void {
		$this->wpdb->get_results_return = array(
			array(
				'status'    => MailStatus::ACCEPTED,
				'row_count' => '5',
			),
		);

		$counts = $this->make_repo()->count_recent_by_status();

		$this->assertArrayNotHasKey( MailStatus::FAILED, $counts );
	}

	public function test_count_recent_by_status_passes_days_to_prepare(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->count_recent_by_status( 14 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertContains( 14, $last_prepare['args'] );
	}

	public function test_count_recent_by_status_clamps_days_below_one(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->count_recent_by_status( 0 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertContains( 1, $last_prepare['args'] );
	}
}
