<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\ReportExportController;
use Scalyn\MailRelay\Admin\Pages\AuditPage;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\Plugin;

final class ReportExportAuditTest extends TestCase {
	protected function setUp():void {
		$GLOBALS['_test_wp_added_actions']=[];
		$GLOBALS['_test_wp_actions']=[];
		$GLOBALS['_test_current_user_can']=[Capabilities::EXPORT_REPORTS=>true,Capabilities::MANAGE_SETTINGS=>true];
		$GLOBALS['_test_current_user_id']=42;
		$GLOBALS['_test_doing_cron']=false;
		$GLOBALS['wpdb']=new WpdbStub();
		(new ReflectionProperty(Plugin::class,'instance'))->setValue(null,null);
		Plugin::instance()->boot();
		$GLOBALS['wpdb']->get_var_return='3';
	}
	protected function tearDown():void {
		$GLOBALS['_test_wp_added_actions']=[];
		$GLOBALS['_test_wp_actions']=[];
		$GLOBALS['_test_current_user_can']=[];
		$GLOBALS['_test_current_user_id']=1;
		(new ReflectionProperty(Plugin::class,'instance'))->setValue(null,null);
		unset($GLOBALS['wpdb']);
	}
	private function input():array {
		return ['_wpnonce'=>'valid-export-nonce','start'=>'2026-09-01T00:00','end'=>'2026-09-02T00:00','format'=>'json','provider_scope'=>'all','provider'=>''];
	}
	private function rows():array {
		return array_values(array_map(fn($row)=>$row['data'],array_filter($GLOBALS['wpdb']->inserts,fn($row)=>$row['table']==='wp_scalyn_audit_logs')));
	}
	/** @dataProvider formats */
	public function test_exports_record_correlated_preparation_and_safe_options(string $format,bool $references):void {
		$input=array_replace($this->input(),['format'=>$format,'provider_scope'=>'specific','provider'=>'private-provider','actor'=>999,'source'=>'private-source']);
		if($references){$input['references']='1';}
		$response=(new ReportExportController())->prepare($input,'POST');
		$rows=$this->rows();
		$this->assertCount(2,$rows);
		$start=json_decode($rows[0]['metadata'],true);
		$prepared=json_decode($rows[1]['metadata'],true);
		$this->assertSame('started',$start['outcome']);
		$this->assertSame('prepared',$prepared['outcome']);
		$this->assertSame($rows[0]['resource_id'],$rows[1]['resource_id']);
		$this->assertSame(42,$rows[1]['user_id']);
		$this->assertSame(['format'=>$format,'references'=>$references,'report_uuid'=>''],$start['export']);
		$this->assertSame($response['audit_export'],$prepared['export']);
		$this->assertNotEmpty($prepared['export']['report_uuid']);
		$this->assertStringNotContainsString('private-',json_encode($rows));
		$this->assertStringNotContainsString('2026-09-01',json_encode($prepared));
		if($format==='json'){$this->assertSame(json_decode($response['body'],true)['report_uuid'],$prepared['export']['report_uuid']);}
		$GLOBALS['wpdb']->get_results_return=$rows;
		$page=(new AuditRepository())->page();
		$this->assertSame($prepared['export'],$page['rows'][1]['export']);
		ob_start();(new AuditPage(new AuditRepository()))->render();$html=ob_get_clean();
		$this->assertStringContainsString('Report reference:',$html);
		$this->assertStringContainsString('does not confirm that the browser received or saved it',$html);
	}
	public static function formats():array { return [['csv',false],['json',true],['pdf',false]]; }
	public function test_capture_failure_is_audited_without_exception_details():void {
		$GLOBALS['wpdb']->get_var_return='0';
		try{(new ReportExportController())->prepare($this->input(),'POST');$this->fail();}
		catch(RuntimeException $error){$this->assertSame('Report export unavailable.',$error->getMessage());}
		$rows=$this->rows();
		$this->assertCount(2,$rows);
		$this->assertSame('failed',json_decode($rows[1]['metadata'],true)['outcome']);
		$this->assertSame($rows[0]['resource_id'],$rows[1]['resource_id']);
		$this->assertSame('',json_decode($rows[1]['metadata'],true)['export']['report_uuid']);
	}
	public function test_denied_and_invalid_requests_emit_no_export_events():void {
		foreach([['_wpnonce'=>'bad'],['format'=>'html'],['end'=>'2020-01-01T00:00']] as $override){
			try{(new ReportExportController())->prepare(array_replace($this->input(),$override),'POST');$this->fail();}catch(RuntimeException $error){}
		}
		$GLOBALS['_test_current_user_can']=[];
		try{(new ReportExportController())->prepare($this->input(),'POST');$this->fail();}catch(RuntimeException $error){}
		$this->assertSame([],$this->rows());
		$this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
	}
	public function test_audit_failure_does_not_break_export():void {
		$GLOBALS['wpdb']->return_false_on_insert=true;
		$this->assertSame('scalyn-mail-relay-report.json',(new ReportExportController())->prepare($this->input(),'POST')['name']);
		$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function(){throw new RuntimeException('private observer error');};
		$this->assertSame('scalyn-mail-relay-report.json',(new ReportExportController())->prepare($this->input(),'POST')['name']);
	}
	public function test_export_contract_rejects_untrusted_metadata_and_history_hides_it():void {
		$uuid=wp_generate_uuid4();
		$good=['format'=>'csv','references'=>false,'report_uuid'=>$uuid];
		foreach([['format'=>'private-secret'],['references'=>'1'],['report_uuid'=>'private-secret'],['extra'=>'private-secret']] as $override){
			$bad=array_replace($good,$override);
			try{new AuditEvent('report_export','prepared',$uuid,[],$bad);$this->fail();}catch(InvalidArgumentException $error){}
			$GLOBALS['wpdb']->get_results_return=[['action'=>'report_export','resource_id'=>$uuid,'metadata'=>json_encode(['version'=>3,'outcome'=>'prepared','export'=>$bad])]];
			$history=(new AuditRepository())->page()['rows'][0];
			$this->assertSame('unknown',$history['action']);
			$this->assertSame([],$history['export']);
			$this->assertStringNotContainsString('private-secret',json_encode($history));
		}
		$this->expectException(InvalidArgumentException::class);
		new AuditEvent('settings_changed','changed',$uuid,[],$good);
	}
}
