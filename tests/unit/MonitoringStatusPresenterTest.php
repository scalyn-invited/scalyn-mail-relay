<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\MonitoringStatusPresenter as Presenter;
use Scalyn\MailRelay\Database\DiagnosticRunStateRepository;

final class MonitoringStatusPresenterTest extends TestCase {
	private function record(string $state='completed',int $finished=1000): array {
		return array('uuid'=>'74000000-0000-4000-8000-000000000001','source'=>'scheduled','state'=>$state,'started_at'=>900,'finished_at'=>$finished,'failure_code'=>$state==='failed'?'publication_failed':'');
	}
	public function test_disabled_never_and_missing_schedule_are_not_passes(): void {
		$this->assertStringContainsString('disabled',Presenter::present('disabled',array(),false,false,1000)['schedule']);
		$view=Presenter::present('hourly',array(),false,false,1000);
		$this->assertStringContainsString('missing',$view['schedule']);
		$this->assertStringContainsString('No scheduled completion',$view['freshness']);
		$this->assertStringContainsString('mismatched',Presenter::present('hourly',array(),2000,'daily',1000)['schedule']);
	}
	public function test_overdue_and_stale_boundaries_include_five_minute_grace(): void {
		$state=array('last_scheduled_success'=>$this->record());
		$this->assertStringContainsString('within',Presenter::present('hourly',$state,4600,'hourly',4900)['freshness']);
		$view=Presenter::present('hourly',$state,4600,'hourly',4901);
		$this->assertStringContainsString('stale',$view['freshness']);
		$this->assertStringContainsString('overdue',$view['schedule']);
	}
	public function test_manual_success_does_not_mask_failed_scheduled_run(): void {
		$state=array('last_success'=>$this->record(),'scheduled'=>$this->record('failed'),'latest'=>$this->record());
		$view=Presenter::present('daily',$state,3000,'daily',2000);
		$this->assertStringContainsString('Execution failed',$view['scheduled']);
		$this->assertStringContainsString('database',$view['scheduled']);
		$this->assertStringContainsString('No scheduled completion',$view['freshness']);
	}
	public function test_unconfirmed_and_future_completion_are_not_healthy(): void {
		$view=Presenter::present('daily',array('latest'=>$this->record('running',0),'last_scheduled_success'=>$this->record()),2000,'daily',950);
		$this->assertStringContainsString('Completion unconfirmed',$view['latest']);
		$this->assertStringContainsString('future',$view['freshness']);
	}
	public function test_retained_evidence_has_separate_age_and_timezone_handling(): void {
		$GLOBALS['_test_timezone']='Asia/Manila';
		$now=strtotime('2026-09-21 04:00:00 UTC');
		$this->assertStringContainsString('Recent',Presenter::evidence('2026-09-21 12:00:00','hourly',$now));
		$this->assertStringContainsString('Stale',Presenter::evidence('2026-09-20 12:00:00','hourly',$now));
		$this->assertStringContainsString('unknown',Presenter::evidence(null,'daily',$now));
		$this->assertStringContainsString('unknown',Presenter::evidence("2026-09-21\0 12:00:00",'daily',$now));
		$this->assertStringContainsString('unknown',Presenter::evidence('2026-02-31 12:00:00','daily',$now));
		unset($GLOBALS['_test_timezone']);
	}
	public function test_rendered_panel_excludes_raw_stored_payloads_and_settings_link_without_permission(): void {
		$GLOBALS['_test_wp_options'][DiagnosticRunStateRepository::OPTION_KEY]=array('latest'=>array('uuid'=>'<script>secret</script>'));
		$GLOBALS['_test_current_user_can']=array();
		$monitoring=Presenter::load();
		ob_start();require SCALYN_MAIL_RELAY_PATH.'admin/views/monitoring-status.php';$html=ob_get_clean();
		$this->assertStringNotContainsString('secret',$html);
		$this->assertStringNotContainsString('Configure monitoring in Data Controls',$html);
		$this->assertStringContainsString('aria-labelledby',$html);
		unset($GLOBALS['_test_wp_options'][DiagnosticRunStateRepository::OPTION_KEY]);
	}
}
