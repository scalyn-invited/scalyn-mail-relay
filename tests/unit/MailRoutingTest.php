<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Mail\MailOrigin;
use Scalyn\MailRelay\Mail\RoutingRepository;

final class MailRoutingTest extends TestCase {
	private SettingsRepository $settings;
	private RoutingRepository $routes;
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_SETTINGS => true,'manage_options'=>true];
		$GLOBALS['_test_wp_added_actions'] = [];
		$this->settings = new SettingsRepository(new CredentialCipher(base64_encode(str_repeat('r',32))));
		$this->settings->save(['provider'=>['active'=>'smtp'],'smtp'=>['host'=>'smtp.example.test','username'=>'private-login','password'=>'private-secret','from_email'=>'sender@example.test']]);
		$this->settings->configure('import');
		$this->routes = new RoutingRepository();
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_current_user_can'] = [];
		$GLOBALS['_test_wp_added_actions'] = [];
	}
	public function test_source_attribution_uses_call_sites_not_arbitrary_headers(): void {
		$this->assertSame('unknown/default',MailOrigin::current([]));
		$this->assertSame('contact-form-7/contact_form',MailOrigin::current([['class'=>'WPCF7_Mail','function'=>'compose']]));
		$this->assertSame('wordpress/password_reset',MailOrigin::current([['function'=>'retrieve_password']]));
		$this->assertSame('unknown/default',MailOrigin::current([['function'=>'mail','args'=>['X-WPCF7-Content-Type: text/html']]]));
	}
	public function test_scopes_restore_after_nested_exception(): void {
		MailOrigin::during('wordpress/password_reset',function(){
			try { MailOrigin::during('contact-form-7/contact_form',function(){
				$this->assertSame('contact-form-7/contact_form',MailOrigin::current());
				throw new RuntimeException('synthetic');
			}); } catch (RuntimeException $e) {}
			$this->assertSame('wordpress/password_reset',MailOrigin::current());
		});
		$this->assertSame('unknown/default',MailOrigin::current([]));
	}
	public function test_disabled_policy_preserves_default(): void {
		$this->assertSame([],$this->routes->plan($this->settings,'unknown/default'));
	}
	public function test_default_route_is_pinned_and_rename_preserves_target(): void {
		$id=$this->settings->get_active_configuration_id();
		$this->routes->save(true,['unknown/default'=>[$id]],'',$this->settings);
		$plan=$this->routes->plan($this->settings,'unknown-integration/something');
		$this->assertSame($this->settings->get_diagnostic_revision(),$plan[0]['revision']);
		$this->settings->configure('rename',$id,'Production');
		$this->assertSame('private-secret',$this->settings->resolve_target($plan[0])['password']);
		$this->assertStringNotContainsString('private-secret',wp_json_encode($plan));
	}
	public function test_edit_stops_pinned_target(): void {
		$target=$this->settings->configuration_target($this->settings->get_active_configuration_id());
		$this->settings->save(['smtp'=>['host'=>'changed.example.test','from_email'=>'sender@example.test']]);
		$this->expectException(RuntimeException::class);
		$this->settings->resolve_target($target);
	}
	public function test_repeated_identity_rejected_before_policy_mutation(): void {
		$id=$this->settings->get_active_configuration_id();
		try { $this->routes->save(true,['unknown/default'=>[$id,$id]],'',$this->settings); $this->fail(); } catch (RuntimeException $e) {}
		$this->assertFalse($this->routes->policy()['enabled']);
	}
	public function test_stale_policy_rejected(): void {
		$this->routes->save(true,[],'',$this->settings);
		$this->expectException(RuntimeException::class);
		$this->routes->save(false,[],'',$this->settings);
	}
	public function test_denied_policy_change_has_no_effect(): void {
		$GLOBALS['_test_current_user_can'] = [];
		$this->expectException(RuntimeException::class);
		$this->routes->save(true,[],'',$this->settings);
	}
	public function test_unconfigured_alternate_rejected(): void {
		$this->settings->configure('create','postmark','Unconfigured');
		$items=$this->settings->get_configurations();
		$this->expectException(RuntimeException::class);
		$this->routes->save(true,['unknown/default'=>[$items[1]['id']]],'',$this->settings);
	}
}
