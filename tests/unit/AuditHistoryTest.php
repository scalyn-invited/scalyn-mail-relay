<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Audit\AuditActor;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Admin\Pages\AuditPage;
use Scalyn\MailRelay\Core\Capabilities;

final class AuditHistoryTest extends TestCase {
	private WpdbStub $db;
	protected function setUp(): void {
		$this->db = new WpdbStub();
		$GLOBALS['wpdb']=$this->db;
		$GLOBALS['_test_current_user_id']=42;
		$GLOBALS['_test_is_admin']=true;
		$GLOBALS['_test_doing_cron']=false;
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		$_GET=[];
	}
	protected function tearDown(): void {
		$GLOBALS['_test_current_user_id']=1;
		$GLOBALS['_test_is_admin']=false;
		$GLOBALS['_test_doing_cron']=false;
		$_GET=[];
	}
	private function row(int $id=10): array {
		return ['id'=>$id,'user_id'=>42,'action'=>'test_email','resource_id'=>'12345678-1234-4234-8234-123456789abc','created_at'=>'2026-09-21 12:00:00','metadata'=>json_encode(['version'=>2,'source'=>'manual','outcome'=>'accepted','changed_fields'=>[]])];
	}
	public function test_identity_is_captured_and_cron_does_not_impersonate_user(): void {
		$_POST=['user_id'=>99,'source'=>'scheduled'];
		$event=new AuditEvent('test_email','accepted');
		$this->assertSame(42,$event->actor->user_id);
		$this->assertSame('manual',$event->actor->source);
		$GLOBALS['_test_current_user_id']=7;
		(new AuditRepository())->append($event);
		$this->assertSame(42,$this->db->inserts[0]['data']['user_id']);
		$GLOBALS['_test_doing_cron']=true;
		$this->assertSame(0,AuditActor::capture()->user_id);
		$this->assertSame('scheduled',AuditActor::capture()->source);
		$_POST=[];
	}
	public function test_actor_rejects_unknown_context(): void {
		$this->expectException(InvalidArgumentException::class);
		new AuditActor(42,'password=secret');
	}
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function test_rest_context_is_not_mislabeled_manual(): void {
		define('REST_REQUEST',true);
		$this->assertSame('rest',AuditActor::capture()->source);
		$this->assertSame(42,AuditActor::capture()->user_id);
	}
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function test_cli_context_and_anonymous_actor(): void {
		define('WP_CLI',true);
		$GLOBALS['_test_current_user_id']=0;
		$this->assertSame('cli',AuditActor::capture()->source);
		$this->assertSame(0,AuditActor::capture()->user_id);
	}
	public function test_request_secrets_never_enter_audit_storage(): void {
		$_POST=['password'=>'private-password','body'=>'private-body','token'=>'private-token','Authorization'=>'Bearer private-auth'];
		$_SERVER['HTTP_USER_AGENT']='private-agent'; $_SERVER['REMOTE_ADDR']='192.0.2.42';
		(new AuditRepository())->append(new AuditEvent('settings_changed','changed','',['smtp.password']));
		$serialized=json_encode($this->db->inserts);
		$this->assertStringNotContainsString('private-',$serialized);
		$this->assertStringNotContainsString('192.0.2.42',$serialized);
		$_POST=[]; unset($_SERVER['HTTP_USER_AGENT'],$_SERVER['REMOTE_ADDR']);
	}
	public function test_read_failure_has_fixed_ui_error_without_raw_sql(): void {
		$db=new class extends WpdbStub { public function get_results(string $query,string $output=OBJECT): array { throw new RuntimeException('private SQL'); } };
		$GLOBALS['wpdb']=$db;
		ob_start(); (new AuditPage(new AuditRepository()))->render(); $html=ob_get_clean();
		$this->assertStringContainsString('Audit history is unavailable',$html);
		$this->assertStringNotContainsString('private SQL',$html);
	}
	public function test_retention_query_failures_rollback_safely(): void {
		foreach ([[false],[0,false,0],[0,1,false,0]] as $results) {
			$this->db->queries=[]; $this->db->get_col_return=[1]; $this->db->query_returns=$results;
			try { (new AuditRepository())->delete_expired_batch('2026-08-01 00:00:00'); $this->fail('Failure ignored'); }
			catch(RuntimeException $e) { $this->assertSame('Audit retention failed.',$e->getMessage()); }
			if ($results[0] !== false) { $this->assertContains('ROLLBACK',$this->db->queries); }
		}
	}
	public function test_read_model_is_bounded_and_excludes_injected_metadata(): void {
		$row=$this->row();
		$meta=json_decode($row['metadata'],true); $meta['password']='secret-value'; $row['metadata']=json_encode($meta);
		$this->db->get_results_return=array_fill(0,51,$row);
		$page=(new AuditRepository())->page(123);
		$this->assertCount(50,$page['rows']);
		$this->assertSame(10,$page['next']);
		$this->assertStringContainsString('LIMIT 51',$this->db->prepare_calls[0]['query']);
		$this->assertSame([123,123],$this->db->prepare_calls[0]['args']);
		$this->assertStringNotContainsString('secret-value',json_encode($page));
		$this->assertSame('manual',$page['rows'][0]['source']);
	}
	public function test_legacy_rows_remain_unattributed_and_malformed_rows_are_safe(): void {
		$old=$this->row(); $old['metadata']=json_encode(['version'=>1,'outcome'=>'accepted','changed_fields'=>[]]);
		$bad=$this->row(); $bad['action']='<script>secret-value</script>';
		$this->db->get_results_return=[$old,$bad];
		$rows=(new AuditRepository())->page()['rows'];
		$this->assertSame('unknown',$rows[0]['source']);
		$this->assertSame(0,$rows[0]['user_id']);
		$this->assertSame('unknown',$rows[1]['action']);
		$this->assertStringNotContainsString('secret-value',json_encode($rows));
	}
	public function test_page_rejects_user_before_reading(): void {
		$GLOBALS['_test_current_user_can']=[];
		try { (new AuditPage(new AuditRepository()))->render(); $this->fail('Unauthorized page rendered'); }
		catch(RuntimeException $e) { $this->assertSame([],$this->db->prepare_calls); }
	}
	public function test_page_renders_safe_rows_and_cursor_navigation(): void {
		$this->db->get_results_return=array_fill(0,51,$this->row());
		ob_start(); (new AuditPage(new AuditRepository()))->render(); $html=ob_get_clean();
		$this->assertStringContainsString('#42',$html);
		$this->assertStringContainsString('Older events',$html);
		$this->assertStringContainsString('before=10',$html);
		$this->assertStringContainsString('accepted',$html);
		$this->assertStringNotContainsString('metadata',$html);
	}
	public function test_retention_deletes_only_locked_expired_ids(): void {
		$this->db->get_col_return=[1,2];
		$this->db->query_returns=[0,2,0];
		$this->assertSame(2,(new AuditRepository())->delete_expired_batch('2026-08-01 00:00:00'));
		$this->assertStringContainsString('LIMIT 100 FOR UPDATE',$this->db->prepare_calls[0]['query']);
		$this->assertStringContainsString('AND created_at < %s',$this->db->prepare_calls[1]['query']);
		$this->assertSame([1,2,'2026-08-01 00:00:00'],$this->db->prepare_calls[1]['args']);
		$this->assertContains('COMMIT',$this->db->queries);
	}
	public function test_retention_rolls_back_partial_delete_and_can_retry(): void {
		$this->db->get_col_return=[1,2]; $this->db->query_returns=[0,1,0];
		try { (new AuditRepository())->delete_expired_batch('2026-08-01 00:00:00'); $this->fail('Partial delete accepted'); }
		catch(RuntimeException $e) { $this->assertContains('ROLLBACK',$this->db->queries); }
		$this->db->query_returns=[0,2,0];
		$this->assertSame(2,(new AuditRepository())->delete_expired_batch('2026-08-01 00:00:00'));
	}
	public function test_invalid_retention_boundary_never_starts_transaction(): void {
		try { (new AuditRepository())->delete_expired_batch('2026-02-31 00:00:00'); $this->fail('Invalid cutoff accepted'); }
		catch(InvalidArgumentException $e) { $this->assertSame([],$this->db->queries); }
	}
}
