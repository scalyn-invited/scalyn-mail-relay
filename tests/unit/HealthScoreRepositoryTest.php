<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Diagnostics\HealthScoreResult;

/**
 * Unit tests for HealthScoreRepository.
 *
 * Verifies that persist() writes the correct data to scalyn_health_scores
 * (deliverability_score always null — out of scope per Handbook §12.3), that
 * DB write failures throw a safe RuntimeException, and that find_latest() /
 * find_recent() build bounded, ordered, prepared queries.
 */
final class HealthScoreRepositoryTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb                    = new WpdbStub();
		$GLOBALS['wpdb']               = $this->wpdb;
		$GLOBALS['_test_current_time'] = '2026-09-01 10:00:00';
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_time'] = null;
		unset( $GLOBALS['wpdb'] );
	}

	private function make_repo(): HealthScoreRepository {
		return new HealthScoreRepository();
	}

	private function make_result(
		int $overall_score = 80,
		?int $dns_score = 90,
		?int $provider_score = 70,
		?int $failure_score = 80,
		?int $security_score = null,
		string $summary = 'Health score based on: DNS & authentication, Provider & transport, Operational reliability.'
	): HealthScoreResult {
		return new HealthScoreResult( $overall_score, $dns_score, $provider_score, $failure_score, $security_score, $summary );
	}

	private function first_insert_data(): array {
		$this->assertNotEmpty( $this->wpdb->inserts, 'Expected at least one INSERT.' );
		return $this->wpdb->inserts[0]['data'];
	}

	// -------------------------------------------------------------------------
	// persist()
	// -------------------------------------------------------------------------

	public function test_persist_writes_to_health_scores_table(): void {
		$this->make_repo()->persist( $this->make_result() );

		$this->assertStringContainsString( 'scalyn_health_scores', $this->wpdb->inserts[0]['table'] );
	}

	public function test_persist_stores_overall_and_component_scores(): void {
		$this->make_repo()->persist( $this->make_result( overall_score: 75, dns_score: 90, provider_score: 60, failure_score: 80 ) );

		$data = $this->first_insert_data();
		$this->assertSame( 75, $data['overall_score'] );
		$this->assertSame( 90, $data['dns_score'] );
		$this->assertSame( 60, $data['provider_score'] );
		$this->assertSame( 80, $data['failure_score'] );
	}

	public function test_persist_stores_null_for_unavailable_components(): void {
		$this->make_repo()->persist( $this->make_result( provider_score: null, failure_score: null ) );

		$data = $this->first_insert_data();
		$this->assertNull( $data['provider_score'] );
		$this->assertNull( $data['failure_score'] );
	}

	public function test_persist_never_writes_a_deliverability_score(): void {
		$this->make_repo()->persist( $this->make_result() );

		$this->assertNull( $this->first_insert_data()['deliverability_score'] );
	}

	public function test_persist_stores_summary(): void {
		$this->make_repo()->persist( $this->make_result( summary: 'Custom summary text.' ) );

		$this->assertSame( 'Custom summary text.', $this->first_insert_data()['summary'] );
	}

	public function test_persist_generates_a_score_uuid(): void {
		$this->make_repo()->persist( $this->make_result() );

		$this->assertNotEmpty( $this->first_insert_data()['score_uuid'] );
	}

	public function test_persist_stores_current_time(): void {
		$this->make_repo()->persist( $this->make_result() );

		$this->assertSame( '2026-09-01 10:00:00', $this->first_insert_data()['created_at'] );
	}

	public function test_persist_throws_runtime_exception_when_insert_fails(): void {
		$this->wpdb->return_false_on_insert = true;

		$this->expectException( \RuntimeException::class );
		$this->make_repo()->persist( $this->make_result() );
	}

	public function test_persist_exception_message_is_safe(): void {
		$this->wpdb->return_false_on_insert = true;

		try {
			$this->make_repo()->persist( $this->make_result() );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertSame( 'Health score insert failed.', $msg );
			$this->assertStringNotContainsString( 'last_error', $msg );
			$this->assertStringNotContainsString( 'INSERT', $msg );
		}
	}

	// -------------------------------------------------------------------------
	// find_latest()
	// -------------------------------------------------------------------------

	public function test_find_latest_returns_null_when_no_rows_exist(): void {
		$this->wpdb->get_row_return = null;

		$this->assertNull( $this->make_repo()->find_latest() );
	}

	public function test_find_latest_returns_configured_row(): void {
		$row                        = array(
			'id'            => '1',
			'overall_score' => 80,
		);
		$this->wpdb->get_row_return = $row;

		$this->assertSame( $row, $this->make_repo()->find_latest() );
	}

	public function test_find_latest_orders_by_created_at_desc_then_id_desc(): void {
		$this->wpdb->get_row_return = null;

		$this->make_repo()->find_latest();

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'created_at DESC', $last_prepare['query'] );
		$this->assertStringContainsString( 'id DESC', $last_prepare['query'] );
	}

	// -------------------------------------------------------------------------
	// find_recent()
	// -------------------------------------------------------------------------

	public function test_find_recent_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_results_return = array();

		$this->assertSame( array(), $this->make_repo()->find_recent() );
	}

	public function test_find_recent_returns_configured_rows(): void {
		$rows                           = array( array( 'id' => '1' ), array( 'id' => '2' ) );
		$this->wpdb->get_results_return = $rows;

		$this->assertSame( $rows, $this->make_repo()->find_recent() );
	}

	public function test_find_recent_clamps_limit_above_max_page_size(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( 10000, 0 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( HealthScoreRepository::MAX_PAGE_SIZE, $last_prepare['args'][0] );
	}

	public function test_find_recent_clamps_negative_offset_to_zero(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( 25, -50 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( 0, $last_prepare['args'][1] );
	}
}
