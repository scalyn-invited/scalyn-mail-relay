<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\ReportSnapshotRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Diagnostics\RecommendationEngine;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Reporting\ReportPeriod;

final class ReportSnapshotTest extends TestCase {

	private WpdbStub $db;
	private ReportSnapshotRepository $repository;

	protected function setUp(): void {
		$this->db = new class extends WpdbStub {
			public string $last_error = '';
			public array $findings = [];
			public ?string $fail_read = null;
			public int $failure_count = 0;
			public array $failures = [];
			public function get_results( string $query, string $output = ARRAY_A ): array {
				if ( null !== $this->fail_read && str_contains( $query, $this->fail_read ) ) {
					$this->last_error = 'secret database detail';
				}
				if (str_contains($query,'COUNT(*) AS row_count')) { return [['status'=>'failed','row_count'=>$this->failure_count]]; }
				if (str_contains($query,'failed_at')) { return $this->failures; }
				return str_contains( $query, 'diagnostic_uuid = ' ) ? $this->findings : [];
			}
		};
		$this->db->get_var_return = '3';
		$GLOBALS['wpdb'] = $this->db;
		$this->repository = new ReportSnapshotRepository(new MailLogRepository(),new HealthScoreRepository(),new DiagnosticRepository(),new RecommendationEngine());
	}

	protected function tearDown(): void {
		unset($GLOBALS['wpdb']);
	}

	private function period(): ReportPeriod {
		return new ReportPeriod('2026-09-01 00:00:00','2026-10-01 00:00:00');
	}

	public function test_empty_capture_is_versioned_immutable_and_does_not_claim_health(): void {
		$snapshot = $this->repository->capture($this->period(),'smtp');
		$data = $snapshot->data;
		$this->assertSame(1,$data['version']);
		$this->assertSame('smtp',$data['mail']['provider']);
		$this->assertSame(0,$data['mail']['totals']['total']);
		$this->assertNull($data['health']['latest_score']);
		$this->assertSame([],$data['health']['daily_trend']);
		$this->assertSame('site-wide',$data['diagnostics']['scope']);
		$this->assertTrue($data['recommendations']['refresh']);
		$this->assertSame($data['generated_at_utc'],$data['freshness']['evaluated_at_utc']);
		$this->assertSame('2026-10-01 00:00:00',$data['period']['end_exclusive']);
		$this->assertCount(8,$data['limitations']);
		$this->assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','COMMIT'],$this->db->queries);
		$data['mail']['provider']='changed copy';
		$this->assertSame('smtp',$snapshot->data['mail']['provider']);
		$this->expectException(Error::class);
		$snapshot->data['version']=2;
	}

	/** @dataProvider failures */
	public function test_transaction_failures_never_return_partial_snapshot(array $returns,array $expected): void {
		$this->db->query_returns=$returns;
		try {
			$this->repository->capture($this->period());
			$this->fail('Expected failure');
		} catch (RuntimeException $error) {
			$this->assertSame('Report snapshot unavailable.',$error->getMessage());
			$this->assertNull($error->getPrevious());
		}
		$this->assertSame($expected,$this->db->queries);
	}

	public static function failures(): array {
		$set='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ';
		$start='START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY';
		return [
			[[false],[$set]],
			[[1,false],[$set,$start]],
			[[1,1,false],[$set,$start,'COMMIT','ROLLBACK']],
		];
	}

	public function test_nontransactional_tables_fail_before_start(): void {
		$this->db->get_var_return='2';
		try { $this->repository->capture($this->period()); $this->fail(); }
		catch(RuntimeException $error) { $this->assertSame('Report snapshot unavailable.',$error->getMessage()); }
		$this->assertSame([],$this->db->queries);
	}

	/** @dataProvider readFailures */
	public function test_read_error_rolls_back_without_exposing_database_details(string $query): void {
		$this->db->fail_read=$query;
		try { $this->repository->capture($this->period()); $this->fail(); }
		catch(RuntimeException $error) { $this->assertSame('Report snapshot unavailable.',$error->getMessage()); }
		$this->assertSame('ROLLBACK',end($this->db->queries));
	}

	public static function readFailures(): array {
		return [['COUNT(*) AS row_count'],['failed_at'],['DATE(created_at)'],['diagnostic_uuid = ']];
	}

	public function test_bounded_private_evidence_and_independent_score_references(): void {
		$this->db->findings=[['id'=>'4','diagnostic_uuid'=>'70000000-0000-4000-8000-000000000001','check_type'=>'dns','check_name'=>'dkim_record','status'=>'unknown','severity'=>'low','score'=>null,'created_at'=>'2026-09-01 12:00:00']];
		$this->db->get_row_return=['id'=>'8','score_uuid'=>'70000000-0000-4000-8000-000000000002','overall_score'=>'0','created_at'=>'2026-09-01 11:00:00'];
		$snapshot=$this->repository->capture($this->period());
		$this->assertSame($this->db->findings,$snapshot->data['diagnostics']['findings']);
		$this->assertSame('0',$snapshot->data['health']['latest_score']['overall_score']);
		$this->assertNotSame($snapshot->data['health']['latest_score']['score_uuid'],$snapshot->data['diagnostics']['findings'][0]['diagnostic_uuid']);
		$sql=implode("\n",array_column($this->db->prepare_calls,'query'));
		foreach(['SELECT *','raw_result','result_message','recommended_action','response_message','summary','recipient','subject','body'] as $private) { $this->assertStringNotContainsString($private,$sql); }
		$this->assertStringContainsString('LIMIT 251',$sql);
		$this->assertStringContainsString('ORDER BY created_at DESC, id DESC LIMIT 1',$sql);
		$this->assertSame(250,$snapshot->data['diagnostics']['limit']);
	}

	public function test_oversized_run_is_rejected_not_truncated(): void {
		$this->db->findings=array_fill(0,251,['created_at'=>'2026-09-02 00:00:00']);
		$this->expectException(RuntimeException::class);
		$this->repository->capture($this->period());
	}

	public function test_boundary_spanning_run_is_rejected(): void {
		$this->db->findings=[['created_at'=>'2026-08-31 23:59:59']];
		$this->expectException(RuntimeException::class);
		$this->repository->capture($this->period());
	}

	public function test_health_query_errors_are_not_missing_scores(): void {
		$this->db->last_error='secret';
		$this->expectExceptionMessage('Reporting data unavailable.');
		(new HealthScoreRepository())->report_latest($this->period());
	}

	public function test_invalid_provider_rolls_back_safely(): void {
		try { $this->repository->capture($this->period(),'smtp secret!'); $this->fail(); }
		catch(RuntimeException $error) { $this->assertSame('Report snapshot unavailable.',$error->getMessage()); }
		$this->assertSame('ROLLBACK',end($this->db->queries));
	}

	public function test_failures_are_a_labelled_bounded_sample_not_total_evidence(): void {
		$this->db->failure_count=31;
		$this->db->failures=array_fill(0,25,['message_uuid'=>'70000000-0000-4000-8000-000000000001']);
		$data=$this->repository->capture($this->period(),'')->data;
		$this->assertSame('',$data['mail']['provider']);
		$this->assertSame(31,$data['mail']['totals']['statuses']['failed']);
		$this->assertCount(25,$data['mail']['recent_failures']);
		$this->assertSame(6,$data['mail']['failures_omitted']);
		$this->assertSame(25,$this->db->prepare_calls[2]['args'][5]);
	}

	public function test_fresh_and_historical_recommendations_use_capture_time(): void {
		$at=(new DateTimeImmutable('now',wp_timezone()))->modify('-60 seconds')->format('Y-m-d H:i:s');
		$end=(new DateTimeImmutable('now',wp_timezone()))->modify('+60 seconds')->format('Y-m-d H:i:s');
		$this->db->findings=[['diagnostic_uuid'=>'70000000-0000-4000-8000-000000000001','check_name'=>'dmarc_policy','status'=>'warn','severity'=>'medium','created_at'=>$at]];
		$data=$this->repository->capture(new ReportPeriod($at,$end),null,'hourly')->data;
		$this->assertFalse($data['recommendations']['refresh']);
		$this->assertSame('dmarc_policy',$data['recommendations']['items'][0]['check']);
		$this->assertSame('hourly',$data['freshness']['cadence']);
		$this->db->findings[0]['created_at']='2020-01-01 00:00:00';
		$historical=$this->repository->capture(new ReportPeriod('2020-01-01 00:00:00','2020-01-02 00:00:00'))->data;
		$this->assertTrue($historical['recommendations']['refresh']);
		$this->assertSame([],$historical['recommendations']['items']);
		$this->assertSame('warn',$historical['diagnostics']['findings'][0]['status']);
	}
}
