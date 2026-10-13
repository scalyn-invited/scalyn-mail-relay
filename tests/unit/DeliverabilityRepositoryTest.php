<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DeliverabilityRepository;
use Scalyn\MailRelay\Database\Migrator;
use Scalyn\MailRelay\Diagnostics\DeliverabilityScorer;

require_once dirname(__DIR__).'/fixtures/delivery-schema-wpdb.php';

final class DeliverabilityRepositoryTest extends TestCase {
 private const REV='11111111-1111-4111-8111-111111111111';
 protected function setUp():void {$GLOBALS['wpdb']=new class extends WpdbStub {public string $last_error='';};$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.11.0'];$GLOBALS['_test_dbdelta_queries']=[];}
 protected function tearDown():void {unset($GLOBALS['wpdb']);$GLOBALS['_test_wp_options']=[];$GLOBALS['_test_dbdelta_queries']=[];}
 public function test_additive_schema_is_verified_idempotent_and_retryable():void {
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.10.0';$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();Migrator::migrate();
  $this->assertSame(SCALYN_MAIL_RELAY_DB_VERSION,get_option('scalyn_mail_relay_db_version'));$this->assertCount(3,$GLOBALS['_test_dbdelta_queries']);
  $sql=$GLOBALS['_test_dbdelta_queries'][0];foreach(['ENGINE=InnoDB','computation_uuid char(36)','configuration_kind (configuration_id,provider,kind,id)','created_at (created_at,id)'] as $part){$this->assertStringContainsString($part,$sql);}
  foreach(['recipient','subject','body','headers','UPDATE','DROP'] as $part){$this->assertStringNotContainsString($part,$sql);}
  Migrator::migrate();$this->assertCount(3,$GLOBALS['_test_dbdelta_queries']);
  foreach(['missingDeliverability','brokenDeliverabilityIndex'] as $bad){$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.10.0';$GLOBALS['wpdb']->$bad=true;try{Migrator::migrate();$this->fail('Must fail verification');}catch(RuntimeException $e){$this->assertSame('Deliverability schema could not be verified.',$e->getMessage());}$this->assertSame('0.10.0',get_option('scalyn_mail_relay_db_version'));$GLOBALS['wpdb']->$bad=false;Migrator::migrate();$this->assertSame(SCALYN_MAIL_RELAY_DB_VERSION,get_option('scalyn_mail_relay_db_version'));}
 }
 public function test_score_round_trip_replays_exact_inputs_and_rejects_tampering():void {
  $repo=new DeliverabilityRepository();$saved=$repo->persist_score(self::REV,'brevo',self::REV,['evaluated_at'=>time(),'raw_headers'=>'private@example.com']);
  $insert=$GLOBALS['wpdb']->inserts[0]['data'];$this->assertNull($saved['score']);$this->assertStringNotContainsString('private',$insert['payload']);$this->assertSame(self::REV,$insert['configuration_id']);
  $GLOBALS['wpdb']->get_row_return=$insert;$this->assertSame($saved,$repo->latest(self::REV,'brevo'));
  $GLOBALS['wpdb']->get_row_return['payload']=str_replace('"score":null','"score":100',$insert['payload']);$this->assertNull($repo->latest(self::REV,'brevo'));
  $GLOBALS['wpdb']->get_row_return=$insert;$GLOBALS['wpdb']->get_row_return['weights_version']='future';$this->assertNull($repo->latest(self::REV,'brevo'));
 }
 public function test_headers_store_enums_only_and_are_not_score_inputs():void {
  $repo=new DeliverabilityRepository();$repo->persist_header(self::REV,'postmark',['verdicts'=>['spf'=>'pass','dkim'=>'fail','dmarc'=>'secret'],'alignment'=>['spf'=>'strict','dkim'=>'secret'],'summary'=>['from_domain'=>'private.example'],'findings'=>[['text'=>'secret']],'raw'=>'secret']);
  $insert=$GLOBALS['wpdb']->inserts[0]['data'];$this->assertSame('header',$insert['kind']);$this->assertNull($insert['diagnostic_uuid']);$payload=json_decode($insert['payload'],true);
  $this->assertSame(['verdicts'=>['spf'=>'pass','dkim'=>'fail','dmarc'=>'unknown'],'alignment'=>['spf'=>'strict','dkim'=>'unknown']],$payload);$this->assertStringNotContainsString('secret',$insert['payload']);$this->assertStringNotContainsString('private',$insert['payload']);
 }
 public function test_history_query_is_revision_provider_window_scoped_with_future_exclusion():void {
  $previousTimezone=date_default_timezone_get();date_default_timezone_set('UTC');
  try {
  $GLOBALS['wpdb']->get_results_return=[['status'=>'accepted','row_count'=>'20'],['status'=>'failed','row_count'=>'1']];$repo=new DeliverabilityRepository();
  $this->assertSame(['accepted'=>20,'failed'=>1],$repo->submissions(self::REV,'smtp',30,1791763200));$call=end($GLOBALS['wpdb']->prepare_calls);
  foreach(['configuration_id=%s','provider=%s','created_at >= %s','created_at <= %s',"status IN ('accepted','failed')"] as $guard){$this->assertStringContainsString($guard,$call['query']);}
  $this->assertSame(['wp_scalyn_mail_logs',self::REV,'smtp','2026-09-12 00:00:00','2026-10-12 00:00:00'],$call['args']);
  $GLOBALS['wpdb']->get_results_return=[];$this->assertSame(['accepted'=>0,'failed'=>0],$repo->submissions(self::REV,'smtp',7,time()));
  $GLOBALS['wpdb']->last_error='secret SQL';$this->assertNull($repo->submissions(self::REV,'smtp',7,time()));
  } finally {date_default_timezone_set($previousTimezone);}
 }
 public function test_scope_schema_and_failed_insert_never_yield_a_number():void {
  $repo=new DeliverabilityRepository();$this->assertNull($repo->latest('bad','smtp'));$this->assertNull($repo->submissions(self::REV,'smtp',31,time()));$this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.10.0';$this->assertNull($repo->latest(self::REV,'smtp'));$this->assertSame(0,$repo->delete_expired('2026-01-01 00:00:00'));
  try{$repo->persist_score(self::REV,'smtp','',[]);$this->fail('No unverified writes');}catch(RuntimeException $e){$this->assertSame('Assessment storage is unavailable.',$e->getMessage());}
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.11.0';$GLOBALS['wpdb']->return_false_on_insert=true;
  try{$repo->persist_score(self::REV,'smtp','',[]);$this->fail('No score on failed insert');}catch(RuntimeException $e){$this->assertSame('Assessment could not be saved.',$e->getMessage());}
 }
 public function test_retention_is_bounded_by_health_cutoff_and_invalid_dates_fail():void {
  $repo=new DeliverabilityRepository();$this->assertSame(1,$repo->delete_expired('2026-09-12 00:00:00'));$call=end($GLOBALS['wpdb']->prepare_calls);$this->assertSame(['wp_scalyn_deliverability_evidence','2026-09-12 00:00:00'],$call['args']);$this->assertStringContainsString('ORDER BY created_at,id LIMIT 100',$call['query']);
  $this->expectException(RuntimeException::class);$repo->delete_expired('2026-02-30 00:00:00');
 }
 public function test_storage_exceptions_are_safe_and_restore_error_mode():void {
  $GLOBALS['wpdb']=new class extends WpdbStub {public function get_row(string $q,string $out=OBJECT):array|object|null{throw new RuntimeException('private SQL and credential');}};
  try{(new DeliverabilityRepository())->latest(self::REV,'smtp');$this->fail('Must reject failed read');}catch(RuntimeException $e){$this->assertSame('Assessment storage is unavailable.',$e->getMessage());}
  $this->assertFalse($GLOBALS['wpdb']->errors_suppressed);
 }
 public function test_nontransactional_schema_does_not_advance_version():void {
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.10.0';$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();$GLOBALS['wpdb']->engine='MyISAM';
  try{Migrator::migrate();$this->fail('Must verify engine');}catch(RuntimeException $e){$this->assertSame('Deliverability schema could not be verified.',$e->getMessage());}
  $this->assertSame('0.10.0',get_option('scalyn_mail_relay_db_version'));$this->assertFalse($GLOBALS['wpdb']->errors_suppressed);
 }
}
