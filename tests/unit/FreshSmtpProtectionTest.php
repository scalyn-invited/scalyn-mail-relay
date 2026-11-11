<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;

/** Fresh SMTP edits must be protected before any named catalogue exists. */
final class FreshSmtpProtectionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_SETTINGS => true, 'manage_options' => true];
	}

	protected function tearDown(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_current_user_can'] = [];
	}

	public function test_fresh_save_encrypts_login_without_named_catalogue_with_external_key(): void {
		$cipher = new CredentialCipher(base64_encode(str_repeat('f',32)));
		$settings = new SettingsRepository($cipher);
		$this->assertTrue($settings->save(['provider'=>['active'=>'smtp'],'smtp'=>[
			'host'=>'smtp.example.invalid','from_email'=>'sender@example.invalid',
			'username'=>'fixture-username-not-real','password'=>'fixture-password-not-real',
		]]));
		$stored = get_option(SettingsRepository::OPTION_KEY);
		$this->assertArrayNotHasKey('configurations',$stored);
		$this->assertStringNotContainsString('fixture-username-not-real',wp_json_encode($stored));
		$this->assertStringNotContainsString('fixture-password-not-real',wp_json_encode($stored));
		$this->assertStringStartsWith('v1:',$stored['smtp']['credentials_cipher']);
		$this->assertSame('',$stored['smtp']['username']);
		$this->assertSame('',$stored['smtp']['password']);
		$fresh = new SettingsRepository($cipher);
		$this->assertSame('fixture-username-not-real',$fresh->get_smtp_config()['username']);
		$this->assertSame('fixture-password-not-real',$fresh->get_smtp_config()['password']);
		$this->assertArrayNotHasKey('username',$fresh->get_smtp_settings());
		$this->assertArrayNotHasKey('credentials_cipher',$fresh->get_smtp_settings());
	}
}
