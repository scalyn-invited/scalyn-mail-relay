<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DeliveryEventRepository;

final class DeliveryEventDb extends WpdbStub {
	public array $rows=[];
	public string $last_error='';
	public bool $failTimeline=false;
	public int $engines=4;
	public string $member='';
	public function get_var(string $query): mixed { return str_contains($query,'information_schema') ? $this->engines : $this->member; }
	public function get_row(string $query,string $output=OBJECT): array|object|null { return array_shift($this->rows); }
	public function insert(string $table,array $data,mixed $format=null): int|false {
		return $this->failTimeline && str_ends_with($table,'mail_timeline') ? false : parent::insert($table,$data,$format);
	}
}

final class DeliveryEventRepositoryTest extends TestCase {
	private const ID='12345678-1234-4234-8234-123456789abc';
	private DeliveryEventDb $db;
	private DeliveryEventRepository $repo;
	protected function setUp(): void {
		$GLOBALS['wpdb']=$this->db=new DeliveryEventDb();
		$this->repo=new DeliveryEventRepository();
		$this->db->rows=[['provider_message_id'=>null],null];
		$this->db->member=str_repeat('a',64);
	}
	protected function tearDown(): void { unset($GLOBALS['wpdb']); }
	public function test_new_providers_commit_and_deduplicate_with_provider_scoped_lock(): void {
		foreach(['smtp2go'=>'AbCd-1234','brevo'=>'<UpperCase@relay.test>'] as $p=>$id) {
			$this->setUp();
			$event=$this->event();$event['provider']=$p;$event['provider_message_id']=$id;$event['authentication_method']=$p.'_bearer_tls';
			$this->assertSame('stored',$this->append($event));
			$lock=array_values(array_filter($this->db->prepare_calls,fn($call)=>str_contains($call['query'],'SELECT provider_message_id')))[0] ?? null;
			$this->assertContains($p,$lock['args']);
			$stored=$this->db->inserts[0]['data'];
			$this->db->rows=[['provider_message_id'=>$id],$stored];
			$this->assertSame('duplicate',$this->append($event));
			$this->assertCount(2,$this->db->inserts);
			$bad=$event;$bad['authentication_method']='postmark_basic_tls';
			try{$this->append($bad);$this->fail('Wrong authentication');}catch(RuntimeException $e){$this->assertCount(2,$this->db->inserts);}
		}
	}
	private function event(): array {
		return ['schema_version'=>1,'source_id'=>self::ID,'provider'=>'postmark','message_uuid'=>self::ID,'provider_message_id'=>self::ID,'event_key'=>str_repeat('b',64),'kind'=>'delivery','recipient_token'=>str_repeat('a',64),'occurred_at'=>'2026-10-02T00:00:00.000000Z','received_at'=>'2026-10-02T00:01:00.000000Z','authentication_method'=>'postmark_basic_tls','reason_code'=>null];
	}
	private function append(?array $event=null): string { return $this->repo->append($event ?? $this->event(),'2026-10-01 00:00:00'); }
	public function test_event_and_projection_commit_without_rewriting_mail_status_or_exposing_tokens(): void {
		$this->assertSame('stored',$this->append());
		$this->assertCount(2,$this->db->inserts);
		$this->assertSame('wp_scalyn_delivery_events',$this->db->inserts[0]['table']);
		$this->assertSame('wp_scalyn_mail_timeline',$this->db->inserts[1]['table']);
		$timeline=$this->db->inserts[1]['data'];
		$this->assertSame('',$timeline['event_status']);
		$this->assertSame('Delivered (recipient server)',$timeline['event_label']);
		$this->assertStringNotContainsString(str_repeat('a',64),serialize($timeline));
		$this->assertStringNotContainsString(str_repeat('b',64),serialize($timeline));
		$this->assertSame('COMMIT',end($this->db->queries));
		$this->assertFalse($this->db->errors_suppressed);
		$this->assertSame('wp_scalyn_delivery_attempts',$this->db->updates[0]['table']);
	}
	public function test_committed_duplicate_changes_neither_evidence_nor_timeline(): void {
		$stored=$this->event(); $stored['occurred_at']='2026-10-02 00:00:00.000000'; $stored['received_at']='2026-10-02 00:01:00.000000';
		$this->db->rows=[['provider_message_id'=>self::ID],$stored];
		$event=$this->event(); $event['received_at']='2026-10-02T00:02:00.000000Z';
		$this->assertSame('duplicate',$this->append($event));
		$this->assertSame([],$this->db->inserts);
		$this->assertSame([],$this->db->updates);
		$this->assertSame('COMMIT',end($this->db->queries));
	}
	public function test_timeline_failure_and_uncertain_commit_never_report_success(): void {
		foreach(['timeline','commit'] as $failure) {
			$this->setUp();
			$this->db->failTimeline=$failure==='timeline';
			$this->db->query_returns=$failure==='commit' ? [1,false,1] : [];
			try { $this->append(); $this->fail('Must not acknowledge'); }
			catch(RuntimeException $e) { $this->assertSame('Delivery evidence could not be committed.',$e->getMessage()); $this->assertNull($e->getPrevious()); }
			$this->assertSame('ROLLBACK',end($this->db->queries));
			$this->assertFalse($this->db->errors_suppressed);
		}
	}
	public function test_missing_expired_wrong_identity_and_missing_membership_are_ignored(): void {
		foreach([null,['provider_message_id'=>'22345678-1234-4234-8234-123456789abc'],['provider_message_id'=>null]] as $attempt) {
			$this->db->rows=[$attempt]; $this->db->member='';
			$this->assertSame('ignored',$this->append());
			$this->assertSame([],$this->db->inserts);
		}
	}
	public function test_storage_and_contract_fail_closed(): void {
		$bad=$this->event(); $bad['raw_payload']='secret';
		try { $this->append($bad); $this->fail('Reject unknown fields'); }
		catch(RuntimeException $e) { $this->assertSame([],$this->db->queries); }
		$bad=$this->event(); $bad['occurred_at']='2026-02-31T00:00:00.000000Z';
		try { $this->append($bad); $this->fail('Reject invalid date'); }
		catch(RuntimeException $e) { $this->assertSame([],$this->db->queries); }
		$this->db->engines=3;
		try { $this->append(); $this->fail('Reject MyISAM'); }
		catch(RuntimeException $e) { $this->assertSame([],$this->db->queries); }
		$this->assertSame([],$this->db->inserts);
	}
	public function test_bounce_remains_separate_evidence_and_conflicting_replay_fails(): void {
		$event=$this->event(); $event['kind']='bounce'; $event['reason_code']='hard_bounce';
		$this->assertSame('stored',$this->append($event));
		$this->assertSame('Bounce reported',$this->db->inserts[1]['data']['event_label']);
		$stored=$this->db->inserts[0]['data'];
		$this->db->rows=[['provider_message_id'=>self::ID],$stored];
		$this->expectException(RuntimeException::class);
		$this->append();
	}
}
