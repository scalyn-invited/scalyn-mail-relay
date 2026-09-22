<?php

require_once __DIR__.'/AlertRepositoryTest.php';
require_once __DIR__.'/WebhookChannelTest.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Alerts\AlertRepository;
use Scalyn\MailRelay\Alerts\ObservationRepository;
use Scalyn\MailRelay\Alerts\WebhookChannel;
use Scalyn\MailRelay\Alerts\AlertService;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\HookNames;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AlertServiceTest extends TestCase {
	private AlertWpdbStub $db;
	protected function setUp():void {
		define('SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL','https://example.com/qa');
		$GLOBALS['wpdb']=$this->db=new AlertWpdbStub();
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.2.0',SettingsRepository::OPTION_KEY=>['advanced'=>['alert_webhook_enabled'=>true]]];
		$GLOBALS['_alert_http_calls']=[];
		$GLOBALS['_test_wp_added_actions']=[];
		$GLOBALS['_test_wp_actions']=[HookNames::AUDIT_EVENT=>static function($event){$GLOBALS['_alert_audit'][]=$event;}];
		$GLOBALS['_alert_audit']=[];
	}
	private function run_job(int $attempts,int $code):void {
		$GLOBALS['_alert_http_response']=['response'=>['code'=>$code]];
		$this->db->result_sets=[[],[],[['notification_uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','alert_uuid'=>'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb','event'=>'opened','alert_type'=>'health','attempts'=>$attempts]],[]];
		(new AlertService(new AlertRepository(),new ObservationRepository(),new WebhookChannel()))->tick();
	}
	#[DataProvider('outcomes')]
	public function test_bounded_attempts_and_audit(int $attempts,int $code,string $status,int $requests):void {
		$this->run_job($attempts,$code);
		$this->assertCount($requests,$GLOBALS['_alert_http_calls']);
		$updates=array_values(array_filter($this->db->prepare_calls,static fn($call)=>str_starts_with($call['query'],'UPDATE %i SET status=%s')));
		$this->assertSame($status,end($updates)['args'][1]);
		$this->assertSame(min(3,$attempts+1),end($updates)['args'][2]);
		if($requests) $this->assertSame('sending',$updates[0]['args'][1]);
		$this->assertSame($status==='pending'?'retry':$status,$GLOBALS['_alert_audit'][0]->outcome);
		$this->assertSame('completed',(new AlertRepository())->status()['state']);
	}
	public static function outcomes():array {return [[0,204,'sent',1],[0,503,'pending',1],[1,503,'pending',1],[2,503,'failed',1],[3,204,'failed',0]];}
	public function test_disabled_queue_is_skipped_without_http():void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['advanced']['alert_webhook_enabled']=false;
		$this->run_job(0,204);
		$this->assertCount(0,$GLOBALS['_alert_http_calls']);
		$this->assertSame('skipped',$GLOBALS['_alert_audit'][0]->outcome);
	}
	public function test_failed_claim_prevents_network_and_reports_failed_tick():void {
		$this->db->query_returns=[false];
		$this->run_job(0,204);
		$this->assertCount(0,$GLOBALS['_alert_http_calls']);
		$this->assertSame('failed',(new AlertRepository())->status()['state']);
	}
	public function test_epoch_run_state_is_observed_as_recovery():void {
		$now=time();
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['advanced']['diagnostic_schedule']='hourly';
		$GLOBALS['_test_wp_cron'][\Scalyn\MailRelay\Core\ScheduledHooks::DIAGNOSTICS]=$now+3600;
		$GLOBALS['_test_wp_recurrence'][\Scalyn\MailRelay\Core\ScheduledHooks::DIAGNOSTICS]='hourly';
		$GLOBALS['_test_wp_options'][\Scalyn\MailRelay\Database\DiagnosticRunStateRepository::OPTION_KEY]=['scheduled'=>['uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','source'=>'scheduled','state'=>'completed','started_at'=>$now-10,'finished_at'=>$now-1,'failure_code'=>'']];
		$this->assertFalse((new ObservationRepository())->conditions($now)['monitoring']);
	}
	public function test_recovery_notification_has_recovery_specific_message():void {
		$channel=new WebhookChannel();
		$this->assertSame(204,$channel->send(['notification_uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','alert_uuid'=>'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb','event'=>'recovered','alert_type'=>'health']));
		$body=json_decode($GLOBALS['_alert_http_calls'][0][1]['body'],true);
		$this->assertStringContainsString('reached at least 75',$body['message']);
	}
}
