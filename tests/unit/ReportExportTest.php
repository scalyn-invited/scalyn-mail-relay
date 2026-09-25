<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\ReportExportController;
use Scalyn\MailRelay\Admin\Pages\ReportsPage;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Reporting\ReportExporter;
use Scalyn\MailRelay\Reporting\ReportSnapshot;

final class ReportExportTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_current_user_can']=[Capabilities::EXPORT_REPORTS=>true];
		$GLOBALS['wpdb']=new WpdbStub();
		(new ReflectionProperty(Plugin::class,'instance'))->setValue(null,null);
	}
	protected function tearDown(): void {
		(new ReflectionProperty(Plugin::class,'instance'))->setValue(null,null);
		$GLOBALS['_test_current_user_can']=[];
		unset($GLOBALS['wpdb']);
	}
	private function input():array {
		return ['_wpnonce'=>'valid-export-nonce','start'=>'2026-09-01 00:00:00','end'=>'2026-09-02 00:00:00','format'=>'json','provider_scope'=>'all','provider'=>''];
	}
	/** @dataProvider rejected */
	public function test_invalid_requests_do_not_read_evidence(array $override,string $method,int $code):void {
		try { (new ReportExportController())->prepare(array_replace($this->input(),$override),$method); $this->fail(); }
		catch(RuntimeException $e) {$this->assertSame($code,$e->getCode());}
		$this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
	}
	public static function rejected():array {
		return [
			[[],'GET',403], [['_wpnonce'=>'wrong'],'POST',403], [['_wpnonce'=>[]],'POST',403],
			[['format'=>'html'],'POST',400], [['format'=>[]],'POST',400], [['references'=>'0'],'POST',400], [['references'=>[]],'POST',400],
			[['provider_scope'=>'bad'],'POST',400], [['provider_scope'=>'specific','provider'=>'smtp!'],'POST',400],
			[['start'=>'2026-02-30 00:00:00'],'POST',400], [['end'=>'2028-01-01 00:00:00'],'POST',400], [['end'=>null],'POST',400],
		];
	}
	public function test_wrong_capability_cannot_download_or_render():void {
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		try {(new ReportExportController())->prepare($this->input(),'POST');$this->fail();}
		catch(RuntimeException $e){$this->assertSame(403,$e->getCode());}
		$this->expectException(RuntimeException::class);
		(new ReportsPage())->render();
	}
	public function test_render_contains_privacy_default_and_nonce():void {
		ob_start();(new ReportsPage())->render();$html=ob_get_clean();
		$this->assertStringContainsString('scalyn_export_report',$html);
		$this->assertStringContainsString('name="_wpnonce"',$html);
		$this->assertStringContainsString('name="references" value="1"',$html);
		$this->assertStringNotContainsString('checked',$html);
		$this->assertStringContainsString('366 days',$html);
		$this->assertSame([],$GLOBALS['wpdb']->queries);
	}
	public function test_valid_empty_download_uses_snapshot_and_fixed_headers():void {
		Plugin::instance()->boot();
		$GLOBALS['wpdb']->get_var_return='3';
		$result=(new ReportExportController())->prepare($this->input(),'POST');
		$this->assertSame('scalyn-mail-relay-report.json',$result['name']);
		$this->assertSame('application/json; charset=UTF-8',$result['mime']);
		$data=json_decode($result['body'],true,512,JSON_THROW_ON_ERROR);
		$this->assertSame(0,$data['mail']['totals']['total']);
		$this->assertFalse($data['privacy']['evidence_references_included']);
		$this->assertNotEmpty($data['limitations']);
	}
	public function test_database_failure_never_becomes_empty_success():void {
		Plugin::instance()->boot();
		$this->expectExceptionMessage('Report export unavailable.');
		(new ReportExportController())->prepare($this->input(),'POST');
	}
	public function test_privacy_is_recursive_and_does_not_mutate_capture():void {
		$snapshot=new ReportSnapshot(['version'=>1,'report_uuid'=>'capture','mail'=>['recent_failures'=>[['id'=>8,'message_uuid'=>'message','provider'=>'smtp']]],'diagnostics'=>['findings'=>[['diagnostic_uuid'=>'run']]],'health'=>['latest_score'=>['score_uuid'=>'score','overall_score'=>null]],'recommendations'=>['items'=>[['run_uuid'=>'run','action'=>'Review']]]]);
		$exporter=new ReportExporter();
		$private=$exporter->encode($snapshot,'json');
		foreach(['message_uuid','diagnostic_uuid','score_uuid','run_uuid','"id"'] as $key){$this->assertStringNotContainsString($key,$private);}
		$this->assertStringContainsString('report_uuid',$private);
		$this->assertStringContainsString('message_uuid',$exporter->encode($snapshot,'json',true));
		$this->assertSame('message',$snapshot->data['mail']['recent_failures'][0]['message_uuid']);
	}
	/** @dataProvider dangerousCells */
	public function test_csv_neutralizes_formulas_and_round_trips_quoted_values(string $cell):void {
		$csv=(new ReportExporter())->encode(new ReportSnapshot(['value'=>$cell]),'csv');
		$lines=explode("\r\n",$csv);
		$row=str_getcsv($lines[1],',','"','');
		$this->assertSame("'".$cell,$row[2]);
	}
	public static function dangerousCells():array {
		return [['=1+1'],['+SUM(1,2)'],['-1+2'],['@SUM(1)'],['  ="x"'],["\t=1"],["\xEF\xBB\xBF=1"]];
	}
	public function test_csv_preserves_null_zero_empty_boolean_and_limitations():void {
		$csv=(new ReportExporter())->encode(new ReportSnapshot(['null'=>null,'zero'=>0,'empty'=>[],'flag'=>false,'limitations'=>['Not delivered'],'text'=>'Comma, "quote"']),'csv');
		$this->assertStringContainsString('"null","NULL","null"',$csv);
		$this->assertStringContainsString('"zero","integer","0"',$csv);
		$this->assertStringContainsString('"empty","array","[]"',$csv);
		$this->assertStringContainsString('"flag","boolean","false"',$csv);
		$this->assertStringContainsString('"limitations.0","string","Not delivered"',$csv);
		$this->assertStringContainsString('"Comma, ""quote"""',$csv);
	}
}
