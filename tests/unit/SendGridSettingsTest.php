<?php
// Explicit fourth-argument marker writer uses the mocked test add_option backend, not native wpdb.


use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Admin\Components\SendGridSettingsForm;

final class SendGridSettingsTest extends TestCase {

	public function test_automatic_protection_copy_explains_backup_and_recovery(): void {
		$html = $this->render();
		$this->assertStringContainsString('protected automatically using a private local key file', $html);
		$this->assertStringContainsString('secure and writable', $html);
		$this->assertStringContainsString('Back up WordPress files and database together', $html);
		$this->assertStringContainsString('Restores require the matching key', $html);
		$this->assertStringContainsString('advanced, optional', $html);
		$this->assertStringContainsString('never stored as plaintext', $html);
		$this->assertStringNotContainsString('Before saving a key, configure', $html);
	}

	public function test_first_local_replacement_is_ready_on_get_and_initializes_only_on_save(): void {
		$root = sys_get_temp_dir().'/scalyn-provider-form-'.bin2hex(random_bytes(8));
		mkdir($root, 0700);
		$store = new \Scalyn\MailRelay\Core\LocalCredentialKeyStore($root, static fn() => false, static fn() => get_option(\Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher = new CredentialCipher(null, $store);
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		$GLOBALS['_test_blog_id'] = 1;
		try {
			$before = $GLOBALS['_test_wp_options'];
			$this->assertFalse($cipher->is_available());
			$this->assertTrue($cipher->can_initialize());
			$html = $this->render($cipher);
			$this->assertSame([], array_values(array_diff(scandir($root), ['.', '..'])));
			$this->assertSame($before, $GLOBALS['_test_wp_options']);
			$this->assertStringNotContainsString('Server encryption is unavailable', $html);
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST = $this->input() + ['scalyn_sendgrid_settings' => '1'];
			$html = $this->render($cipher);
			$this->assertStringContainsString('configuration saved', $html);
			$envelope = $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['sendgrid']['key_cipher'];
			$this->assertStringStartsWith('v2:', $envelope);
			$this->assertSame(self::SECRET, $cipher->decrypt($envelope, 'sendgrid'));
			$this->assertStringNotContainsString(self::SECRET, $html);
		} finally {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($iterator as $entry) {
				if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
				else { unlink($entry->getPathname()); }
			}
			rmdir($root);
		}
	}

	public function test_keep_uses_stored_envelope_even_when_new_write_key_is_invalid(): void {
		$root = sys_get_temp_dir().'/scalyn-provider-keep-'.bin2hex(random_bytes(8));
		mkdir($root, 0700);
		$store = new \Scalyn\MailRelay\Core\LocalCredentialKeyStore($root, static fn() => false, static fn() => get_option(\Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher = new CredentialCipher(null, $store);
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		$GLOBALS['_test_blog_id'] = 1;
		try {
			$cipher->initialize_for_write();
			$envelope = $cipher->encrypt(self::SECRET, 'sendgrid');
			$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = ['sendgrid'=>['from_email'=>'sender@example.com','from_name'=>'Sender','key_cipher'=>$envelope]];
			$read_cipher = new CredentialCipher('', $store);
			$this->assertFalse($read_cipher->is_available());
			$this->assertFalse($read_cipher->can_initialize());
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST = $this->input(['key_action'=>'keep','api_key'=>'','from_name'=>'Updated sender']) + ['scalyn_sendgrid_settings'=>'1'];
			$html = $this->render($read_cipher);
			$this->assertStringContainsString('configuration saved', $html);
			$this->assertSame($envelope, $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['sendgrid']['key_cipher']);
			$this->assertSame('Updated sender', (new SettingsRepository())->get_sendgrid_settings()['from_name']);
			$this->assertStringNotContainsString(self::SECRET, $html);
		} finally {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($iterator as $entry) {
				if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
				else { unlink($entry->getPathname()); }
			}
			rmdir($root);
		}
	}

	public function test_unavailable_storage_feedback_offers_safe_recovery_not_mandatory_setup(): void {
		$html = $this->render(new CredentialCipher(''));
		$this->assertStringContainsString('restore the matching key', $html);
		$this->assertStringContainsString('private file permissions', $html);
		$this->assertStringNotContainsString('and PHP OpenSSL are required', $html);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $this->input() + ['scalyn_sendgrid_settings'=>'1'];
		$html = $this->render(new CredentialCipher(''));
		$this->assertStringContainsString('No changes saved: server encryption is unavailable. Restore the matching key', $html);
		$_POST = $this->input(['key_action'=>'keep','api_key'=>'']) + ['scalyn_sendgrid_settings'=>'1'];
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = ['sendgrid'=>['key_cipher'=>'v1:invalid']];
		$html = $this->render(new CredentialCipher(''));
		$this->assertStringContainsString('Restore the matching key or check private file permissions', $html);
		$this->assertStringNotContainsString('valid server encryption key', $html);
		$this->assertStringNotContainsString(self::SECRET, $html);
		$this->assertStringNotContainsString('v1:invalid', $html);
	}

	#[DataProvider('rejected_local_posts')]
	public function test_rejected_posts_never_initialize_local_storage(string $reason): void {
		$root = sys_get_temp_dir().'/scalyn-provider-rejected-'.bin2hex(random_bytes(8));
		mkdir($root, 0700);
		$store = new \Scalyn\MailRelay\Core\LocalCredentialKeyStore($root, static fn() => false, static fn() => get_option(\Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher = new CredentialCipher(null, $store);
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		$GLOBALS['_test_blog_id'] = 1;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $this->input() + ['scalyn_sendgrid_settings'=>'1'];
		if ('nonce' === $reason) { $GLOBALS['_test_wp_nonce_valid'] = false; }
		if ('capability' === $reason) { unset($GLOBALS['_test_current_user_can'][Capabilities::MANAGE_MAIL]); }
		if ('managed' === $reason) { $GLOBALS['_test_wp_options'][\Scalyn\MailRelay\Agency\AgencyRepository::OPTION] = ['managed'=>true,'providers'=>true]; }
		if ('stale' === $reason) { $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = ['configurations'=>['active'=>'synthetic-config'],'diagnostic_revision'=>'current']; $_POST['configuration_revision'] = 'stale'; }
		if ('sender' === $reason) { $_POST['from_email'] = 'invalid'; }
		if ('credential' === $reason) { $_POST['api_key'] = 'short'; }
		if ('keep' === $reason) { $_POST['key_action'] = 'keep'; $_POST['api_key'] = ''; }
		if ('remove' === $reason) { $_POST['key_action'] = 'remove'; $_POST['api_key'] = ''; }
		$before = $GLOBALS['_test_wp_options'];
		try {
			try { $html = $this->render($cipher); }
			catch (RuntimeException $error) { $html = $error->getMessage(); }
			$this->assertStringNotContainsString('configuration saved', $html);
			$this->assertStringNotContainsString(self::SECRET, $html);
			if ('keep' === $reason) { $this->assertStringContainsString('there is no saved', $html); }
			$this->assertSame($before, $GLOBALS['_test_wp_options']);
			$this->assertSame([], array_values(array_diff(scandir($root), ['.', '..'])));
		} finally { rmdir($root); }
	}

	public static function rejected_local_posts(): array {
		return [['nonce'], ['capability'], ['managed'], ['stale'], ['sender'], ['credential'], ['keep'], ['remove']];
	}

	public function test_form_removes_saved_key_without_encryption(): void {
		(new SettingsRepository())->save_sendgrid($this->input(), $this->cipher);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $this->input(['key_action'=>'remove','api_key'=>'','confirm_remove'=>'1']) + ['scalyn_sendgrid_settings'=>'1'];
		$html = $this->render(new CredentialCipher(''));
		$this->assertStringContainsString('configuration saved', $html);
		$this->assertFalse((new SettingsRepository())->get_sendgrid_settings()['has_key']);
		$this->assertStringNotContainsString(self::SECRET, $html);
	}

	private CredentialCipher $cipher;
	private const SECRET = 'SG.synthetic_test_credential_0123456789';

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
		try { (new SendGridSettingsForm(new SettingsRepository(),$cipher??$this->cipher))->render(); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}

	private function post(array $overrides=[]): string {
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=$this->input($overrides)+['scalyn_sendgrid_settings'=>'1'];
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
		$settings->save(['provider'=>['active'=>'sendgrid']]);
		$_SERVER['REQUEST_METHOD']='POST';
		$_POST=$this->input()+['scalyn_sendgrid_settings'=>'1'];
		ob_start();
		try { (new SendGridSettingsForm($settings,$this->cipher))->render(true); $html=ob_get_contents(); }
		finally { ob_end_clean(); }
		$this->assertTrue($settings->get_sendgrid_settings()['has_key']);
		$this->assertStringContainsString('wizard&step=3', $html);
		$this->assertStringContainsString('Continue to verification', $html);
		$this->assertStringContainsString('wizard&step=4', $html);
		$this->assertStringContainsString('SendGrid configuration saved', $html);
		$this->assertStringContainsString('name="_wpnonce"', $html);
		$this->assertStringNotContainsString(self::SECRET, $html);
		$this->assertStringNotContainsString('Continue in Setup Wizard', $html);
		$this->assertStringContainsString('autocomplete="new-password" value=""', $html);
	}

	public function test_first_save_defaults_to_replace_and_reports_missing_encryption(): void {
		$html = $this->render(new CredentialCipher(''));
		$this->assertMatchesRegularExpression('/value="replace"[^>]*selected/', $html);
		$this->assertStringContainsString('Server encryption is unavailable', $html);
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=$this->input()+['scalyn_sendgrid_settings'=>'1'];
		$html = $this->render(new CredentialCipher(''));
		$this->assertStringContainsString('No changes saved: server encryption is unavailable', $html);
		$this->assertStringNotContainsString(self::SECRET, $html);
		$this->assertFalse((new SettingsRepository())->get_sendgrid_settings()['has_key']);
	}

	public function test_save_can_run_before_status_projection_and_only_once(): void {
		$_SERVER['REQUEST_METHOD']='POST'; $_POST=$this->input()+['scalyn_sendgrid_settings'=>'1'];
		$settings = new SettingsRepository();
		$form = new SendGridSettingsForm($settings,$this->cipher);
		$form->handle();
		$this->assertTrue($settings->get_sendgrid_settings()['has_key']);
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
		try {(new SettingsRepository())->save_sendgrid($this->input(),new CredentialCipher('')); $this->fail('Must fail');}
		catch(RuntimeException $e){$this->assertStringNotContainsString(self::SECRET,$e->getMessage());}
		$this->assertArrayNotHasKey(SettingsRepository::OPTION_KEY,$GLOBALS['_test_wp_options']);
	}

	public function test_save_keep_replace_remove_and_audit_are_private(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['smtp'=>['password'=>'smtp-existing'],'provider'=>['active'=>'smtp','verified'=>true,'verified_at'=>'existing','test_email_accepted_at'=>'existing']];
		$events=[]; $GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function($event)use(&$events){$events[]=$event;};
		$this->assertStringContainsString('configuration saved',$this->post());
		$old=$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY];
		$this->assertSame(self::SECRET,$this->cipher->decrypt($old['sendgrid']['key_cipher']));
		$this->assertStringNotContainsString(self::SECRET,json_encode($old));
		$this->assertContains('sendgrid.api_key',$events[0]->changed_fields);
		$this->assertStringNotContainsString(self::SECRET,json_encode($events));
		$this->assertStringNotContainsString('sender@example.com',json_encode($events));
		$this->assertStringContainsString('configuration saved',$this->post(['key_action'=>'keep','api_key'=>'']));
		$this->assertSame($old,$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]);
		$this->post(['api_key'=>'SG.replacement_credential_123456789']);
		$this->assertNotSame($old['sendgrid']['key_cipher'],$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['sendgrid']['key_cipher']);
		$this->post(['key_action'=>'remove','api_key'=>'','confirm_remove'=>'1']);
		$this->assertFalse((new SettingsRepository())->get_sendgrid_settings()['has_key']);
		$this->assertSame($old['smtp'],$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['smtp']);
		$this->assertSame($old['provider'],$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['provider']);
	}

	public function test_removal_works_without_encryption_key(): void {
		$settings=new SettingsRepository(); $settings->save_sendgrid($this->input(),$this->cipher);
		$this->assertTrue($settings->save_sendgrid($this->input(['key_action'=>'remove','api_key'=>'','confirm_remove'=>true]),new CredentialCipher('')));
		$this->assertFalse($settings->get_sendgrid_settings()['has_key']);
	}

	#[DataProvider('invalid_inputs')]
	public function test_invalid_input_leaves_settings_unchanged(array $changes): void {
		$settings=new SettingsRepository(); $settings->save_sendgrid($this->input(),$this->cipher);
		$before=$GLOBALS['_test_wp_options'];
		try {$settings->save_sendgrid($this->input($changes),$this->cipher); $this->fail('Must reject');}
		catch(InvalidArgumentException $e){$this->assertStringNotContainsString(self::SECRET,$e->getMessage());}
		$this->assertSame($before,$GLOBALS['_test_wp_options']);
	}
	public static function invalid_inputs(): array {return [
		[['from_email'=>[]]],[['from_email'=>'not-email']],[['from_email'=>"a@example.com\r\nBcc:x@example.com"]],
		[['from_name'=>[]]],[['from_name'=>"bad\nname"]],[['from_name'=>str_repeat('a',201)]],
		[['api_key'=>[]]],[['api_key'=>'short']],[['api_key'=>str_repeat('a',513)]],[['api_key'=>"secret with spaces"]],
		[['key_action'=>'keep']],[['key_action'=>'unknown']],[['key_action'=>'remove','api_key'=>'']],
	];}

	public function test_no_credentials_or_ciphertext_in_markup_or_public_projection(): void {
		$this->post(); $_SERVER['REQUEST_METHOD']='GET'; $_POST=[];
		$html=$this->render(); $ciphertext=$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['sendgrid']['key_cipher'];
		$this->assertStringNotContainsString(self::SECRET,$html);
		$this->assertStringNotContainsString($ciphertext,$html);
		$this->assertStringContainsString('value=""',$html);
		$this->assertSame(['from_email'=>'sender@example.com','from_name'=>'Sender','has_key'=>true],(new SettingsRepository())->get_sendgrid_settings());
		try {
			(new SettingsRepository())->get_provider_config('sendgrid');
			$this->fail('A missing server key must fail closed.');
		} catch (RuntimeException $error) {
			$this->assertStringNotContainsString(self::SECRET,$error->getMessage());
		}
		$this->assertSame(self::SECRET,(new SettingsRepository($this->cipher))->get_provider_config('sendgrid')['api_key']);
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
		$this->assertStringContainsString('could not be saved',$this->post(['api_key'=>'SG.new_credential_0123456789']));
		$this->assertSame($before,$GLOBALS['_test_wp_options']);
	}
	public function test_active_sendgrid_configuration_change_invalidates_verification(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['provider'=>['active'=>'sendgrid','verified'=>true,'verified_at'=>'old','test_email_accepted_at'=>'old']];
		$this->post(); $settings=new SettingsRepository();
		$this->assertFalse($settings->is_provider_verified());
		$this->assertFalse($settings->has_accepted_test_email());
	}
	public function test_audit_observer_failure_does_not_undo_save(): void {
		$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function(){throw new RuntimeException('fixture');};
		$this->assertStringContainsString('configuration saved',$this->post());
		$this->assertTrue((new SettingsRepository())->get_sendgrid_settings()['has_key']);
	}
}
