<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Admin\Components\DkimSettingsForm;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;
use Scalyn\MailRelay\Diagnostics\Checks\DkimCheck;

final class DkimSettingsFormTest extends TestCase {
	protected function setUp():void {
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		$GLOBALS['_test_wp_nonce_valid']=true;
		$GLOBALS['_test_wp_option_write_failures']=[];
		$GLOBALS['_test_wp_added_actions']=[];
		$GLOBALS['_test_wp_actions']=[];
		$_SERVER['REQUEST_METHOD']='GET'; $_POST=[];
	}
	protected function tearDown():void {
		$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
		$GLOBALS['_test_wp_option_write_failures']=[];
	}
	private function render():string {
		ob_start();
		try {(new DkimSettingsForm(new SettingsRepository()))->render();return ob_get_contents();}
		finally {ob_end_clean();}
	}
	private function post(mixed $value):string {
		$_SERVER['REQUEST_METHOD']='POST';$_POST=['scalyn_dkim_settings'=>'1','dkim_selector'=>$value];
		return $this->render();
	}
	public function test_save_and_clear_preserve_other_settings_and_audit_only_field_name():void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['smtp'=>['password'=>'keep-secret'],'advanced'=>['log_retention_days'=>60]];
		$events=[];$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function($event)use(&$events){$events[]=$event;};
		$this->assertStringContainsString('DKIM selector saved',$this->post(' selector1 '));
		$this->assertSame('selector1',(new SettingsRepository())->get_dkim_selector());
		$this->assertSame(['advanced.dkim_selector'],$events[0]->changed_fields);
		$this->assertStringNotContainsString('selector1',json_encode($events[0]->metadata()));
		$this->assertStringNotContainsString('keep-secret',$this->render());
		$this->assertSame(60,(new SettingsRepository())->get_log_retention_days());
		$this->post('');$this->assertSame('',(new SettingsRepository())->get_dkim_selector());
	}
	#[DataProvider('invalid_values')]
	public function test_invalid_input_preserves_saved_value(mixed $value):void {
		(new SettingsRepository())->save(['advanced'=>['dkim_selector'=>'existing']]);
		$this->assertStringContainsString('No settings were changed',$this->post($value));
		$this->assertSame('existing',(new SettingsRepository())->get_dkim_selector());
	}
	public static function invalid_values():array {return [[[]],[null],['-selector'],['selector-'],['<b>default</b>'],['selector._domainkey.example.com'],[str_repeat('a',64)],['v=DKIM1; p=key'],["bad\nvalue"],['https://example.com']];}
	public function test_reader_cannot_edit_or_forge_post():void {
		$GLOBALS['_test_current_user_can']=[];
		$this->assertStringNotContainsString('<form',$this->render());
		$this->expectException(RuntimeException::class);$this->post('selector1');
	}
	public function test_nonce_required():void {
		$GLOBALS['_test_wp_nonce_valid']=false;
		$this->expectException(RuntimeException::class);$this->post('selector1');
	}
	public function test_failed_write_does_not_claim_saved():void {
		$GLOBALS['_test_wp_option_write_failures']=[SettingsRepository::OPTION_KEY=>true];
		$this->assertStringContainsString('could not be saved',$this->post('selector1'));
		$this->assertSame('',(new SettingsRepository())->get_dkim_selector());
	}
	public function test_saved_selector_flows_to_actual_check_without_credentials():void {
		$settings=new SettingsRepository();
		$settings->save(['smtp'=>['from_email'=>'sender@example.com','password'=>'keep-secret'],'advanced'=>['dkim_selector'=>'selector1']]);
		$context=(new DiagnosticContextBuilder())->build(new SettingsRepository(),'fallback.test');
		$lookups=[];
		$check=new DkimCheck(static function($host)use(&$lookups){$lookups[]=$host;return [['txt'=>'v=DKIM1; p=synthetic-public-key']];});
		$check->run($context);
		$this->assertSame(['selector1._domainkey.example.com'],$lookups);
		$this->assertStringNotContainsString('keep-secret',json_encode($context->settings));
		$settings->save(['advanced'=>['dkim_selector'=>'']]);
		$context=(new DiagnosticContextBuilder())->build(new SettingsRepository(),'fallback.test');
		$this->assertSame('unknown',$check->run($context)->status);
		$this->assertCount(1,$lookups);
	}
	public function test_malformed_stored_selector_is_never_rendered_or_forwarded():void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['advanced'=>['dkim_selector'=>'<script>alert(1)</script>']];
		$this->assertStringNotContainsString('<script>',$this->render());
		$context=(new DiagnosticContextBuilder())->build(new SettingsRepository(),'example.com');
		$this->assertArrayNotHasKey('dkim_selector',$context->settings);
	}
}
