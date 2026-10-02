<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Admin\Components\PostmarkSettingsForm;

final class PostmarkSettingsTest extends TestCase {
	public function test_provider_bound_ciphertext_cannot_be_swapped(): void {
		$value = $this->cipher->encrypt(self::SECRET, 'postmark');
		$this->assertSame(self::SECRET, $this->cipher->decrypt($value, 'postmark'));
		$this->expectException(RuntimeException::class);
		$this->cipher->decrypt($value, 'sendgrid');
	}


	private CredentialCipher $cipher;
	private const SECRET = 'PM.synthetic_test_credential_0123456789';

	protected function setUp(): void {
		$this->cipher = new CredentialCipher(base64_encode(str_repeat('a',32)));
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_wp_actions'] = [];
		$GLOBALS['_test_wp_added_actions'] = [];
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_MAIL=>true];
		$GLOBALS['_test_wp_nonce_valid'] = true;
		$_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
	}

	protected function tearDown(): void {
		$_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
		$GLOBALS['_test_wp_option_write_failures']=[];
		$GLOBALS['_test_wp_actions']=[];
	}

	private function input(array $overrides=[]): array {
		return array_replace(['from_email'=>'sender@example.com','from_name'=>'Sender','key_action'=>'replace','api_key'=>self::SECRET],$overrides);
	}

	private function render(?CredentialCipher $cipher=null): string {
		ob_start();
		try { (new PostmarkSettingsForm(new SettingsRepository(),$cipher??$this->cipher))->render(); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}

	private function post(array $overrides=[]): string {
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=$this->input($overrides)+['scalyn_postmark_settings'=>'1'];
		return $this->render();
	}

	public function test_randomized_authenticated_encryption_round_trip(): void {
		$a=$this->cipher->encrypt(self::SECRET); $b=$this->cipher->encrypt(self::SECRET);
		$this->assertNotSame($a,$b);
		$this->assertStringNotContainsString(self::SECRET,$a);
		$this->assertSame(self::SECRET,$this->cipher->decrypt($a));
		$this->assertSame(self::SECRET,$this->cipher->decrypt($b));
	}

	public function test_wizard_form_saves_in_place_without_exposing_credentials(): void {
		$settings = new SettingsRepository();
		$settings->save(['provider'=>['active'=>'postmark']]);
		$_SERVER['REQUEST_METHOD']='POST';
		$_POST=$this->input()+['scalyn_postmark_settings'=>'1'];
		ob_start();
		try { (new PostmarkSettingsForm($settings,$this->cipher))->render(true); $html=ob_get_contents(); }
		finally { ob_end_clean(); }
		$this->assertTrue($settings->get_postmark_settings()['has_key']);
		$this->assertStringContainsString('wizard&step=3', $html);
		$this->assertStringContainsString('Continue to verification', $html);
		$this->assertStringContainsString('wizard&step=4', $html);
		$this->assertStringContainsString('Postmark configuration saved', $html);
		$this->assertStringContainsString('name="_wpnonce"', $html);
		$this->assertStringNotContainsString(self::SECRET, $html);
		$this->assertStringNotContainsString('Continue in Setup Wizard', $html);
		$this->assertStringContainsString('autocomplete="new-password" value=""', $html);
	}

	public function test_first_save_defaults_to_replace_and_reports_missing_encryption(): void {
		$html = $this->render(new CredentialCipher(''));
		$this->assertMatchesRegularExpression('/value="replace"[^>]*selected/', $html);
		$this->assertStringContainsString('Server encryption is unavailable', $html);
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=$this->input()+['scalyn_postmark_settings'=>'1'];
		$html = $this->render(new CredentialCipher(''));
		$this->assertStringContainsString('No changes saved: server encryption is unavailable', $html);
		$this->assertStringNotContainsString(self::SECRET, $html);
		$this->assertFalse((new SettingsRepository())->get_postmark_settings()['has_key']);
	}

	public function test_save_can_run_before_status_projection_and_only_once(): void {
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=$this->input()+['scalyn_postmark_settings'=>'1'];
		$settings = new SettingsRepository();
		$form = new PostmarkSettingsForm($settings,$this->cipher);
		$form->handle();
		$this->assertTrue($settings->get_postmark_settings()['has_key']);
		$stored = $GLOBALS['_test_wp_options'];
		ob_start();
		try { $form->render(); $html=ob_get_contents(); } finally { ob_end_clean(); }
		$this->assertSame($stored,$GLOBALS['_test_wp_options']);
		$this->assertStringContainsString('notice-success',$html);
		$this->assertStringContainsString('Continue in Setup Wizard',$html);
		$this->assertStringNotContainsString('not available yet',$html);
	}

	public function test_modified_ciphertext_fails_closed(): void {
		$value=$this->cipher->encrypt(self::SECRET);
		$data=base64_decode(substr($value,3)); $data[30]=chr(ord($data[30])^1);
		$this->expectException(RuntimeException::class);
		$this->cipher->decrypt('v1:'.base64_encode($data));
	}

	public function test_changed_server_key_fails_closed(): void {
		$value=$this->cipher->encrypt(self::SECRET);
		$this->expectException(RuntimeException::class);
		(new CredentialCipher(base64_encode(str_repeat('b',32))))->decrypt($value);
	}

	#[DataProvider('invalid_envelopes')]
	public function test_malformed_envelopes_fail_closed(string $value): void {
		$this->expectException(RuntimeException::class); $this->cipher->decrypt($value);
	}
	public static function invalid_envelopes(): array {return [[''],['plaintext'],['v2:abc'],['v1:%%%'],['v1:'.base64_encode('short')],['v1:'.str_repeat('a',1025)]];}

	public function test_missing_server_key_never_saves_plaintext(): void {
		try {(new SettingsRepository())->save_postmark($this->input(),new CredentialCipher('')); $this->fail('Must fail');}
		catch(RuntimeException $e){$this->assertStringNotContainsString(self::SECRET,$e->getMessage());}
		$this->assertArrayNotHasKey(SettingsRepository::OPTION_KEY,$GLOBALS['_test_wp_options']);
	}

	public function test_save_keep_replace_remove_and_audit_are_private(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['smtp'=>['password'=>'smtp-existing'],'provider'=>['active'=>'smtp','verified'=>true,'verified_at'=>'existing','test_email_accepted_at'=>'existing']];
		$events=[]; $GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function($event)use(&$events){$events[]=$event;};
		$this->assertStringContainsString('configuration saved',$this->post());
		$old=$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY];
		$this->assertSame(self::SECRET,$this->cipher->decrypt($old['postmark']['key_cipher'], 'postmark'));
		$this->assertStringNotContainsString(self::SECRET,json_encode($old));
		$this->assertContains('postmark.api_key',$events[0]->changed_fields);
		$this->assertStringNotContainsString(self::SECRET,json_encode($events));
		$this->assertStringNotContainsString('sender@example.com',json_encode($events));
		$this->assertStringContainsString('configuration saved',$this->post(['key_action'=>'keep','api_key'=>'']));
		$this->assertSame($old,$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]);
		$this->post(['api_key'=>'PM.replacement_credential_123456789']);
		$this->assertNotSame($old['postmark']['key_cipher'],$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['postmark']['key_cipher']);
		$this->post(['key_action'=>'remove','api_key'=>'','confirm_remove'=>'1']);
		$this->assertFalse((new SettingsRepository())->get_postmark_settings()['has_key']);
		$this->assertSame($old['smtp'],$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['smtp']);
		$this->assertSame($old['provider'],$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['provider']);
	}

	public function test_removal_works_without_encryption_key(): void {
		$settings=new SettingsRepository(); $settings->save_postmark($this->input(),$this->cipher);
		$this->assertTrue($settings->save_postmark($this->input(['key_action'=>'remove','api_key'=>'','confirm_remove'=>true]),new CredentialCipher('')));
		$this->assertFalse($settings->get_postmark_settings()['has_key']);
	}

	#[DataProvider('invalid_inputs')]
	public function test_invalid_input_leaves_settings_unchanged(array $changes): void {
		$settings=new SettingsRepository(); $settings->save_postmark($this->input(),$this->cipher);
		$before=$GLOBALS['_test_wp_options'];
		try {$settings->save_postmark($this->input($changes),$this->cipher); $this->fail('Must reject');}
		catch(InvalidArgumentException $e){$this->assertStringNotContainsString(self::SECRET,$e->getMessage());}
		$this->assertSame($before,$GLOBALS['_test_wp_options']);
	}
	public static function invalid_inputs(): array {return [
		[['from_email'=>[]]],[['from_email'=>'not-email']],[['from_email'=>"a@example.com\r\nBcc:x@example.com"]],
		[['from_name'=>[]]],[['from_name'=>"bad\nname"]],[['from_name'=>str_repeat('a',201)]],
		[['api_key'=>[]]],[['api_key'=>'POSTMARK_API_TEST']],[['api_key'=>'short']],[['api_key'=>str_repeat('a',513)]],[['api_key'=>"secret with spaces"]],
		[['key_action'=>'keep']],[['key_action'=>'unknown']],[['key_action'=>'remove','api_key'=>'']],
	];}

	public function test_no_credentials_or_ciphertext_in_markup_or_public_projection(): void {
		$this->post(); $_SERVER['REQUEST_METHOD']='GET'; $_POST=[];
		$html=$this->render(); $ciphertext=$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['postmark']['key_cipher'];
		$this->assertStringNotContainsString(self::SECRET,$html);
		$this->assertStringNotContainsString($ciphertext,$html);
		$this->assertStringContainsString('value=""',$html);
		$this->assertSame(['from_email'=>'sender@example.com','from_name'=>'Sender','has_key'=>true],(new SettingsRepository())->get_postmark_settings());
		try {
			(new SettingsRepository())->get_provider_config('postmark');
			$this->fail('A missing server key must fail closed.');
		} catch (RuntimeException $error) {
			$this->assertStringNotContainsString(self::SECRET,$error->getMessage());
		}
		$this->assertSame(self::SECRET,(new SettingsRepository($this->cipher))->get_provider_config('postmark')['api_key']);
	}

	public function test_capability_required_before_post(): void {
		$GLOBALS['_test_current_user_can']=[];
		$this->assertStringNotContainsString('<form',$this->render());
		$this->expectException(RuntimeException::class); $this->post();
	}
	public function test_nonce_required(): void {
		$GLOBALS['_test_wp_nonce_valid']=false;
		$this->expectException(RuntimeException::class); $this->post();
	}
	public function test_failed_write_is_reported_and_preserves_state(): void {
		$this->post(); $before=$GLOBALS['_test_wp_options'];
		$GLOBALS['_test_wp_option_write_failures']=[SettingsRepository::OPTION_KEY=>true];
		$this->assertStringContainsString('could not be saved',$this->post(['api_key'=>'PM.new_credential_0123456789']));
		$this->assertSame($before,$GLOBALS['_test_wp_options']);
	}
	public function test_active_postmark_configuration_change_invalidates_verification(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['provider'=>['active'=>'postmark','verified'=>true,'verified_at'=>'old','test_email_accepted_at'=>'old']];
		$this->post(); $settings=new SettingsRepository();
		$this->assertFalse($settings->is_provider_verified());
		$this->assertFalse($settings->has_accepted_test_email());
	}
	public function test_audit_observer_failure_does_not_undo_save(): void {
		$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function(){throw new RuntimeException('fixture');};
		$this->assertStringContainsString('configuration saved',$this->post());
		$this->assertTrue((new SettingsRepository())->get_postmark_settings()['has_key']);
	}
}
