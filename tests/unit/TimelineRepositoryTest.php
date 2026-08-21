<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Logging\TimelineRepository;
use Scalyn\MailRelay\Mail\MailStatus;

/**
 * Unit tests for TimelineRepository.
 *
 * Verifies that insert_event() writes the correct data to scalyn_mail_timeline,
 * that event_data is JSON-encoded, and that find_by_uuid() returns events in
 * the deterministic chronological order defined by the query (created_at, id ASC).
 */
final class TimelineRepositoryTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb                    = new WpdbStub();
		$GLOBALS['wpdb']               = $this->wpdb;
		$GLOBALS['_test_current_time'] = '2026-08-21 14:30:00';
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_time'] = null;
		unset( $GLOBALS['wpdb'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_repo(): TimelineRepository {
		return new TimelineRepository();
	}

	/** Returns the data array from the first recorded INSERT. */
	private function first_insert_data(): array {
		$this->assertNotEmpty( $this->wpdb->inserts, 'Expected at least one INSERT.' );
		return $this->wpdb->inserts[0]['data'];
	}

	// -------------------------------------------------------------------------
	// insert_event()
	// -------------------------------------------------------------------------

	public function test_insert_event_writes_to_timeline_table(): void {
		$this->make_repo()->insert_event(
			'uuid-001',
			'mail_sent',
			MailStatus::ACCEPTED,
			'Message accepted by provider',
			'OK',
			null
		);

		$this->assertStringContainsString( 'scalyn_mail_timeline', $this->wpdb->inserts[0]['table'] );
	}

	public function test_insert_event_stores_uuid(): void {
		$this->make_repo()->insert_event( 'uuid-abc', 'mail_sent', MailStatus::ACCEPTED, 'Label', null, null );

		$this->assertSame( 'uuid-abc', $this->first_insert_data()['message_uuid'] );
	}

	public function test_insert_event_stores_event_type(): void {
		$this->make_repo()->insert_event( 'uuid-001', 'mail_failed', MailStatus::FAILED, 'Label', null, null );

		$this->assertSame( 'mail_failed', $this->first_insert_data()['event_type'] );
	}

	public function test_insert_event_stores_event_status(): void {
		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Label', null, null );

		$this->assertSame( MailStatus::ACCEPTED, $this->first_insert_data()['event_status'] );
	}

	public function test_insert_event_stores_event_label(): void {
		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Custom Label', null, null );

		$this->assertSame( 'Custom Label', $this->first_insert_data()['event_label'] );
	}

	public function test_insert_event_stores_event_message(): void {
		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Label', 'Provider OK', null );

		$this->assertSame( 'Provider OK', $this->first_insert_data()['event_message'] );
	}

	public function test_insert_event_json_encodes_event_data_array(): void {
		$event_data = array( 'provider' => 'smtp', 'response_code' => '250' );

		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Label', null, $event_data );

		$stored = $this->first_insert_data()['event_data'];
		$this->assertIsString( $stored );
		$this->assertSame( $event_data, json_decode( $stored, true ) );
	}

	public function test_insert_event_stores_null_when_event_data_is_null(): void {
		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Label', null, null );

		$this->assertNull( $this->first_insert_data()['event_data'] );
	}

	public function test_insert_event_stores_current_time(): void {
		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Label', null, null );

		$this->assertSame( '2026-08-21 14:30:00', $this->first_insert_data()['created_at'] );
	}

	public function test_insert_event_does_not_store_metadata_key(): void {
		// Verify that even if caller accidentally passes metadata in event_data,
		// this test acts as a guardrail for the subscriber's allowlist.
		$safe_data = array( 'provider' => 'smtp', 'response_code' => null );

		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', MailStatus::ACCEPTED, 'Label', null, $safe_data );

		$decoded = json_decode( $this->first_insert_data()['event_data'], true );
		$this->assertArrayNotHasKey( 'metadata', $decoded );
	}

	// -------------------------------------------------------------------------
	// find_by_uuid()
	// -------------------------------------------------------------------------

	public function test_find_by_uuid_returns_empty_array_when_no_events(): void {
		$this->wpdb->get_results_return = array();

		$result = $this->make_repo()->find_by_uuid( 'uuid-unknown' );

		$this->assertSame( array(), $result );
	}

	/**
	 * Timeline ordering is deterministic: results must be returned oldest-first
	 * (created_at ASC, id ASC as tiebreaker).
	 *
	 * The query ORDER BY clause is verified through the prepare_calls recording.
	 */
	public function test_find_by_uuid_query_orders_by_created_at_asc_then_id_asc(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_by_uuid( 'uuid-order-test' );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'created_at ASC', $last_prepare['query'] );
		$this->assertStringContainsString( 'id ASC', $last_prepare['query'] );
	}

	public function test_find_by_uuid_returns_configured_rows(): void {
		$rows = array(
			array( 'id' => '1', 'event_type' => 'mail_sent', 'event_status' => 'accepted' ),
			array( 'id' => '2', 'event_type' => 'mail_sent', 'event_status' => 'accepted' ),
		);
		$this->wpdb->get_results_return = $rows;

		$result = $this->make_repo()->find_by_uuid( 'uuid-001' );

		$this->assertSame( $rows, $result );
	}

	public function test_find_by_uuid_passes_uuid_to_prepare(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_by_uuid( 'uuid-for-query-test' );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertContains( 'uuid-for-query-test', $last_prepare['args'] );
	}

	// -------------------------------------------------------------------------
	// DB write failure handling
	// -------------------------------------------------------------------------

	/**
	 * When $wpdb->insert() returns false, insert_event() must throw RuntimeException.
	 * The subscriber's catch boundary absorbs it; mail delivery is unaffected.
	 */
	public function test_insert_event_throws_runtime_exception_when_insert_fails(): void {
		$this->wpdb->return_false_on_insert = true;

		$this->expectException( \RuntimeException::class );
		$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', 'accepted', 'Label', null, null );
	}

	/**
	 * The INSERT failure exception message must be a fixed safe string:
	 * no $wpdb->last_error, no SQL, no credentials, no provider metadata.
	 */
	public function test_insert_event_exception_message_is_safe(): void {
		$this->wpdb->return_false_on_insert = true;

		try {
			$this->make_repo()->insert_event( 'uuid-001', 'mail_sent', 'accepted', 'Label', null, null );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertSame( 'Timeline event insert failed.', $msg );
			$this->assertStringNotContainsString( 'last_error', $msg );
			$this->assertStringNotContainsString( 'INSERT', $msg );
			$this->assertStringNotContainsString( 'password', $msg );
		}
	}
}
