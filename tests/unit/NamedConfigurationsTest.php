<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\NamedConfigurations;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Admin\ConfigurationGuard;

final class NamedConfigurationsTest extends TestCase {
	private const REVISION = '11111111-1111-4111-8111-111111111111';
	private CredentialCipher $cipher;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_SETTINGS => true, 'manage_options' => true];
		$GLOBALS['_test_wp_added_actions'] = [];
		$_POST = [];
		$this->cipher = new CredentialCipher(base64_encode(str_repeat('n',32)));
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_current_user_can'] = [];
		$GLOBALS['_test_wp_added_actions'] = [];
		$_POST = [];
	}
	private function legacy(): SettingsRepository {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = [
			'provider'=>['active'=>'smtp','verified'=>true,'verified_at'=>'2026-10-12T00:00:00Z'],
			'diagnostic_revision'=>self::REVISION,
			'smtp'=>['host'=>'smtp.example.test','username'=>'private_login','password'=>'private_password','from_email'=>'sender@example.test'],
			'advanced'=>['dkim_selector'=>'selector'],
		];
		return new SettingsRepository($this->cipher);
	}
	private function second(SettingsRepository $settings, string $provider='smtp'): string {
		$settings->configure('create',$provider,'Secondary');
		$configurations=$settings->get_configurations();
		return $configurations[array_key_last($configurations)]['id'];
	}
	public function test_import_preserves_active_revision_and_encrypts_smtp_without_changing_transport(): void {
		$settings=$this->legacy();
		$transport=$settings->get_smtp_config();
		$this->assertTrue($settings->configure('import'));
		$this->assertSame(self::REVISION,$settings->get_diagnostic_revision());
		$this->assertSame($transport,$settings->get_smtp_config());
		$this->assertTrue($settings->is_provider_verified());
		$this->assertCount(1,$settings->get_configurations());
		$stored=wp_json_encode(get_option(SettingsRepository::OPTION_KEY));
		$this->assertStringNotContainsString('private_password',$stored);
		$this->assertStringNotContainsString('private_login',$stored);
		$this->assertSame('smtp.example.test',$settings->get_smtp_settings()['host']);
		$this->assertArrayNotHasKey('credentials_cipher',$settings->get_smtp_settings());
		$this->assertArrayNotHasKey('username',$settings->get_smtp_settings());
		$before=get_option(SettingsRepository::OPTION_KEY);
		$this->assertTrue($settings->configure('import'));
		$this->assertSame($before,get_option(SettingsRepository::OPTION_KEY));
	}
	public function test_missing_key_import_preserves_legacy_sending_without_copying_plaintext(): void {
		$this->legacy();
		$settings=new SettingsRepository(new CredentialCipher('invalid'));
		$settings->configure('import');
		$this->assertSame('private_password',$settings->get_smtp_config()['password']);
		$catalog=get_option(SettingsRepository::OPTION_KEY)['configurations'];
		$this->assertStringNotContainsString('private_password',wp_json_encode($catalog));
		$this->assertTrue($catalog['revisions'][self::REVISION]['legacy']);
		$this->expectException(RuntimeException::class);
		$settings->configure('create','smtp','New');
	}
	public function test_same_provider_configurations_are_isolated_and_create_does_not_select(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$first=$settings->get_active_configuration_id();
		$second=$this->second($settings);
		$this->assertSame($first,$settings->get_active_configuration_id());
		$settings->configure('select',$second);
		$this->assertSame('smtp',$settings->get_active_provider_id());
		$this->assertSame('',$settings->get_smtp_config()['host']);
		$this->assertSame('',$settings->get_smtp_config()['password']);
		$this->assertFalse($settings->is_provider_verified());
		$settings->save(['smtp'=>['host'=>'second.example.test','from_email'=>'other@example.test','username'=>'other_login','password'=>'other_password']]);
		$revision=$settings->get_diagnostic_revision();
		$settings->configure('select',$first);
		$this->assertSame(self::REVISION,$settings->get_diagnostic_revision());
		$this->assertSame('private_password',$settings->get_smtp_config()['password']);
		$settings->configure('select',$second);
		$this->assertSame($revision,$settings->get_diagnostic_revision());
		$this->assertSame('other_password',$settings->get_smtp_config()['password']);
		$this->assertFalse($settings->is_provider_verified());
	}
	public function test_rename_does_not_rotate_revision_or_touch_verification(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$before=get_option(SettingsRepository::OPTION_KEY)['configurations']['revisions'];
		$settings->configure('rename',$settings->get_active_configuration_id(),'Production');
		$this->assertSame(self::REVISION,$settings->get_diagnostic_revision());
		$this->assertTrue($settings->is_provider_verified());
		$this->assertSame($before,get_option(SettingsRepository::OPTION_KEY)['configurations']['revisions']);
		$this->assertSame('Production',$settings->get_configurations()[0]['name']);
	}
	public function test_edit_rotates_revision_preserves_immutable_snapshot_and_resets_progress(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$before=get_option(SettingsRepository::OPTION_KEY)['configurations']['revisions'][self::REVISION];
		$settings->save(['smtp'=>['host'=>'changed.example.test','from_email'=>'sender@example.test','password'=>'replacement']]);
		$this->assertNotSame(self::REVISION,$settings->get_diagnostic_revision());
		$this->assertFalse($settings->is_provider_verified());
		$this->assertSame('private_login',$settings->get_smtp_config()['username']);
		$this->assertSame('replacement',$settings->get_smtp_config()['password']);
		$this->assertSame($before,get_option(SettingsRepository::OPTION_KEY)['configurations']['revisions'][self::REVISION]);
		$this->assertStringNotContainsString('replacement',wp_json_encode(get_option(SettingsRepository::OPTION_KEY)));
	}
	public function test_noop_smtp_edit_keeps_revision_and_verification(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$config=$settings->get_smtp_config(); $config['password']=''; $config['username']='';
		$settings->save(['smtp'=>$config]);
		$this->assertSame(self::REVISION,$settings->get_diagnostic_revision());
		$this->assertTrue($settings->is_provider_verified());
	}
	public function test_selector_is_revision_scoped_and_restored_on_selection(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$first=$settings->get_active_configuration_id(); $second=$this->second($settings);
		$settings->configure('select',$second);
		$this->assertSame('',$settings->get_dkim_selector());
		$revision=$settings->get_diagnostic_revision();
		$settings->save(['advanced'=>['dkim_selector'=>'second']]);
		$this->assertNotSame($revision,$settings->get_diagnostic_revision());
		$settings->configure('select',$first);
		$this->assertSame('selector',$settings->get_dkim_selector());
	}
	public function test_archive_retains_identity_but_prevents_selection(): void {
		$settings=$this->legacy(); $settings->configure('import'); $second=$this->second($settings);
		$settings->configure('archive',$second);
		$this->assertCount(2,$settings->get_configurations());
		$this->assertTrue($settings->get_configurations()[1]['archived']);
		$this->expectException(InvalidArgumentException::class);
		$settings->configure('select',$second);
	}
	public function test_active_default_cannot_be_archived(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$this->expectException(InvalidArgumentException::class);
		$settings->configure('archive',$settings->get_active_configuration_id());
	}
	public function test_api_credential_edit_does_not_change_another_configuration(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$first=$this->second($settings,'postmark'); $settings->configure('select',$first);
		$input=['from_email'=>'sender@example.test','from_name'=>'Sender','key_action'=>'replace','api_key'=>'synthetic_token_0123456789'];
		$settings->save_postmark($input,$this->cipher); $revision=$settings->get_diagnostic_revision();
		$second=$this->second($settings,'postmark'); $settings->configure('select',$second);
		$this->assertFalse($settings->get_postmark_settings()['has_key']);
		$this->assertSame([],$settings->get_provider_config('postmark'));
		$input['api_key']='second_token_0123456789'; $settings->save_postmark($input,$this->cipher);
		$settings->configure('select',$first);
		$this->assertSame($revision,$settings->get_diagnostic_revision());
		$this->assertSame('synthetic_token_0123456789',$settings->get_provider_config('postmark')['api_key']);
		$this->assertStringNotContainsString('synthetic_token',wp_json_encode($settings->get_configurations()));
	}
	public function test_stale_writer_rejected_without_overwriting_new_state_and_lock_released(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$stale=new SettingsRepository($this->cipher);
		$settings->configure('rename',$settings->get_active_configuration_id(),'Newest');
		try { $stale->configure('rename',$stale->get_active_configuration_id(),'Stale'); $this->fail('Must reject stale writer'); }
		catch(RuntimeException $e) { $this->assertStringContainsString('another request',$e->getMessage()); }
		$this->assertSame('Newest',(new SettingsRepository($this->cipher))->get_configurations()[0]['name']);
		$this->assertFalse(get_option(SettingsRepository::OPTION_KEY.'_write_lock'));
	}
	public function test_failed_write_keeps_selected_identity_and_revisions(): void {
		$settings=$this->legacy(); $settings->configure('import'); $before=get_option(SettingsRepository::OPTION_KEY);
		$GLOBALS['_test_wp_option_write_failures'][SettingsRepository::OPTION_KEY]=true;
		$this->assertFalse($settings->configure('create','postmark','Failed'));
		$this->assertSame($before,get_option(SettingsRepository::OPTION_KEY));
		$this->assertCount(1,$settings->get_configurations());
		$this->assertFalse(get_option(SettingsRepository::OPTION_KEY.'_write_lock'));
	}
	public function test_capability_denial_does_not_import_or_modify_settings(): void {
		$settings=$this->legacy(); $before=get_option(SettingsRepository::OPTION_KEY);
		$GLOBALS['_test_current_user_can']=[];
		try { $settings->configure('import'); $this->fail('Must deny'); }
		catch(RuntimeException $e) { $this->assertSame($before,get_option(SettingsRepository::OPTION_KEY)); }
	}
	public function test_stale_form_is_rejected_and_current_revision_accepted(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$_POST['configuration_revision']=self::REVISION; ConfigurationGuard::check();
		$settings->save(['advanced'=>['dkim_selector'=>'new']]);
		$this->expectException(RuntimeException::class);
		ConfigurationGuard::check();
	}
	public function test_catalogue_read_is_credential_free_and_read_only(): void {
		$settings=$this->legacy(); $before=get_option(SettingsRepository::OPTION_KEY);
		$this->assertSame([],$settings->get_configurations());
		$this->assertSame($before,get_option(SettingsRepository::OPTION_KEY));
	}
	public function test_legacy_selector_edit_does_not_require_a_key_or_duplicate_plaintext(): void {
		$this->legacy(); $settings=new SettingsRepository(new CredentialCipher('invalid'));
		$settings->configure('import');
		$settings->save(['advanced'=>['dkim_selector'=>'updated']]);
		$this->assertNotSame(self::REVISION,$settings->get_diagnostic_revision());
		$this->assertSame('private_password',$settings->get_smtp_config()['password']);
		$this->assertStringNotContainsString('private_password',wp_json_encode(get_option(SettingsRepository::OPTION_KEY)['configurations']));
	}
	public function test_key_rotation_allows_complete_smtp_replacement_but_not_blank_keep(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$new_cipher=new CredentialCipher(base64_encode(str_repeat('r',32)));
		$settings=new SettingsRepository($new_cipher);
		try { $settings->save(['smtp'=>['host'=>'smtp.example.test','from_email'=>'sender@example.test']]); $this->fail('Unreadable keep accepted'); }
		catch(RuntimeException $error) { $this->assertSame(self::REVISION,$settings->get_diagnostic_revision()); }
		$this->assertTrue($settings->save(['smtp'=>['host'=>'smtp.example.test','from_email'=>'sender@example.test','username'=>'new_login','password'=>'new_password']]));
		$this->assertSame('new_password',$settings->get_smtp_config()['password']);
		$this->assertNotSame(self::REVISION,$settings->get_diagnostic_revision());
	}
	public function test_webhook_source_pauses_for_another_named_revision_but_retains_historical_ingress(): void {
		foreach (['postmark','smtp2go','brevo'] as $provider) {
			$GLOBALS['_test_wp_options']=[SettingsRepository::OPTION_KEY=>['provider'=>['active'=>$provider],'diagnostic_revision'=>self::REVISION], 'scalyn_mail_relay_db_version'=>'0.11.0'];
			$settings=new SettingsRepository($this->cipher); $settings->configure('import');
			$first=$settings->get_active_configuration_id();
			$source=wp_generate_uuid4();
			$option='scalyn_mail_relay_'.$provider.'_webhook';
			$GLOBALS['_test_wp_options'][$option]=[
				'id'=>$source,'enabled'=>true,'stream'=>'outbound','server_id'=>7,'key_version'=>wp_generate_uuid4(),
				'configuration_id'=>self::REVISION,'verified_revision'=>self::REVISION,
				'credentials'=>$this->cipher->encrypt(wp_json_encode(['username'=>'synthetic_username','password'=>'synthetic_webhook_password_0123456789']),$provider.'-webhook'),
				'allowed_ips'=>['192.0.2.1']
			];
			$service=$provider==='postmark' ? new \Scalyn\MailRelay\Core\PostmarkWebhookSettings($this->cipher,null,$settings) : new \Scalyn\MailRelay\Core\ApiWebhookSettings($this->cipher,$provider,null,$settings);
			$this->assertSame(self::REVISION,$service->dispatch_source()['configuration_id']);
			$second=$this->second($settings,$provider); $settings->configure('select',$second);
			$this->assertNull($service->dispatch_source());
			$this->assertTrue($service->ingress_source($source)['enabled'],'Historical callbacks keep their original source; new sends are not tracked under it.');
			$this->assertSame(self::REVISION,get_option($option)['configuration_id']);
			$settings->configure('select',$first);
			$this->assertSame(self::REVISION,$service->dispatch_source()['configuration_id']);
		}
	}
	public function test_uncredentialed_smtp_does_not_claim_a_saved_password(): void {
		$settings=$this->legacy(); $settings->configure('import'); $second=$this->second($settings); $settings->configure('select',$second);
		$settings->save(['smtp'=>['host'=>'relay.example.test','from_email'=>'sender@example.test']]);
		$this->assertFalse($settings->get_smtp_settings()['has_password']);
		$this->assertSame('',$settings->get_smtp_config()['password']);
	}
	public function test_invalid_labels_and_unknown_providers_are_rejected_without_partial_import(): void {
		foreach ([['smtp',''],['smtp','<b>unsafe</b>'],['smtp',str_repeat('x',81)],['unknown','Name']] as [$provider,$name]) {
			$settings=$this->legacy(); $before=get_option(SettingsRepository::OPTION_KEY);
			try { $settings->configure('create',$provider,$name); $this->fail('Invalid input accepted'); }
			catch(InvalidArgumentException $e) { $this->assertSame($before,get_option(SettingsRepository::OPTION_KEY)); }
		}
	}
	public function test_legacy_request_cannot_overwrite_a_concurrent_import(): void {
		$settings=$this->legacy(); $stale=new SettingsRepository($this->cipher);
		$settings->configure('import');
		$before=get_option(SettingsRepository::OPTION_KEY);
		try { $stale->save(['advanced'=>['log_retention_days'=>90]]); $this->fail('Must reject stale legacy writer'); }
		catch(RuntimeException $error) { $this->assertSame($before,get_option(SettingsRepository::OPTION_KEY)); }
	}
	public function test_late_wizard_progress_cannot_overwrite_a_concurrent_selection(): void {
		$settings=$this->legacy(); $settings->configure('import'); $second=$this->second($settings);
		$stale=new SettingsRepository($this->cipher);
		$settings->configure('select',$second);
		$this->assertFalse($stale->mark_test_email_accepted());
		$fresh=new SettingsRepository($this->cipher);
		$this->assertSame($second,$fresh->get_active_configuration_id());
		$this->assertFalse($fresh->has_accepted_test_email());
	}
	public function test_locked_configuration_management_denies_non_override_actor(): void {
		$settings=$this->legacy();
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_agency']=['managed'=>true,'providers'=>true,'smtp'=>true,'critical'=>true];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		$this->expectException(RuntimeException::class);
		$settings->configure('import');
	}
	public function test_populated_configuration_markup_contains_no_credentials_and_has_explicit_actions(): void {
		$settings=$this->legacy(); $settings->configure('import'); $this->second($settings,'postmark');
		ob_start();
		try { (new \Scalyn\MailRelay\Admin\Components\ConfigurationList($settings))->render(true); $html=ob_get_contents(); }
		finally { ob_end_clean(); }
		foreach (['Select default and configure','Configure in wizard','Rename','Archive','configuration_revision','Secondary'] as $text) $this->assertStringContainsString($text,$html);
		foreach (['private_password','private_login','credentials_cipher','v1:'] as $text) $this->assertStringNotContainsString($text,$html);
	}
	public function test_smtp_dns_context_does_not_require_decrypting_a_rotated_login(): void {
		$settings=$this->legacy(); $settings->configure('import');
		$rotated=new SettingsRepository(new CredentialCipher(base64_encode(str_repeat('z',32))));
		$context=(new \Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder())->build($rotated,'fallback.example.test');
		$this->assertSame('example.test',$context->domain);
		$this->assertStringNotContainsString('private_',wp_json_encode($context));
	}
	public function test_guard_rejects_a_new_token_when_the_action_holds_an_old_settings_snapshot(): void {
		$settings=$this->legacy(); $settings->configure('import'); $second=$this->second($settings);
		$old_snapshot=new SettingsRepository($this->cipher);
		$settings->configure('select',$second);
		$_POST['configuration_revision']=$settings->get_diagnostic_revision();
		$this->expectException(RuntimeException::class);
		ConfigurationGuard::check($old_snapshot);
	}
}
