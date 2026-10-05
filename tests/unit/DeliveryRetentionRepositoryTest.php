<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DeliveryRetentionRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Logging\MailRetentionRepository;

final class DeliveryRetentionDb extends WpdbStub {
	public string $last_error='';
	public function get_var(string $query): mixed {
		if(str_contains($query,'information_schema')) { return str_contains($query,'(%s,%s)') ? '2' : '4'; }
		return parent::get_var($query);
	}
}

final class DeliveryRetentionRepositoryTest extends TestCase {
	private const ID='12345678-1234-4234-8234-123456789abc';
	private DeliveryRetentionDb $db;
	protected function setUp(): void { $GLOBALS['wpdb']=$this->db=new DeliveryRetentionDb(); $GLOBALS['_test_wp_options']=[]; }
	protected function tearDown(): void { unset($GLOBALS['wpdb']); $GLOBALS['_test_wp_options']=[]; }
	public function test_expiry_is_bounded_by_attempt_age_and_all_children_are_atomic(): void {
		$this->db->get_col_returns=[[self::ID],[self::ID]];
		$this->assertSame(1,(new DeliveryRetentionRepository())->delete_expired_batch('2026-09-01 00:00:00',999));
		$this->assertSame('START TRANSACTION',$this->db->queries[0]);
		$this->assertSame('COMMIT',end($this->db->queries));
		$deletes=array_values(array_filter($this->db->prepare_calls,fn($q)=>str_starts_with($q['query'],'DELETE')));
		$this->assertSame(['wp_scalyn_delivery_events','wp_scalyn_delivery_recipients','wp_scalyn_mail_timeline','wp_scalyn_delivery_attempts'],array_column(array_column($deletes,'args'),0));
		$this->assertStringContainsString("event_type='delivery_evidence'",$deletes[2]['query']);
		$select=array_values(array_filter($this->db->prepare_calls,fn($q)=>str_contains($q['query'],'created_at <')))[0];
		$this->assertSame(['wp_scalyn_delivery_attempts','2026-09-01 00:00:00',250],$select['args']);
		$this->assertStringContainsString('FOR UPDATE',$select['query']);
		$this->assertFalse($this->db->errors_suppressed);
	}
	public function test_empty_batch_does_not_delete(): void {
		$this->assertSame(0,(new DeliveryRetentionRepository())->delete_expired_batch('2026-09-01 00:00:00'));
		$this->assertSame(['START TRANSACTION','COMMIT'],$this->db->queries);
	}
	public function test_each_delete_and_commit_failure_rolls_back(): void {
		for($failure=1;$failure<=5;$failure++) {
			$this->setUp(); $this->db->get_col_returns=[[self::ID],[self::ID]];
			$this->db->query_returns=array_merge(array_fill(0,$failure,1),[false,1]);
			try { (new DeliveryRetentionRepository())->delete_expired_batch('2026-09-01 00:00:00'); $this->fail('Must roll back'); }
			catch(RuntimeException $e) { $this->assertSame('Delivery retention cleanup failed.',$e->getMessage()); }
			$this->assertSame('ROLLBACK',end($this->db->queries));
			$this->assertFalse($this->db->errors_suppressed);
		}
	}
	public function test_invalid_boundary_or_targets_never_delete(): void {
		foreach(['2026-02-31 00:00:00','yesterday'] as $cutoff) {
			try { (new DeliveryRetentionRepository())->delete_expired_batch($cutoff); $this->fail('Must reject'); }
			catch(RuntimeException $e) { $this->assertSame([],$this->db->queries); }
		}
		$this->expectException(RuntimeException::class);
		(new DeliveryRetentionRepository())->delete_for_messages(['arbitrary']);
	}
	public function test_mail_log_cleanup_locks_and_deletes_delivery_children_inside_same_transaction(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.6.0';
		$this->db->get_col_returns=[[self::ID],[self::ID]];
		$result=(new MailRetentionRepository())->delete_expired_batch('2026-09-01 00:00:00');
		$this->assertSame(2,$result->deleted_timeline_events);
		$this->assertSame(1,count(array_filter($this->db->queries,fn($q)=>$q==='START TRANSACTION')));
		$this->assertSame('COMMIT',end($this->db->queries));
	}
	public function test_retired_keys_are_rechecked_for_references_before_delete(): void {
		$this->db->get_col_return=[self::ID];
		$keys=new DeliveryKeyRepository(new CredentialCipher('not-needed-for-deletion'));
		$this->db->get_var_return=self::ID;
		$this->assertSame(0,$keys->prune_retired());
		$this->assertSame(['START TRANSACTION','COMMIT'],$this->db->queries);
		$this->db->queries=[]; $this->db->get_var_return=null;
		$this->assertSame(1,$keys->prune_retired());
		$this->assertStringContainsString('retired_at IS NOT NULL',$this->db->queries[1]);
		$this->assertSame('COMMIT',end($this->db->queries));
	}
	public function test_key_retirement_and_deletion_errors_are_safe(): void {
		$keys=new DeliveryKeyRepository(new CredentialCipher('not-needed'));
		$this->db->get_var_return='2026-10-02 00:00:00';
		$keys->retire(self::ID);
		$this->assertStringContainsString('retired_at IS NULL',$this->db->queries[0]);
		$this->assertFalse($this->db->errors_suppressed);
		$this->db->get_var_return=null;
		$this->db->get_col_return=[self::ID];
		$this->db->query_returns=[1,false,1];
		try { $keys->prune_retired(); $this->fail('Must roll back'); }
		catch(RuntimeException $e) { $this->assertSame('Recipient matching key cleanup failed.',$e->getMessage()); }
		$this->assertSame('ROLLBACK',end($this->db->queries));
	}
}
