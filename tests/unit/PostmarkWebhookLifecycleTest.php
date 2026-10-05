<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;

final class LifecycleKeyDb extends WpdbStub {
	public array $retired=[];
	public function query( string $query ): int|false {
		$this->queries[]=$query;
		if ( str_contains( $query, 'retired_at' ) ) { $this->retired[]=end( $this->prepare_calls )['args'][2] ?? null; }
		return 1;
	}
	public function get_var( string $query ): mixed {
		return str_contains( $query, 'SELECT retired_at' ) ? '2026-10-03 00:00:00' : null;
	}
}

final class PostmarkWebhookLifecycleTest extends TestCase {
	private const REV='11111111-1111-4111-8111-111111111111';
	private CredentialCipher $cipher;
	private LifecycleKeyDb $db;
	private array $events=[];

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_wp_option_write_failures']=[];
		$GLOBALS['_test_wp_actions']=[];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>true];
		$GLOBALS['_test_wp_nonce_valid']=true;
		$this->cipher=new CredentialCipher(base64_encode(str_repeat('a',32)));
		$this->db=new LifecycleKeyDb();
		$GLOBALS['wpdb']=$this->db;
		$this->events=[];
		$events=&$this->events;
		$GLOBALS['_test_wp_actions'][HookNames::AUDIT_EVENT]=static function($event) use (&$events) { $events[]=$event->outcome; };
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.6.0';
		$this->mail_settings('postmark',self::REV);
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_actions']=[];
		$GLOBALS['_test_current_user_can']=[];
		$GLOBALS['_test_wp_nonce_valid']=true;
	}
	private function mail_settings(string $active,string $revision): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['provider'=>['active'=>$active],'postmark'=>['key_cipher'=>$this->cipher->encrypt('synthetic-server-token-0123','postmark'),'from_email'=>'sender@example.com','from_name'=>''],'diagnostic_revision'=>$revision];
	}
	private function service(): PostmarkWebhookSettings {
		return new PostmarkWebhookSettings($this->cipher,new DeliveryKeyRepository($this->cipher),new SettingsRepository($this->cipher));
	}
	private function provider(?int $server): PostmarkProvider {
		return new class($server) extends PostmarkProvider {
			public array $seen=[];
			public function __construct(private ?int $server) {}
			public function live_server_id(array $config): ?int { $this->seen[]=$config; return $this->server; }
		};
	}
	private function saved_source(array $changes=[]): PostmarkWebhookSettings {
		$service=$this->service();
		$service->save(array_replace(['server_id'=>7,'stream'=>'outbound','allowed_ips'=>['192.0.2.1'],'credential_action'=>'replace','username'=>'synthetic_username','password'=>'synthetic_password_01234567890123456789'],$changes),'valid');
		return $service;
	}

	public function test_verification_binds_server_identity_to_current_revision_without_sending(): void {
		$service=$this->saved_source();
		$provider=$this->provider(7);
		$this->assertTrue($service->verify('valid',$provider));
		$this->assertSame('synthetic-server-token-0123',$provider->seen[0]['api_key']);
		$stored=get_option(PostmarkWebhookSettings::OPTION);
		$this->assertSame(self::REV,$stored['verified_revision']);
		$this->assertTrue($service->public_settings()['verified']);
		$this->assertFalse($service->public_settings()['enabled']);
		$this->assertSame(['saved_disabled','verified'],$this->events);
	}

	public function test_mismatched_server_inactive_provider_or_stream_fail_verification(): void {
		$service=$this->saved_source();
		$this->assertFalse($service->verify('valid',$this->provider(8)));
		$this->assertFalse($service->verify('valid',$this->provider(null)));
		$this->mail_settings('sendgrid',self::REV);
		$provider=$this->provider(7);
		$this->assertFalse($this->service()->verify('valid',$provider));
		$this->assertSame([],$provider->seen,'No server check for an inactive provider');
		$this->assertArrayNotHasKey('verified_revision',get_option(PostmarkWebhookSettings::OPTION));
		$this->assertSame('verification_failed',end($this->events));
		$GLOBALS['_test_wp_options'][PostmarkWebhookSettings::OPTION]=null;
		$this->mail_settings('postmark',self::REV);
		$service=$this->saved_source(['stream'=>'broadcast']);
		$this->assertFalse($service->verify('valid',$this->provider(7)));
	}

	public function test_enable_requires_acknowledgement_verification_and_storage(): void {
		$service=$this->saved_source();
		foreach ([[false,'Confirm the privacy notice before enabling collection.'],[true,'Verify the webhook source for the current Postmark configuration first.']] as [$ack,$message]) {
			try { $service->enable($ack,'valid'); $this->fail('Must not enable'); }
			catch (RuntimeException $error) { $this->assertSame($message,$error->getMessage()); }
		}
		$service->verify('valid',$this->provider(7));
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.5.0';
		try { $service->enable(true,'valid'); $this->fail('Must not enable'); }
		catch (RuntimeException $error) { $this->assertStringContainsString('storage is not ready',$error->getMessage()); }
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION)['enabled']);
		$this->assertSame([],$this->db->inserts,'No key provisioned before prerequisites');
		$this->assertNull($service->dispatch_source());
	}

	public function test_enable_provisions_key_and_exposes_only_secret_free_dispatch_source(): void {
		$service=$this->saved_source();
		$service->verify('valid',$this->provider(7));
		$this->assertTrue($service->enable(true,'valid'));
		$stored=get_option(PostmarkWebhookSettings::OPTION);
		$this->assertTrue($stored['enabled']);
		$this->assertCount(1,$this->db->inserts);
		$source=$service->dispatch_source();
		$this->assertSame(['id','key_version','configuration_id'],array_keys($source));
		$this->assertSame($stored['key_version'],$source['key_version']);
		$this->assertSame(self::REV,$source['configuration_id']);
		$this->assertSame('collecting',$service->collection_status());
		$this->assertTrue($service->public_settings()['collecting']);
		$this->assertStringNotContainsString('synthetic',json_encode($source));
		$this->assertTrue($service->enable(true,'valid'),'Idempotent');
		$this->assertCount(1,$this->db->inserts);
		$this->assertSame(['saved_disabled','verified','enabled'],$this->events);
		try { $service->save(['server_id'=>7,'stream'=>'outbound','allowed_ips'=>['192.0.2.1'],'credential_action'=>'keep'],'valid'); $this->fail('Enabled source cannot be edited'); }
		catch (RuntimeException $error) { $this->assertTrue(get_option(PostmarkWebhookSettings::OPTION)['enabled']); }
	}

	public function test_configuration_or_provider_change_pauses_collection_until_reverified(): void {
		$service=$this->saved_source();
		$service->verify('valid',$this->provider(7));
		$service->enable(true,'valid');
		$this->mail_settings('postmark','22222222-2222-4222-8222-222222222222');
		$changed=$this->service();
		$this->assertNull($changed->dispatch_source());
		$this->assertSame('paused',$changed->collection_status());
		$this->assertSame('reverify',$changed->public_settings()['pause_reason']);
		$this->assertTrue($changed->collection_enabled(),'Opt-in itself is preserved');
		$this->assertTrue($changed->verify('valid',$this->provider(7)));
		$this->assertSame('22222222-2222-4222-8222-222222222222',$changed->dispatch_source()['configuration_id']);
		$this->mail_settings('sendgrid','22222222-2222-4222-8222-222222222222');
		$this->assertSame('provider',$this->service()->public_settings()['pause_reason']);
		$this->assertNull($this->service()->dispatch_source());
	}

	public function test_disable_stops_collection_and_credential_rotation_keeps_verification_and_key(): void {
		$service=$this->saved_source();
		$service->verify('valid',$this->provider(7));
		$service->enable(true,'valid');
		$key=get_option(PostmarkWebhookSettings::OPTION)['key_version'];
		$id=get_option(PostmarkWebhookSettings::OPTION)['id'];
		$this->assertTrue($service->disable('valid'));
		$this->assertNull($service->dispatch_source());
		$this->assertFalse($service->is_enabled($id));
		$this->assertSame('off',$service->collection_status());
		$this->assertTrue($service->save(['server_id'=>7,'stream'=>'outbound','allowed_ips'=>['192.0.2.2'],'credential_action'=>'replace','username'=>'synthetic_username_2','password'=>'synthetic_password_98765432109876543210'],'valid'));
		$stored=get_option(PostmarkWebhookSettings::OPTION);
		$this->assertSame([$id,$key,self::REV],[$stored['id'],$stored['key_version'],$stored['verified_revision']]);
		$this->assertTrue($service->enable(true,'valid'));
		$this->assertCount(1,$this->db->inserts,'Re-enable reuses the selected key version');
		$this->assertSame('disabled',$this->events[3]);
	}

	public function test_remove_requires_disabled_source_and_retires_its_key(): void {
		$service=$this->saved_source();
		$service->verify('valid',$this->provider(7));
		$service->enable(true,'valid');
		$key=get_option(PostmarkWebhookSettings::OPTION)['key_version'];
		try { $service->remove(true,'valid'); $this->fail('Enabled source cannot be removed'); }
		catch (RuntimeException $error) { $this->assertSame([],$this->db->retired); }
		$service->disable('valid');
		$this->assertTrue($service->remove(true,'valid'));
		$this->assertSame([$key],$this->db->retired);
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION,false));
		$this->assertFalse($service->has_source());
	}

	public function test_ingress_source_matches_route_identity_only(): void {
		$service=$this->saved_source();
		$id=get_option(PostmarkWebhookSettings::OPTION)['id'];
		$this->assertNull($service->ingress_source('99999999-9999-4999-8999-999999999999'));
		$source=$service->ingress_source(strtoupper($id));
		$this->assertSame('synthetic_username',$source['username']);
		$this->assertSame([7,'outbound',false],[$source['server_id'],$source['stream'],$source['enabled']]);
	}

	public function test_lifecycle_actions_require_capability_and_nonce(): void {
		$service=$this->saved_source();
		foreach (['capability','nonce'] as $mode) {
			$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>'capability'!==$mode];
			$GLOBALS['_test_wp_nonce_valid']='nonce'!==$mode;
			foreach ([fn()=>$service->verify('valid',$this->provider(7)),fn()=>$service->enable(true,'valid'),fn()=>$service->disable('valid')] as $action) {
				try { $action(); $this->fail('Must deny'); }
				catch (RuntimeException $error) { $this->assertSame('Webhook configuration is not authorized.',$error->getMessage()); }
			}
		}
		$this->assertArrayNotHasKey('verified_revision',get_option(PostmarkWebhookSettings::OPTION));
		$this->assertFalse(get_option(PostmarkWebhookSettings::OPTION)['enabled']);
	}
}
