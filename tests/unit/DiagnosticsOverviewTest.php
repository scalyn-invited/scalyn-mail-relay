<?php

use PHPUnit\Framework\TestCase;

final class DiagnosticsOverviewTest extends TestCase {
	private function render(array $diagnostics):string {
		ob_start();
		require SCALYN_MAIL_RELAY_PATH.'admin/views/diagnostics-overview.php';
		return (string)ob_get_clean();
	}
	public function test_missing_evidence_is_not_healthy():void {
		$html=$this->render(array());
		$this->assertSame(3,substr_count($html,'Incomplete evidence'));
		$this->assertStringNotContainsString('scalyn-badge--healthy',$html);
		$this->assertStringContainsString('Recorded issues: 0 · Not assessed: 3',$html);
		$this->assertStringContainsString('System and deliverability assessments are not implemented here',$html);
	}
	public function test_failures_warnings_and_gaps_remain_distinct():void {
		$html=$this->render(array('spf'=>array('status'=>'fail'),'dkim'=>array('status'=>'warn'),'dmarc'=>array('status'=>'unknown'),'mx'=>array('status'=>'pass'),'smtp_tls'=>array('status'=>'error')));
		$this->assertSame(1,substr_count($html,'scalyn-badge--critical'));
		$this->assertSame(1,substr_count($html,'scalyn-badge--healthy'));
		$this->assertSame(1,substr_count($html,'scalyn-badge--unknown'));
		$this->assertStringContainsString('Recorded issues: 2 · Not assessed: 1',$html);
		$this->assertStringContainsString('not live health',$html);
	}
}
