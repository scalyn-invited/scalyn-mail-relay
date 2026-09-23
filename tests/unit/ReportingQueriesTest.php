<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Reporting\ReportPeriod;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;

final class ReportingQueriesTest extends TestCase {

	private WpdbStub $db;

	protected function setUp(): void {
		$this->db = new WpdbStub();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private function period(): ReportPeriod {
		return new ReportPeriod( '2026-09-01 00:00:00', '2026-10-01 00:00:00' );
	}

	/** @dataProvider invalidPeriods */
	public function test_invalid_periods( string $start, string $end ): void {
		$this->expectException( InvalidArgumentException::class );
		new ReportPeriod( $start, $end );
	}

	public static function invalidPeriods(): array {
		return array(
			array( "2026-09-01 00:00:00\0", '2026-10-01 00:00:00' ),
			array( '2026-02-30 00:00:00', '2026-03-10 00:00:00' ),
			array( '2026-09-01', '2026-10-01 00:00:00' ),
			array( '2026-09-01 00:00:00', '2026-09-01 00:00:00' ),
			array( '2026-09-02 00:00:00', '2026-09-01 00:00:00' ),
			array( '2024-01-01 00:00:00', '2026-01-01 00:00:00' ),
		);
	}

	public function test_counts_include_nonterminal_and_unrecognized_without_delivery_claims(): void {
		$this->db->get_results_return = array(
			array( 'status' => 'accepted', 'row_count' => '4' ),
			array( 'status' => 'prepared', 'row_count' => '2' ),
			array( 'status' => 'legacy', 'row_count' => '1' ),
		);
		$result = (new MailLogRepository())->activity_totals( $this->period(), 'smtp' );
		$this->assertSame( 7, $result['total'] );
		$this->assertSame( 4, $result['statuses']['accepted'] );
		$this->assertSame( 0, $result['statuses']['failed'] );
		$this->assertSame( 1, $result['unrecognized'] );
		$query = $this->db->prepare_calls[0];
		$this->assertStringContainsString( 'created_at >= %s AND created_at < %s', $query['query'] );
		$this->assertSame( 'smtp', $query['args'][3] );
	}

	public function test_empty_activity_returns_zero_counts(): void {
		$this->assertSame( 0, (new MailLogRepository())->activity_totals( $this->period() )['total'] );
		$this->assertStringNotContainsString( 'provider =', $this->db->prepare_calls[0]['query'] );
	}

	public function test_invalid_provider_never_queries(): void {
		try {
			(new MailLogRepository())->report_failures( $this->period(), "smtp' OR 1=1" );
			$this->fail( 'Expected rejection' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertSame( array(), $this->db->prepare_calls );
		}
	}

	public function test_failure_projection_is_bounded_and_private(): void {
		(new MailLogRepository())->report_failures( $this->period(), '', 9999 );
		$query = $this->db->prepare_calls[0];
		$this->assertSame( array( 'wp_scalyn_mail_logs', $this->period()->start, $this->period()->end, '', 'failed', 250 ), $query['args'] );
		$this->assertStringContainsString( 'ORDER BY created_at DESC, id DESC', $query['query'] );
		$this->assertStringNotContainsString( 'response_message', $query['query'] );
		$this->assertStringNotContainsString( 'SELECT *', $query['query'] );
	}

	public function test_trend_keeps_null_distinct_from_zero(): void {
		$this->db->get_results_return = array( array( 'day' => '2026-09-01', 'snapshot_count' => '1', 'scored_count' => '0', 'average_score' => null, 'minimum_score' => null, 'maximum_score' => null, 'latest_at' => '2026-09-01 12:00:00' ) );
		$result = (new HealthScoreRepository())->daily_trend( $this->period() );
		$this->assertNull( $result[0]['average_score'] );
		$this->assertSame( 0, $result[0]['scored_count'] );
		$this->assertStringContainsString( 'LIMIT 367', $this->db->prepare_calls[0]['query'] );
		$this->assertStringNotContainsString( 'provider', $this->db->prepare_calls[0]['query'] );
	}

	public function test_maximum_period_including_leap_day_is_valid(): void {
		$period = new ReportPeriod( '2024-01-01 00:00:00', '2025-01-01 00:00:00' );
		$this->assertSame( '2025-01-01 00:00:00', $period->end );
	}

	public function test_failures_lower_limit_is_one(): void {
		(new MailLogRepository())->report_failures( $this->period(), null, -1 );
		$this->assertSame( 1, $this->db->prepare_calls[0]['args'][4] );
	}

	/** @dataProvider reportingMethods */
	public function test_database_failure_is_not_reported_as_no_activity( string $method ): void {
		$GLOBALS['wpdb'] = new class() extends WpdbStub {
			public string $last_error = 'sensitive SQL and credentials';
		};
		$repo = 'daily_trend' === $method ? new HealthScoreRepository() : new MailLogRepository();
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Reporting data unavailable.' );
		$repo->$method( $this->period() );
	}

	public static function reportingMethods(): array {
		return array( array( 'activity_totals' ), array( 'report_failures' ), array( 'daily_trend' ) );
	}
}
