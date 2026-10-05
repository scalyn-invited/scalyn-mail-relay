<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\Migrator;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\MailStatus;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\MailEventSubscriber;
use Scalyn\MailRelay\Logging\TimelineRepository;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

require_once dirname(__DIR__) . '/fixtures/delivery-schema-wpdb.php';

final class MailConfigurationTest extends TestCase {
	private const REVISION = '11111111-1111-4111-8111-111111111111';
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = ['scalyn_mail_relay_db_version'=>'0.8.0'];
		$GLOBALS['_test_wp_added_actions'] = [];
		$GLOBALS['_test_wp_actions'] = [];
		$GLOBALS['_test_dbdelta_queries'] = [];
		$GLOBALS['wpdb'] = new WpdbStub();
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_added_actions'] = [];
		unset($GLOBALS['wpdb']);
	}
	private function message(): MailMessage {
		return new MailMessage('message-uuid','from@example.com',['to@example.com'],'Private subject','Private body',context:['configuration_id'=>'spoofed']);
	}
	public function test_migration_is_additive_nullable_verified_and_idempotent(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.7.0';
		$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();
		Migrator::migrate();
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
		$this->assertCount(2,$GLOBALS['_test_dbdelta_queries']);
		$sql=$GLOBALS['_test_dbdelta_queries'][0];
		$this->assertStringContainsString('configuration_id char(36) NULL',$sql);
		$this->assertStringContainsString('configuration_created (configuration_id,created_at,id)',$sql);
		$this->assertStringNotContainsString('UPDATE',$sql);
		$this->assertStringNotContainsString('DROP',$sql);
		Migrator::migrate();
		$this->assertCount(2,$GLOBALS['_test_dbdelta_queries']);
	}
	public function test_incomplete_migration_does_not_advance_version_and_can_retry(): void {
		foreach(['missingMailRevision','brokenMailIndex'] as $property) {
			$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.7.0';
			$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();
			$GLOBALS['wpdb']->$property=true;
			try { Migrator::migrate(); $this->fail('Must verify migration'); }
			catch(RuntimeException $e) { $this->assertSame('Mail configuration migration failed.',$e->getMessage()); }
			$this->assertSame('0.7.0',get_option('scalyn_mail_relay_db_version'));
			$GLOBALS['wpdb']->$property=false;
			Migrator::migrate();
			$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
		}
	}
	public function test_insert_uses_only_explicit_revision_and_updates_never_backfill(): void {
		$repo=new MailLogRepository();
		foreach([self::REVISION,null,'secret-invalid'] as $revision) {
			$repo->upsert($this->message(),new SendResult(true,'smtp'),MailStatus::ACCEPTED,$revision);
			$row=end($GLOBALS['wpdb']->inserts)['data'];
			$this->assertSame($revision===self::REVISION ? self::REVISION : null,$row['configuration_id']);
		}
		$GLOBALS['wpdb']->get_var_return=1;
		$repo->upsert($this->message(),new SendResult(true,'smtp'),MailStatus::ACCEPTED,self::REVISION);
		$this->assertArrayNotHasKey('configuration_id',end($GLOBALS['wpdb']->updates)['data']);
	}
	public function test_pre_migration_logging_omits_new_column(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.7.0';
		(new MailLogRepository())->upsert($this->message(),new SendResult(true,'smtp'),MailStatus::ACCEPTED,self::REVISION);
		$this->assertArrayNotHasKey('configuration_id',$GLOBALS['wpdb']->inserts[0]['data']);
	}
	public function test_dispatch_snapshot_survives_configuration_change_during_send_for_all_outcomes(): void {
		foreach(['accepted','failed','unconfirmed'] as $outcome) {
			$GLOBALS['wpdb']=new WpdbStub();
			$GLOBALS['_test_wp_added_actions']=[];
			$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['provider'=>['active'=>'smtp'],'diagnostic_revision'=>self::REVISION];
			$settings=new SettingsRepository();
			$provider=new class($settings,$outcome) implements ProviderInterface {
				public function __construct(private SettingsRepository $settings,private string $outcome) {}
				public function get_id(): string { return 'smtp'; }
				public function get_label(): string { return 'Synthetic'; }
				public function get_capabilities(): array { return []; }
				public function validate_config(array $config): ValidationResult { return new ValidationResult(true); }
				public function test_connection(array $config): ConnectionResult { return new ConnectionResult(true); }
				public function send(MailMessage $message,array $config): SendResult {
					$this->settings->save(['smtp'=>['host'=>'changed.example.com']]);
					return new SendResult($this->outcome==='accepted','smtp',acceptance_unconfirmed:$this->outcome==='unconfirmed');
				}
			};
			$registry=new ProviderRegistry();
			$registry->register($provider);
			(new MailEventSubscriber(new MailLogRepository(),new TimelineRepository()))->register();
			(new MailDispatcher($registry,$settings))->dispatch($this->message());
			$this->assertNotSame(self::REVISION,$settings->get_diagnostic_revision());
			$this->assertSame(self::REVISION,$GLOBALS['wpdb']->inserts[0]['data']['configuration_id']);
			$this->assertSame($outcome==='unconfirmed' ? 'prepared' : $outcome,$GLOBALS['wpdb']->inserts[0]['data']['status']);
		}
	}
	public function test_missing_revision_and_legacy_publishers_remain_unattributed(): void {
		(new MailEventSubscriber(new MailLogRepository(),new TimelineRepository()))->register();
		do_action(HookNames::MAIL_FAILED,new SendResult(false,'smtp'),$this->message());
		$this->assertNull($GLOBALS['wpdb']->inserts[0]['data']['configuration_id']);
		$GLOBALS['wpdb']=new WpdbStub();
		(new MailDispatcher(new ProviderRegistry(),new SettingsRepository()))->dispatch($this->message());
		$this->assertNull($GLOBALS['wpdb']->inserts[0]['data']['configuration_id']);
	}
}
