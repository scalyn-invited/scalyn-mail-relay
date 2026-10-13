<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Admin\ClientOverview;
use Scalyn\MailRelay\Admin\ClientReportController;
use Scalyn\MailRelay\Agency\ManagedAccess;
use Scalyn\MailRelay\Core\Capabilities;

final class ClientOverviewTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = [];
		$GLOBALS['_test_user_meta'] = [];
		$GLOBALS['_test_current_user_id'] = 1;
		$GLOBALS['_test_blog_id'] = 1;
		$GLOBALS['_test_timezone'] = 'UTC';
	}
	protected function tearDown(): void { $this->setUp(); }
	private function activity(): array {
		return ['available'=>true,'start'=>'2026-10-05 12:00:00','end'=>'2026-10-12 12:00:00','total'=>20,'accepted'=>18,'failed'=>2,'other'=>0];
	}
	private function snapshot(string $created='2026-10-12 11:00:00', mixed $score=96): array {
		return ['health'=>['created_at'=>$created,'overall_score'=>$score,'summary'=>'private summary'], 'domain'=>'private.example', 'results'=>[['raw_result'=>'secret','result_message'=>'recipient@example.test']]];
	}
	public function test_projection_contains_only_summary_evidence(): void {
		$result=ClientOverview::project($this->snapshot(),$this->activity(),'daily',strtotime('2026-10-12 12:00:00 UTC'));
		$this->assertSame('healthy',$result['health']); $this->assertSame(96,$result['score']);
		$this->assertSame('monitor',$result['recommendation']);
		$this->assertSame(18,$result['activity']['accepted']);
		$json=json_encode($result);
		foreach(['private','secret','recipient','domain','results'] as $unsafe) $this->assertStringNotContainsString($unsafe,$json);
	}
	#[DataProvider('unavailable_health')]
	public function test_unknown_stale_and_invalid_evidence_are_not_pass_or_fail(string $created, mixed $score, string $expected): void {
		$result=ClientOverview::project($this->snapshot($created,$score),$this->activity(),'daily',strtotime('2026-10-12 12:00:00 UTC'));
		$this->assertSame($expected,$result['health']); $this->assertNull($result['score']);
		$this->assertSame('request_check',$result['recommendation']);
	}
	public static function unavailable_health(): array {
		return [['2026-10-01 12:00:00',96,'stale'],['2026-10-13 12:00:00',96,'unknown'],['invalid',100,'unknown'],['2026-02-31 12:00:00',100,'unknown'],['2026-10-12 11:00:00',null,'unknown'],['2026-10-12 11:00:00',101,'unknown'],['2026-10-12 11:00:00',-1,'unknown'],['2026-10-12 11:00:00','96secret','unknown']];
	}
	public function test_read_failure_remains_unavailable_not_zero(): void {
		$result=ClientOverview::project([],['available'=>false],'daily',time());
		$this->assertFalse($result['activity']['available']); $this->assertNull($result['activity']['total']);
		$this->assertSame('unknown',$result['health']);
	}
	public function test_inconsistent_counts_are_not_reported(): void {
		$counts=$this->activity(); $counts['total']=1;
		$result=ClientOverview::project([],$counts,'daily',time());
		$this->assertFalse($result['activity']['available']); $this->assertNull($result['activity']['accepted']);
	}
	public function test_client_view_does_not_replace_administrator_or_agency_dashboard(): void {
		$GLOBALS['_test_user_meta'][1][ManagedAccess::profile_key()]='client';
		$this->assertTrue(ManagedAccess::is_client());
		$GLOBALS['_test_current_user_can']['manage_options']=true;
		$this->assertFalse(ManagedAccess::is_client());
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_AGENCY=>true];
		$this->assertFalse(ManagedAccess::is_client());
	}
	#[DataProvider('denied_exports')]
	public function test_export_denies_before_any_reads(array $caps, array $input, string $method): void {
		$GLOBALS['_test_current_user_can']=$caps;
		$this->expectException(RuntimeException::class);
		(new ClientReportController())->prepare($input,$method);
	}
	public static function denied_exports(): array {
		return [[[],['_wpnonce'=>'valid-client-nonce'],'POST'],[[Capabilities::VIEW_CLIENT_REPORTS=>true],['_wpnonce'=>'bad'],'POST'],[[Capabilities::VIEW_CLIENT_REPORTS=>true],['_wpnonce'=>'valid-client-nonce'],'GET'],[[Capabilities::EXPORT_REPORTS=>true],['_wpnonce'=>'valid-client-nonce'],'POST']];
	}
	public function test_csv_is_fixed_projection_and_formula_safe(): void {
		$result=ClientOverview::project($this->snapshot(),$this->activity(),'daily',strtotime('2026-10-12 12:00:00 UTC'));
		$result['raw_result']='secret';
		$csv=ClientReportController::encode($result,'=HYPERLINK("evil")');
		$this->assertStringContainsString("\"'=HYPERLINK",$csv);
		$this->assertStringNotContainsString('secret',$csv);
		$this->assertStringContainsString('not confirmed inbox delivery',$csv);
	}
	public function test_client_view_has_no_operational_actions_or_sensitive_data(): void {
		$summary=ClientOverview::project($this->snapshot(),$this->activity(),'daily',strtotime('2026-10-12 12:00:00 UTC'));
		ob_start(); require SCALYN_MAIL_RELAY_PATH.'admin/views/client-overview.php'; $html=ob_get_clean();
		foreach(['recipient@example.test','private.example','secret','Run Diagnostics','Test Email','api_key','message_uuid'] as $unsafe) $this->assertStringNotContainsString($unsafe,$html);
		$this->assertStringContainsString('Download summary CSV',$html);
		$this->assertStringContainsString('not a delivery rate',$html);
	}
}
