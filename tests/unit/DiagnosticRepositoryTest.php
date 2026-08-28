<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

/**
 * Unit tests for DiagnosticRepository.
 *
 * Verifies that persist_result() writes the correct data to scalyn_diagnostics
 * (including folding evidence/impact into raw_result JSON alongside raw), that
 * DB write failures throw a safe RuntimeException, and that find_by_uuid() /
 * find_recent() build bounded, ordered, prepared queries.
 */
final class DiagnosticRepositoryTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb                    = new WpdbStub();
		$GLOBALS['wpdb']               = $this->wpdb;
		$GLOBALS['_test_current_time'] = '2026-08-25 09:00:00';
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_time'] = null;
		unset( $GLOBALS['wpdb'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_repo(): DiagnosticRepository {
		return new DiagnosticRepository();
	}

	private function make_result(
		string $status = 'pass',
		string $severity = 'low',
		string $message = 'SPF record found.',
		string $evidence = 'v=spf1 include:_spf.example.com ~all',
		string $impact = 'Outbound mail may be marked as spam.',
		string $recommended_action = '',
		?int $score = 90,
		array $raw = array( 'record' => 'v=spf1 include:_spf.example.com ~all' )
	): DiagnosticResult {
		return new DiagnosticResult( $status, $severity, $message, $evidence, $impact, $recommended_action, $score, $raw );
	}

	/** Returns the data array from the first recorded INSERT. */
	private function first_insert_data(): array {
		$this->assertNotEmpty( $this->wpdb->inserts, 'Expected at least one INSERT.' );
		return $this->wpdb->inserts[0]['data'];
	}

	// -------------------------------------------------------------------------
	// persist_result()
	// -------------------------------------------------------------------------

	public function test_persist_result_writes_to_diagnostics_table(): void {
		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $this->make_result() );

		$this->assertStringContainsString( 'scalyn_diagnostics', $this->wpdb->inserts[0]['table'] );
	}

	public function test_persist_result_stores_diagnostic_uuid(): void {
		$this->make_repo()->persist_result( 'run-uuid-abc', 'dns', 'spf_record', $this->make_result() );

		$this->assertSame( 'run-uuid-abc', $this->first_insert_data()['diagnostic_uuid'] );
	}

	public function test_persist_result_stores_check_type_and_name(): void {
		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $this->make_result() );

		$data = $this->first_insert_data();
		$this->assertSame( 'dns', $data['check_type'] );
		$this->assertSame( 'spf_record', $data['check_name'] );
	}

	public function test_persist_result_stores_status_severity_and_score(): void {
		$result = $this->make_result( status: 'warn', severity: 'medium', score: 60 );

		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $result );

		$data = $this->first_insert_data();
		$this->assertSame( 'warn', $data['status'] );
		$this->assertSame( 'medium', $data['severity'] );
		$this->assertSame( 60, $data['score'] );
	}

	public function test_persist_result_stores_null_score_when_absent(): void {
		$result = $this->make_result( score: null );

		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $result );

		$this->assertNull( $this->first_insert_data()['score'] );
	}

	public function test_persist_result_stores_message_as_result_message(): void {
		$result = $this->make_result( message: 'DKIM record missing.' );

		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'dkim_record', $result );

		$this->assertSame( 'DKIM record missing.', $this->first_insert_data()['result_message'] );
	}

	public function test_persist_result_stores_recommended_action(): void {
		$result = $this->make_result( recommended_action: 'Add a DKIM TXT record.' );

		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'dkim_record', $result );

		$this->assertSame( 'Add a DKIM TXT record.', $this->first_insert_data()['recommended_action'] );
	}

	public function test_persist_result_json_encodes_raw_evidence_and_impact_into_raw_result(): void {
		$result = $this->make_result(
			evidence: 'record-evidence',
			impact: 'record-impact',
			raw: array( 'ttl' => 3600 )
		);

		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $result );

		$decoded = json_decode( $this->first_insert_data()['raw_result'], true );
		$this->assertSame(
			array(
				'raw'      => array( 'ttl' => 3600 ),
				'evidence' => 'record-evidence',
				'impact'   => 'record-impact',
			),
			$decoded
		);
	}

	public function test_persist_result_stores_current_time(): void {
		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $this->make_result() );

		$this->assertSame( '2026-08-25 09:00:00', $this->first_insert_data()['created_at'] );
	}

	public function test_persist_result_throws_runtime_exception_when_insert_fails(): void {
		$this->wpdb->return_false_on_insert = true;

		$this->expectException( \RuntimeException::class );
		$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $this->make_result() );
	}

	/**
	 * The INSERT failure exception message must be a fixed safe string: no
	 * $wpdb->last_error, no SQL, no credentials, no check-supplied evidence/raw.
	 */
	public function test_persist_result_exception_message_is_safe(): void {
		$this->wpdb->return_false_on_insert = true;

		try {
			$this->make_repo()->persist_result( 'run-uuid-001', 'dns', 'spf_record', $this->make_result() );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertSame( 'Diagnostic result insert failed.', $msg );
			$this->assertStringNotContainsString( 'last_error', $msg );
			$this->assertStringNotContainsString( 'INSERT', $msg );
			$this->assertStringNotContainsString( 'password', $msg );
		}
	}

	// -------------------------------------------------------------------------
	// find_by_uuid()
	// -------------------------------------------------------------------------

	public function test_find_by_uuid_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_results_return = array();

		$result = $this->make_repo()->find_by_uuid( 'run-uuid-unknown' );

		$this->assertSame( array(), $result );
	}

	public function test_find_by_uuid_query_orders_by_created_at_asc_then_id_asc(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_by_uuid( 'run-uuid-order-test' );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'created_at ASC', $last_prepare['query'] );
		$this->assertStringContainsString( 'id ASC', $last_prepare['query'] );
	}

	public function test_find_by_uuid_passes_uuid_to_prepare(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_by_uuid( 'run-uuid-for-query-test' );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertContains( 'run-uuid-for-query-test', $last_prepare['args'] );
	}

	public function test_find_by_uuid_returns_configured_rows(): void {
		$rows = array(
			array( 'id' => '1', 'check_name' => 'spf_record', 'status' => 'pass' ),
			array( 'id' => '2', 'check_name' => 'dkim_record', 'status' => 'warn' ),
		);
		$this->wpdb->get_results_return = $rows;

		$result = $this->make_repo()->find_by_uuid( 'run-uuid-001' );

		$this->assertSame( $rows, $result );
	}

	// -------------------------------------------------------------------------
	// find_recent()
	// -------------------------------------------------------------------------

	public function test_find_recent_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_results_return = array();

		$this->assertSame( array(), $this->make_repo()->find_recent() );
	}

	public function test_find_recent_returns_configured_rows(): void {
		$rows                            = array( array( 'id' => '1' ), array( 'id' => '2' ) );
		$this->wpdb->get_results_return = $rows;

		$this->assertSame( $rows, $this->make_repo()->find_recent() );
	}

	public function test_find_recent_orders_by_created_at_desc_then_id_desc(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent();

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'created_at DESC', $last_prepare['query'] );
		$this->assertStringContainsString( 'id DESC', $last_prepare['query'] );
	}

	public function test_find_recent_clamps_limit_above_max_page_size(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( 10000, 0 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( DiagnosticRepository::MAX_PAGE_SIZE, $last_prepare['args'][0] );
	}

	public function test_find_recent_clamps_limit_below_one(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( 0, 0 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( 1, $last_prepare['args'][0] );
	}

	public function test_find_recent_clamps_negative_offset_to_zero(): void {
		$this->wpdb->get_results_return = array();

		$this->make_repo()->find_recent( 25, -50 );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertSame( 0, $last_prepare['args'][1] );
	}

	// -------------------------------------------------------------------------
	// find_latest_run()
	// -------------------------------------------------------------------------

	public function test_find_latest_run_returns_empty_results_and_null_score_when_no_diagnostics_exist(): void {
		$this->wpdb->get_var_return = null;

		$run = $this->make_repo()->find_latest_run();

		$this->assertSame( array(), $run['results'] );
		$this->assertNull( $run['health_score'] );
	}

	public function test_find_latest_run_fetches_rows_for_the_latest_uuid(): void {
		$this->wpdb->get_var_return     = 'run-uuid-latest';
		$this->wpdb->get_results_return = array(
			array( 'id' => '1', 'diagnostic_uuid' => 'run-uuid-latest', 'score' => '80' ),
		);

		$run = $this->make_repo()->find_latest_run();

		$this->assertSame( $this->wpdb->get_results_return, $run['results'] );

		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertContains( 'run-uuid-latest', $last_prepare['args'] );
	}

	public function test_find_latest_run_averages_scores_in_the_run(): void {
		$this->wpdb->get_var_return     = 'run-uuid-001';
		$this->wpdb->get_results_return = array(
			array( 'score' => '90' ),
			array( 'score' => '70' ),
		);

		$run = $this->make_repo()->find_latest_run();

		$this->assertSame( 80, $run['health_score'] );
	}

	public function test_find_latest_run_ignores_null_scores_in_the_average(): void {
		$this->wpdb->get_var_return     = 'run-uuid-001';
		$this->wpdb->get_results_return = array(
			array( 'score' => '100' ),
			array( 'score' => null ),
		);

		$run = $this->make_repo()->find_latest_run();

		$this->assertSame( 100, $run['health_score'] );
	}

	public function test_find_latest_run_returns_null_score_when_no_row_has_a_score(): void {
		$this->wpdb->get_var_return     = 'run-uuid-001';
		$this->wpdb->get_results_return = array(
			array( 'score' => null ),
			array( 'score' => null ),
		);

		$run = $this->make_repo()->find_latest_run();

		$this->assertNull( $run['health_score'] );
	}

	public function test_find_latest_run_truncates_average_rather_than_rounding(): void {
		$this->wpdb->get_var_return     = 'run-uuid-001';
		$this->wpdb->get_results_return = array(
			array( 'score' => '51' ),
			array( 'score' => '50' ),
		);

		$run = $this->make_repo()->find_latest_run();

		// (51 + 50) / 2 = 50.5 -> intval truncates to 50, never rounds up.
		$this->assertSame( 50, $run['health_score'] );
	}
}
