<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\ConnectionVerification;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Database\ConnectionEvidenceRepository;
use Scalyn\MailRelay\Database\Migrator;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;

require_once dirname(__DIR__) . '/fixtures/delivery-schema-wpdb.php';

final class ConnectionEvidenceTest extends TestCase {
	private const REV='11111111-1111-4111-8111-111111111111';
	private const TIME='2026-10-05 00:00:00.000000';
	protected function setUp(): void {
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.9.0',SettingsRepository::OPTION_KEY=>['provider'=>['active'=>'smtp'],'diagnostic_revision'=>self::REV]];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		$GLOBALS['_test_wp_added_actions']=[];
		$GLOBALS['_test_dbdelta_queries']=[];
		$GLOBALS['wpdb']=new WpdbStub();
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_current_user_can']=[];
		unset($GLOBALS['wpdb']);
	}
	private function service(?SettingsRepository $settings=null,?ProviderRegistry $registry=null): ConnectionVerification {
		return new ConnectionVerification($settings ?? new SettingsRepository(),$registry ?? new ProviderRegistry(),new ConnectionEvidenceRepository());
	}
	public function test_read_is_exact_scope_and_never_uses_legacy_verification(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['provider']['verified']=true;
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['provider']['verified_at']='2026-10-05T00:00:00Z';
		$this->assertSame('unknown',$this->service()->current()['status']);
		$call=end($GLOBALS['wpdb']->prepare_calls);
		$this->assertSame(['wp_scalyn_connection_evidence',self::REV,'smtp'],$call['args']);
		$this->assertStringContainsString('configuration_id=%s AND provider=%s LIMIT 1',$call['query']);
	}
	public function test_freshness_boundaries_unknown_and_retention(): void {
		$GLOBALS['wpdb']->get_row_return=['status'=>'passed','started_at'=>self::TIME,'checked_at'=>self::TIME,'private'=>'must not escape'];
		$service=$this->service();
		$this->assertSame('passed',$service->current(new DateTimeImmutable('2026-10-12 00:00:00 UTC'))['status']);
		$this->assertSame('stale',$service->current(new DateTimeImmutable('2026-10-12 00:00:00.000001 UTC'))['status']);
		$this->assertSame('unknown',$service->current(new DateTimeImmutable('2026-10-04 00:00:00 UTC'))['status']);
		$this->assertSame('unknown',$service->current(new DateTimeImmutable('2026-11-05 00:00:00 UTC'))['status']);
		$GLOBALS['wpdb']->get_row_return['status']='unknown';
		$this->assertSame(['status'=>'unknown','checked_at'=>self::TIME],$service->current(new DateTimeImmutable('2026-10-06 UTC')));
		$GLOBALS['wpdb']->get_row_return['status']='failed';
		$this->assertSame('stale',$service->current(new DateTimeImmutable('2026-10-13 UTC'))['status']);
	}
	public function test_permissions_missing_schema_and_invalid_evidence_fail_unknown_without_query(): void {
		$GLOBALS['_test_current_user_can']=[];
		$this->assertSame('unknown',$this->service()->current()['status']);
		$this->assertFalse($this->service()->verify()->success);
		$this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.8.0';
		$this->assertSame('unknown',$this->service()->current()['status']);
		$this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.9.0';
		$GLOBALS['wpdb']->get_row_return=['status'=>'passed','started_at'=>'broken','checked_at'=>self::TIME];
		$this->assertSame('unknown',$this->service()->current()['status']);
	}
	public function test_scope_change_queries_new_revision_not_old_success(): void {
		$settings=new SettingsRepository();
		$settings->save(['smtp'=>['host'=>'changed.example.com']]);
		$this->assertSame('unknown',$this->service($settings)->current()['status']);
		$call=end($GLOBALS['wpdb']->prepare_calls);
		$this->assertSame($settings->get_diagnostic_revision(),$call['args'][1]);
		$this->assertNotSame(self::REV,$call['args'][1]);
	}
	public function test_verification_records_snapshot_minimal_result_and_utc_without_metadata(): void {
		foreach(['pass','fail','throw'] as $mode) {
			$GLOBALS['wpdb']=new WpdbStub();
			$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['provider'=>['active'=>'smtp'],'diagnostic_revision'=>self::REV];
			$settings=new SettingsRepository();
			$registry=new ProviderRegistry();
			$registry->register(new class($mode,$settings) implements ProviderInterface {
				public function __construct(private string $mode,private SettingsRepository $settings) {}
				public function get_id(): string { return 'smtp'; }
				public function get_label(): string { return 'Synthetic'; }
				public function get_capabilities(): array { return []; }
				public function validate_config(array $config): ValidationResult { return new ValidationResult(true); }
				public function send(MailMessage $message,array $config): SendResult { throw new LogicException('Must never send'); }
				public function test_connection(array $config): ConnectionResult {
					$this->settings->save(['smtp'=>['host'=>'changed.example.com']]);
					if ($this->mode==='throw') { throw new RuntimeException('private exception'); }
					return new ConnectionResult($this->mode==='pass','private message',['private'=>'secret']);
				}
			});
			$result=$this->service($settings,$registry)->verify();
			$this->assertSame($mode==='pass',$result->success);
			$call=end($GLOBALS['wpdb']->prepare_calls);
			$this->assertSame(self::REV,$call['args'][1]);
			$this->assertSame(match($mode) {'pass'=>'passed','fail'=>'failed','throw'=>'unknown'},$call['args'][3]);
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6}$/',$call['args'][4]);
			$this->assertStringNotContainsString('private',json_encode($call));
			$this->assertStringNotContainsString('secret',json_encode($call));
			$this->assertStringContainsString('VALUES(started_at)>started_at',$call['query']);
		}
	}
	public function test_invalid_scope_and_dates_are_rejected_before_sql(): void {
		$repo=new ConnectionEvidenceRepository();
		foreach([['bad','smtp','passed',self::TIME,self::TIME],[self::REV,'smtp','Healthy',self::TIME,self::TIME],[self::REV,'smtp','passed','2026-02-30 00:00:00.000000',self::TIME],[self::REV,'smtp','failed',self::TIME,'2026-10-04 00:00:00.000000']] as $args) {
			try { $repo->record(...$args); $this->fail('Reject invalid evidence'); } catch(InvalidArgumentException $e) { $this->assertSame('Invalid connection evidence.',$e->getMessage()); }
		}
		$this->assertSame([],$GLOBALS['wpdb']->queries);
	}
	public function test_retention_is_bounded_and_schema_guarded(): void {
		$repo=new ConnectionEvidenceRepository();
		$repo->delete_expired(self::TIME);
		$call=end($GLOBALS['wpdb']->prepare_calls);
		$this->assertSame(['wp_scalyn_connection_evidence',self::TIME],$call['args']);
		$this->assertStringContainsString('ORDER BY checked_at LIMIT 100',$call['query']);
	}
	public function test_new_migration_is_idempotent_and_does_not_backfill(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.8.0';
		$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();
		Migrator::migrate();
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
		$this->assertCount(1,$GLOBALS['_test_dbdelta_queries']);
		$this->assertStringNotContainsString('INSERT',$GLOBALS['_test_dbdelta_queries'][0]);
		Migrator::migrate();
		$this->assertCount(1,$GLOBALS['_test_dbdelta_queries']);
	}
	public function test_missing_schema_never_advances_version(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.8.0';
		$GLOBALS['wpdb']=new class extends DeliverySchemaWpdbStub { public function get_col(string $query): array { return []; } };
		try { Migrator::migrate(); $this->fail('Expected verification failure'); } catch(RuntimeException $e) { $this->assertSame('Connection evidence migration failed.',$e->getMessage()); }
		$this->assertSame('0.8.0',get_option('scalyn_mail_relay_db_version'));
	}
}
