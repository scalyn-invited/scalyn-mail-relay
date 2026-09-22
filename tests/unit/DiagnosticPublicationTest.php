<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DiagnosticPublicationRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;
use Scalyn\MailRelay\Diagnostics\HealthScorer;
use Scalyn\MailRelay\Logging\MailLogRepository;

final class DiagnosticPublicationTest extends TestCase {
	private PublicationWpdbStub $db;
	private DiagnosticPublicationRepository $repository;
	private const UUID = '70000000-0000-4000-8000-000000000001';

	protected function setUp(): void {
		$this->db = new PublicationWpdbStub();
		$GLOBALS['wpdb'] = $this->db;
		$this->repository = new DiagnosticPublicationRepository(new DiagnosticRepository(),new HealthScoreRepository(),new HealthScorer(),new MailLogRepository());
	}
	private function checks(string $status='pass'): array {
		return array(
			array('id'=>'spf','category'=>'dns','result'=>new DiagnosticResult($status,'low','Synthetic')),
			array('id'=>'smtp','category'=>'smtp','result'=>new DiagnosticResult($status,'low','Synthetic')),
		);
	}
	public function test_complete_run_and_snapshot_commit_with_same_uuid_and_timestamp(): void {
		$result = $this->repository->publish(self::UUID,$this->checks());
		$this->assertSame(100,$result['health_score']);
		$this->assertCount(2,$result['results']);
		$this->assertSame(array('START TRANSACTION','COMMIT'),$this->db->queries);
		$this->assertCount(3,$this->db->inserts);
		$rows = array_column($this->db->inserts,'data');
		$this->assertSame(self::UUID,$rows[2]['score_uuid']);
		$this->assertSame($rows[0]['created_at'],$rows[1]['created_at']);
		$this->assertSame($rows[0]['created_at'],$rows[2]['created_at']);
	}
	public function test_unknown_and_error_results_do_not_invent_a_snapshot(): void {
		foreach (array('unknown','error') as $status) {
			$this->db->inserts = array();
			$result = $this->repository->publish(self::UUID,$this->checks($status));
			$this->assertNull($result['health_score']);
			$this->assertCount(2,$this->db->inserts);
			$this->assertSame($status,$result['results'][0]['status']);
		}
	}
	public function test_each_insert_failure_rolls_back_and_preserves_previous_history_then_retries(): void {
		foreach (array(1,2,3) as $position) {
			$this->db = new PublicationWpdbStub();
			$GLOBALS['wpdb'] = $this->db;
			$previous = array(array('table'=>'wp_scalyn_health_scores','data'=>array('score_uuid'=>'prior','overall_score'=>60)));
			$this->db->inserts = $previous;
			$this->db->fail_insert_at = $position;
			try {
				$this->repository->publish(self::UUID,$this->checks());
				$this->fail('Publication must fail');
			} catch (RuntimeException $error) {
				$this->assertSame('Diagnostic publication failed.',$error->getMessage());
			}
			$this->assertSame($previous,$this->db->inserts);
			$this->assertSame('ROLLBACK',end($this->db->queries));
			$this->db->fail_insert_at = 0;
			$this->assertSame(100,$this->repository->publish(self::UUID,$this->checks())['health_score']);
		}
	}
	public function test_transaction_start_and_commit_failures_are_safe(): void {
		foreach (array(array(false),array(1,false)) as $failures) {
			$this->db->inserts = array();
			$this->db->queries = array();
			$this->db->query_returns = $failures;
			try { $this->repository->publish(self::UUID,$this->checks()); $this->fail('Expected failure'); }
			catch (RuntimeException $error) { $this->assertSame('Diagnostic publication failed.',$error->getMessage()); }
			$this->assertSame(array(),$this->db->inserts);
		}
	}
	public function test_read_and_mail_count_failures_roll_back_without_leaking_errors(): void {
		foreach (array('fail_read','fail_counts') as $flag) {
			$this->db->$flag = true;
			try { $this->repository->publish(self::UUID,$this->checks()); $this->fail('Expected failure'); }
			catch (RuntimeException $error) { $this->assertSame('Diagnostic publication failed.',$error->getMessage()); }
			$this->assertSame(array(),$this->db->inserts);
			$this->assertSame('ROLLBACK',end($this->db->queries));
			$this->db->$flag = false;
		}
	}
	public function test_nontransactional_tables_fail_before_writes(): void {
		$this->db->engines = 1;
		try { $this->repository->publish(self::UUID,$this->checks()); $this->fail('Expected failure'); }
		catch (RuntimeException $error) { $this->assertSame('Diagnostic publication failed.',$error->getMessage()); }
		$this->assertSame(array(),$this->db->inserts);
		$this->assertSame(array(),$this->db->queries);
	}
	public function test_empty_duplicate_and_oversized_sets_cannot_publish(): void {
		$checks=$this->checks();
		foreach (array(array(),array($checks[0],$checks[0]),array_fill(0,21,$checks[0])) as $invalid) {
			try { $this->repository->publish(self::UUID,$invalid); $this->fail('Expected failure'); }
			catch (RuntimeException $error) { $this->assertSame('Diagnostic publication failed.',$error->getMessage()); }
		}
		$this->assertSame(array(),$this->db->queries);
	}
}
