<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Components\PostmarkWebhookForm;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;

final class PostmarkWebhookFormTest extends TestCase {
	private PostmarkWebhookSettings $settings;
	protected function setUp(): void {
		foreach (['_test_wp_options','_test_wp_actions','_test_wp_added_actions','_test_wp_option_write_failures'] as $key) { $GLOBALS[$key]=[]; }
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>true];
		$GLOBALS['_test_wp_nonce_valid']=true;
		$_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
		$this->settings=new PostmarkWebhookSettings(new CredentialCipher(base64_encode(str_repeat('a',32))));
	}
	protected function tearDown(): void {
		$_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
		$GLOBALS['_test_wp_actions']=[];
		$GLOBALS['_test_wp_added_actions']=[];
		$GLOBALS['_test_current_user_can']=[];
		$GLOBALS['_test_wp_nonce_valid']=true;
	}
	private function post(array $changes=[]): void {
		$_SERVER['REQUEST_METHOD']='POST';
		$_POST=array_replace(['scalyn_postmark_webhook_draft'=>'1','_wpnonce'=>'valid','wh_action'=>'replace','wh_server_id'=>'1','wh_stream'=>'outbound','wh_ips'=>'192.0.2.1','wh_username'=>'synthetic_username','wh_password'=>'synthetic_password_01234567890123456789'],$changes);
	}
	private function render(): string {
		ob_start();
		try { (new PostmarkWebhookForm($this->settings))->render(); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	public function test_save_is_disabled_has_safe_feedback_and_private_audit(): void {
		$events=[];
		$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function($event) use (&$events) { $events[]=$event; };
		$this->post(); $html=$this->render();
		$this->assertStringContainsString('Webhook source saved.',$html);
		$this->assertStringContainsString('Collection remains disabled',$html);
		$this->assertStringContainsString('name="wh_password" type="password"',$html);
		$this->assertStringNotContainsString('synthetic',$html);
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION)['enabled']);
		$this->assertCount(1,$events);
		$this->assertSame('saved_disabled',$events[0]->outcome);
		$this->assertStringNotContainsString('synthetic',serialize($events));
		$this->assertStringNotContainsString('192.0.2.1',serialize($events));
		$this->post(['wh_action'=>'remove','wh_confirm_remove'=>'1']);
		$this->assertStringContainsString('Webhook source removed.',$this->render());
		$this->assertSame('removed',$events[1]->outcome);
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
	}
	public function test_bad_input_never_echoes_payload_and_cannot_save(): void {
		$this->post(['wh_stream'=>'<script>private</script>']);
		$html=$this->render();
		$this->assertStringContainsString('No source changes saved.',$html);
		$this->assertStringNotContainsString('<script>',$html);
		$this->assertStringNotContainsString('synthetic',$html);
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
	}
	public function test_get_has_no_side_effects_and_no_secret_values(): void {
		$html=$this->render();
		$this->assertStringContainsString('Off — no source saved',$html);
		$this->assertStringContainsString('Skip this section',$html);
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
	}
	public function test_no_capability_hides_form_and_denies_post(): void {
		$GLOBALS['_test_current_user_can']=[];
		$this->assertSame('',$this->render());
		$this->post();
		$this->expectException(RuntimeException::class);
		try { $this->render(); } finally { $this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false)); }
	}
	public function test_bad_nonce_cannot_save(): void {
		$this->post(['_wpnonce'=>'invalid']);
		$html=$this->render();
		$this->assertStringContainsString('No source changes saved.',$html);
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
	}
	public function test_audit_failure_cannot_reverse_save(): void {
		$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function() { throw new RuntimeException('private'); };
		$this->post();
		$this->assertStringContainsString('Webhook source saved.',$this->render());
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION)['enabled']);
	}
}
