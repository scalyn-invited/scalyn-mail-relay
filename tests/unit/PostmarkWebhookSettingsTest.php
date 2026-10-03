<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;

final class PostmarkWebhookSettingsTest extends TestCase {
	private CredentialCipher $cipher;
	private PostmarkWebhookSettings $settings;
	protected function setUp(): void {
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_wp_option_write_failures']=[];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>true];
		$GLOBALS['_test_wp_nonce_valid']=true;
		$this->cipher=new CredentialCipher(base64_encode(str_repeat('a',32)));
		$this->settings=new PostmarkWebhookSettings($this->cipher);
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_option_write_failures']=[];
		$GLOBALS['_test_current_user_can']=[];
		$GLOBALS['_test_wp_nonce_valid']=true;
	}
	private function input(array $changes=[]): array {
		return array_replace(['server_id'=>1,'stream'=>'outbound','allowed_ips'=>['192.0.2.1'],'credential_action'=>'replace','username'=>'synthetic_username','password'=>'synthetic_password_01234567890123456789'],$changes);
	}
	public function test_disabled_encrypted_draft_does_not_modify_sending_settings(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_settings']=['provider'=>['active'=>'smtp']];
		$this->assertTrue($this->settings->save($this->input(),'valid'));
		$row=get_option(PostmarkWebhookSettings::OPTION);
		$this->assertFalse($row['enabled']);
		$this->assertStringNotContainsString('synthetic',json_encode($row));
		$this->assertSame(['configured'=>true,'enabled'=>false,'verified'=>false,'server_id'=>1,'stream'=>'outbound','allowed_ips'=>['192.0.2.1']],$this->settings->public_settings());
		$this->assertSame(['provider'=>['active'=>'smtp']],get_option('scalyn_mail_relay_settings'));
		$secret=json_decode($this->cipher->decrypt($row['credentials'],'postmark-webhook'),true);
		$this->assertSame($this->input()['password'],$secret['password']);
		$this->expectException(InvalidArgumentException::class);
		// Unsupported contexts still fail; existing provider contexts cannot read this envelope.
		$this->cipher->decrypt($row['credentials'],'unsupported');
	}
	public function test_api_and_webhook_encryption_contexts_are_separate(): void {
		$this->settings->save($this->input(),'valid');
		foreach (['postmark','sendgrid'] as $context) {
			try { $this->cipher->decrypt(get_option(PostmarkWebhookSettings::OPTION)['credentials'],$context); $this->fail('Must reject cross-context ciphertext'); }
			catch (RuntimeException $error) { $this->assertStringNotContainsString('synthetic',$error->getMessage()); }
		}
	}
	public function test_keep_preserves_identity_and_unreadable_credentials_fail_closed(): void {
		$this->settings->save($this->input(),'valid');
		$old=get_option(PostmarkWebhookSettings::OPTION);
		$keep=$this->input(['credential_action'=>'keep','username'=>'','password'=>'']);
		$this->assertTrue($this->settings->save($keep,'valid'));
		$this->assertSame($old,get_option(PostmarkWebhookSettings::OPTION));
		$rotated=new PostmarkWebhookSettings(new CredentialCipher(base64_encode(str_repeat('b',32))));
		$this->expectException(RuntimeException::class);
		try { $rotated->save($keep,'valid'); } finally { $this->assertSame($old,get_option(PostmarkWebhookSettings::OPTION)); }
	}
	public function test_permissions_and_nonce_protect_mutation(): void {
		foreach (['capability','nonce'] as $mode) {
			$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>'capability'!==$mode];
			$GLOBALS['_test_wp_nonce_valid']='nonce'!==$mode;
			try { $this->settings->save($this->input(),'invalid'); $this->fail('Must deny'); }
			catch (RuntimeException $error) { $this->assertSame('Webhook configuration is not authorized.',$error->getMessage()); }
			$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
		}
	}
	public function test_enabled_or_invalid_source_input_is_not_saved(): void {
		foreach ([['enabled'=>true],['server_id'=>'1'],['allowed_ips'=>[]],['allowed_ips'=>['192.0.2.0/24']],['password'=>'short']] as $changes) {
			try { $this->settings->save($this->input($changes),'valid'); $this->fail('Must reject'); }
			catch (RuntimeException $error) { $this->assertStringNotContainsString('synthetic',$error->getMessage()); }
			$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
		}
	}
	public function test_source_change_requires_explicit_removal_and_cleans_budget(): void {
		$this->settings->save($this->input(),'valid');
		$old=get_option(PostmarkWebhookSettings::OPTION);
		try { $this->settings->save($this->input(['server_id'=>2]),'valid'); $this->fail('Must preserve source until removed'); }
		catch (RuntimeException $error) { $this->assertSame($old,get_option(PostmarkWebhookSettings::OPTION)); }
		$budget='scalyn_webhook_budget_'.$old['id']; update_option($budget,'synthetic counter');
		$this->assertTrue($this->settings->remove(true,'valid'));
		$this->assertFalse(get_option($budget,false));
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
		$this->assertTrue($this->settings->save($this->input(['server_id'=>2]),'valid'));
		$this->assertNotSame($old['id'],get_option(PostmarkWebhookSettings::OPTION)['id']);
	}
}
