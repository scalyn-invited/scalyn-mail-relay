<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\RecommendationEngine;

final class RecommendationEngineTest extends TestCase {

	private const NOW = 1790128800;
	private const UUID = '11111111-1111-4111-8111-111111111111';

	protected function setUp(): void {
		$GLOBALS['_test_timezone'] = 'UTC';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_timezone'] );
	}

	private function rows(): array {
		return array_map( static fn( $name ) => array( 'check_name' => $name, 'status' => 'pass', 'severity' => 'low', 'diagnostic_uuid' => self::UUID, 'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW ) ), array( 'spf_record', 'dkim_record', 'dmarc_policy', 'mx_record', 'smtp_tls' ) );
	}

	private function run_rules( array $rows, string $cadence = 'hourly' ): array {
		return (new RecommendationEngine())->recommend( $rows, $cadence, self::NOW );
	}

	public function test_passes_do_not_generate_remediation(): void {
		$this->assertSame( array( 'refresh' => false, 'items' => array() ), $this->run_rules( $this->rows() ) );
	}

	public function test_severity_orders_failures_before_warnings_and_gaps(): void {
		$rows = $this->rows();
		$rows[0]['status'] = 'warn';
		$rows[0]['severity'] = 'critical';
		$rows[1]['status'] = 'fail';
		$rows[1]['severity'] = 'low';
		$rows[2]['status'] = 'unknown';
		$rows[4]['status'] = 'fail';
		$rows[4]['severity'] = 'high';
		$items = $this->run_rules( $rows )['items'];
		$this->assertSame( array( 'smtp_tls', 'dkim_record', 'spf_record', 'dmarc_policy' ), array_column( $items, 'check' ) );
		$this->assertSame( self::UUID, $items[0]['run_uuid'] );
	}

	public function test_equal_priority_is_deterministic_independent_of_input_order(): void {
		$rows = $this->rows();
		foreach ( $rows as &$row ) { $row['status'] = 'fail'; }
		$this->assertSame( $this->run_rules( $rows ), $this->run_rules( array_reverse( $rows ) ) );
	}

	/** @dataProvider gaps */
	public function test_unknown_error_and_missing_dkim_do_not_claim_unsigned_mail( string $status ): void {
		$rows = $this->rows();
		if ( 'missing' === $status ) { unset( $rows[1] ); } else { $rows[1]['status'] = $status; }
		$item = $this->run_rules( $rows )['items'][0];
		$this->assertSame( 'Evidence needed', $item['kind'] );
		$this->assertStringContainsString( 'If the selector is missing', $item['action'] );
		$this->assertStringContainsString( 'no configuration failure is inferred', $item['impact'] );
	}

	public static function gaps(): array { return array( array( 'unknown' ), array( 'error' ), array( 'missing' ) ); }

	/** @dataProvider ages */
	public function test_freshness_boundary( int $age, bool $refresh ): void {
		$rows = $this->rows();
		$rows[0]['created_at'] = gmdate( 'Y-m-d H:i:s', self::NOW - $age );
		$this->assertSame( $refresh, $this->run_rules( $rows )['refresh'] );
	}

	public static function ages(): array { return array( array( 3900, false ), array( 3901, true ), array( -1, true ) ); }

	public function test_disabled_monitoring_uses_daily_plus_grace(): void {
		$rows = $this->rows();
		$rows[0]['created_at'] = gmdate( 'Y-m-d H:i:s', self::NOW - 86700 );
		$this->assertFalse( $this->run_rules( $rows, 'disabled' )['refresh'] );
	}

	public function test_site_timezone_is_applied(): void {
		$GLOBALS['_test_timezone'] = 'Asia/Manila';
		$rows = $this->rows();
		foreach ( $rows as &$row ) { $row['created_at'] = gmdate( 'Y-m-d H:i:s', self::NOW + 28800 ); }
		$this->assertFalse( $this->run_rules( $rows )['refresh'] );
	}

	public function test_stale_failure_suppresses_configuration_actions(): void {
		$rows = $this->rows();
		$rows[0]['status'] = 'fail';
		$rows[0]['created_at'] = '2020-01-01 00:00:00';
		$this->assertSame( array( 'refresh' => true, 'items' => array() ), $this->run_rules( $rows ) );
	}

	public function test_missing_and_mixed_runs_require_refresh(): void {
		$this->assertTrue( $this->run_rules( array() )['refresh'] );
		$rows = $this->rows();
		$rows[0]['diagnostic_uuid'] = '22222222-2222-4222-8222-222222222222';
		$this->assertTrue( $this->run_rules( $rows )['refresh'] );
	}

	public function test_duplicate_checks_require_refresh(): void {
		$rows = $this->rows();
		$rows[] = $rows[0];
		$this->assertTrue( $this->run_rules( $rows )['refresh'] );
	}

	/** @dataProvider supportedChecks */
	public function test_supported_failures_have_scoped_actions( int $index, string $phrase ): void {
		$rows = $this->rows();
		$rows[$index]['status'] = 'fail';
		$item = $this->run_rules( $rows )['items'][0];
		$this->assertSame( 'Recorded failure', $item['kind'] );
		$this->assertStringContainsString( $phrase, $item['action'] );
	}

	public static function supportedChecks(): array {
		return array( array(0, 'Do not blindly authorize'), array(1, 'does not enable signing'), array(2, 'before tightening enforcement'), array(3, 'outbound-only'), array(4, 'Do not disable certificate verification') );
	}

	public function test_malformed_timestamp_and_uuid_require_refresh(): void {
		$rows = $this->rows();
		$rows[0]['created_at'] = '2026-02-30 00:00:00';
		$this->assertTrue( $this->run_rules( $rows )['refresh'] );
		$rows = $this->rows();
		$rows[0]['diagnostic_uuid'] = '<script>secret</script>';
		$this->assertSame( array( 'refresh' => true, 'items' => array() ), $this->run_rules( $rows ) );
	}

	public function test_raw_details_and_arbitrary_remediation_are_not_copied(): void {
		$rows = $this->rows();
		$rows[0]['status'] = 'fail';
		$rows[0]['raw_result'] = 'private-token';
		$rows[0]['recommended_action'] = '<script>secret</script>';
		$rows[0]['severity'] = '<script>secret</script>';
		$json = json_encode( $this->run_rules( $rows ) );
		$this->assertStringNotContainsString( 'private-token', $json );
		$this->assertStringNotContainsString( 'secret', $json );
	}
}
