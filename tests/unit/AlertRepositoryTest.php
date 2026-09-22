<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Alerts\AlertRepository;

class AlertWpdbStub extends WpdbStub {
	public array $result_sets=[];
	public int $engines=2;
	public bool $locked=false;
	public int $fail_insert_at=0;
	private int $writes=0;
	private int $savepoint=0;
	public function get_var(string $sql): mixed {
		if(str_contains($sql,'information_schema')) return $this->engines;
		if(str_contains($sql,'GET_LOCK')) return $this->locked ? 0 : 1;
		if(str_contains($sql,'RELEASE_LOCK')) return 1;
		return 0;
	}
	public function get_results(string $sql,string $output=OBJECT): array {return array_shift($this->result_sets) ?? [];}
	public function query(string $sql): int|false {
		if($sql==='START TRANSACTION') $this->savepoint=count($this->inserts);
		if($sql==='ROLLBACK') $this->inserts=array_slice($this->inserts,0,$this->savepoint);
		return parent::query($sql);
	}
	public function insert(string $table,array $data,mixed $format=null): int|false {
		if(++$this->writes===$this->fail_insert_at) return false;
		return parent::insert($table,$data,$format);
	}
}

final class AlertRepositoryTest extends TestCase {
	private AlertWpdbStub $db;
	private AlertRepository $repo;
	protected function setUp():void {
		$GLOBALS['wpdb']=$this->db=new AlertWpdbStub();
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.2.0'];
		$this->repo=new AlertRepository();
	}
	public function test_opening_is_atomic_and_disabled_notification_is_skipped():void {
		$this->assertTrue($this->repo->exclusive(function(){
			$change=$this->repo->transition('health',true,false,100000);
			$this->assertSame('opened',$change['event']);
		}));
		$this->assertCount(2,$this->db->inserts);
		$this->assertSame('active',$this->db->inserts[0]['data']['status']);
		$this->assertSame('skipped',$this->db->inserts[1]['data']['status']);
		$this->assertSame($this->db->inserts[0]['data']['alert_uuid'],$this->db->inserts[1]['data']['alert_uuid']);
		$this->assertSame(['START TRANSACTION','COMMIT'],$this->db->queries);
	}
	public function test_outbox_failure_rolls_back_incident():void {
		$this->db->fail_insert_at=2;
		$this->assertFalse($this->repo->exclusive(fn()=> $this->repo->transition('health',true,true,100000)));
		$this->assertSame([],$this->db->inserts);
		$this->assertSame(['START TRANSACTION','ROLLBACK'],$this->db->queries);
	}
	public function test_repeated_observation_does_not_create_another_notification():void {
		$this->db->result_sets=[[['alert_uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','status'=>'active']]];
		$this->repo->exclusive(function(){ $this->assertNull($this->repo->transition('health',true,true,100000)); });
		$this->assertSame([],$this->db->inserts);
	}
	public function test_recovery_cancels_unsent_opening_and_queues_recovery():void {
		$this->db->result_sets=[[['alert_uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','status'=>'active']]];
		$this->repo->exclusive(function(){ $this->assertSame('resolved',$this->repo->transition('health',false,true,100000)['event']); });
		$this->assertCount(1,$this->db->inserts);
		$this->assertSame('recovered',$this->db->inserts[0]['data']['event']);
		$this->assertStringContainsString("status = 'cancelled'",$this->db->queries[2]);
	}
	public function test_reopened_notification_observes_cooldown():void {
		$this->db->result_sets=[[['status'=>'resolved']],[['created_at'=>gmdate('Y-m-d H:i:s',99900),'last_attempt_at'=>null]]];
		$this->repo->exclusive(fn()=> $this->repo->transition('health',true,true,100000));
		$this->assertSame(gmdate('Y-m-d H:i:s',100800),$this->db->inserts[1]['data']['next_attempt_at']);
	}
	public function test_unknown_condition_and_unowned_writes_do_nothing():void {
		$this->assertNull($this->repo->transition('health',true,true,100000));
		$this->repo->exclusive(function(){ $this->assertNull($this->repo->transition('health',null,true,100000)); });
		$this->assertSame([],$this->db->inserts);
	}
	public function test_lock_and_engine_guards_fail_closed():void {
		$ran=false;
		$this->db->locked=true;
		$this->assertFalse($this->repo->exclusive(function()use(&$ran){$ran=true;}));
		$this->db->locked=false;
		$this->db->engines=1;
		$this->assertFalse($this->repo->exclusive(function()use(&$ran){$ran=true;}));
		$this->assertFalse($ran);
	}
	public function test_recursive_lock_is_rejected_and_released_after_work():void {
		$this->assertTrue($this->repo->exclusive(function(){ $this->assertFalse((new AlertRepository())->exclusive(fn()=>null)); }));
		$this->assertTrue($this->repo->exclusive(fn()=>null));
	}
	public function test_old_schema_cannot_write():void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.1.0';
		$this->assertFalse($this->repo->exclusive(fn()=> $this->repo->transition('health',true,true,100000)));
		$this->assertSame([],$this->db->inserts);
	}
	public function test_retention_rolls_back_both_tables_when_incident_delete_fails():void {
		$this->db->result_sets=[[['alert_uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa']]];
		$this->db->query_returns=[1,1,false,1];
		$this->assertFalse($this->repo->exclusive(fn()=> $this->repo->retain(100000)));
		$this->assertSame('ROLLBACK',end($this->db->queries));
	}
	public function test_history_does_not_expose_legacy_fields():void {
		$this->db->result_sets=[[['id'=>1,'alert_uuid'=>'secret','alert_type'=>'secret','status'=>'secret','created_at'=>'secret','resolved_at'=>null]],[['event'=>'secret','status'=>'secret','attempts'=>99,'response_code'=>999]]];
		$page=$this->repo->page();
		$this->assertStringNotContainsString('secret',json_encode($page));
		$this->assertSame(3,$page['rows'][0]['notifications'][0]['attempts']);
	}
}
