<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\AlertsPage;
use Scalyn\MailRelay\Alerts\AlertRepository;
use Scalyn\MailRelay\Alerts\WebhookChannel;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;

final class AlertsPageTest extends TestCase {
	protected function setUp():void {
		$GLOBALS['wpdb']=new WpdbStub();
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_wp_cron']=[];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		$GLOBALS['_test_wp_nonce_valid']=true;
		$_SERVER['REQUEST_METHOD']='GET'; $_POST=[]; $_GET=[];
	}
	protected function tearDown():void {$_SERVER['REQUEST_METHOD']='GET'; $_POST=[]; $_GET=[];}
	private function render():string {
		ob_start();
		try { (new AlertsPage(new AlertRepository(),new WebhookChannel()))->render();return ob_get_contents(); }
		finally {ob_end_clean();}
	}
	public function test_no_capability_cannot_read_or_write():void {
		$GLOBALS['_test_current_user_can']=[];
		$this->expectException(RuntimeException::class);$this->render();
	}
	public function test_invalid_nonce_cannot_write():void {
		$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['_test_wp_nonce_valid']=false;
		$this->expectException(RuntimeException::class);$this->render();
	}
	public function test_configuration_required_to_enable():void {
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=['alert_webhook_enabled'=>'1'];
		$this->assertStringContainsString('Configure a valid HTTPS webhook',$this->render());
		$this->assertFalse((new SettingsRepository())->get_alert_webhook_enabled());
	}
	public function test_safe_empty_state_and_limitations():void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['smtp'=>['password'=>'secret-value']];
		$html=$this->render();
		$this->assertStringContainsString('No incidents recorded',$html);
		$this->assertStringContainsString('external uptime monitor',$html);
		$this->assertStringNotContainsString('secret-value',$html);
	}
	public function test_strict_setting_and_audit_field():void {
		$settings=new SettingsRepository();
		$this->assertTrue($settings->save(['advanced'=>['alert_webhook_enabled'=>true,'webhook_token'=>'secret']]));
		$this->assertTrue((new SettingsRepository())->get_alert_webhook_enabled());
		$this->assertStringNotContainsString('secret',json_encode($GLOBALS['_test_wp_options']));
		$this->assertContains('advanced.alert_webhook_enabled',\Scalyn\MailRelay\Audit\AuditEvent::FIELDS);
		$this->expectException(InvalidArgumentException::class);
		$settings->save(['advanced'=>['alert_webhook_enabled'=>'yes']]);
	}
}
