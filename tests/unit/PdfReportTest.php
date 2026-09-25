<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Reporting\PdfReportRenderer;
use Scalyn\MailRelay\Reporting\ReportExporter;
use Scalyn\MailRelay\Reporting\ReportSnapshot;
use Scalyn\MailRelay\Admin\ReportExportController;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;

final class PdfReportTest extends TestCase {
	public function test_safe_html_contains_all_sections_and_does_not_interpret_source_markup():void {
		$html=(new PdfReportRenderer())->html(['version'=>1,'report_uuid'=>'capture','generated_at_utc'=>'2026-09-25T00:00:00Z','period'=>['start'=>'2026-09-01 00:00:00'],'mail'=>['provider'=>null,'total'=>0],'health'=>['latest_score'=>null],'diagnostics'=>['findings'=>[['check_name'=>'<img src="https://attacker.invalid">','status'=>'unknown']]],'recommendations'=>['refresh'=>true],'freshness'=>['cadence'=>'daily'],'limitations'=>['Never delivered']]);
		foreach(['Capture details','Reporting period','Privacy','Mail activity','Health (site-wide)','Diagnostic findings (site-wide)','Recommendations','Evidence freshness','Limitations','All providers','Unknown / unavailable (null)','Never delivered'] as $text){$this->assertStringContainsString($text,$html);}
		$this->assertStringNotContainsString('<img',$html);
		$this->assertStringContainsString('&lt;img',$html);
		$this->assertStringContainsString('>0</td>',$html);
	}
	public function test_actual_pdf_has_page_tree_and_no_javascript():void {
		$bytes=(new ReportExporter())->encode(new ReportSnapshot(['version'=>1,'mail'=>['provider'=>'smtp','totals'=>['failed'=>0]],'health'=>['latest_score'=>null],'limitations'=>['Accepted is not delivered']]),'pdf');
		$this->assertStringStartsWith('%PDF-',$bytes);
		$this->assertStringContainsString('/Type /Pages',$bytes);
		$this->assertStringContainsString('%%EOF',$bytes);
		$this->assertStringNotContainsString('/JavaScript',$bytes);
	}
	public function test_pdf_controller_uses_same_authentication_and_fixed_mime():void {
		$GLOBALS['wpdb']=new WpdbStub();$GLOBALS['wpdb']->get_var_return='3';
		$GLOBALS['_test_current_user_can']=[Capabilities::EXPORT_REPORTS=>true];
		(new ReflectionProperty(Plugin::class,'instance'))->setValue(null,null);
		Plugin::instance()->boot();
		$input=['_wpnonce'=>'valid-export-nonce','start'=>'2026-09-01 00:00:00','end'=>'2026-09-02 00:00:00','format'=>'pdf','provider_scope'=>'all','provider'=>''];
		try{
			$result=(new ReportExportController())->prepare($input,'POST');
			$this->assertSame('application/pdf',$result['mime']);
			$this->assertSame('scalyn-mail-relay-report.pdf',$result['name']);
			$this->assertStringStartsWith('%PDF-',$result['body']);
			$GLOBALS['_test_current_user_can']=[];
			$this->expectExceptionCode(403);
			(new ReportExportController())->prepare($input,'POST');
		}finally{(new ReflectionProperty(Plugin::class,'instance'))->setValue(null,null);unset($GLOBALS['wpdb']);$GLOBALS['_test_current_user_can']=[];}
	}
}
