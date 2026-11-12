<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\ConnectionEvidenceRepository;
use Scalyn\MailRelay\Database\Migrator;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Health\ProviderHealthAssessment as Health;
require_once dirname(__DIR__).'/fixtures/delivery-schema-wpdb.php';

final class FailureCategoryEvidenceTest extends TestCase {
 private const REV='11111111-1111-4111-8111-111111111111';
 private const TIME='2026-10-06 00:00:00.000000';
 protected function setUp():void {
  $GLOBALS['wpdb']=new class extends WpdbStub {public string $last_error='';};
  $GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.10.0'];
  $GLOBALS['_test_dbdelta_queries']=[];
 }
 protected function tearDown():void {
  $GLOBALS['_test_wp_options']=[];unset($GLOBALS['wpdb']);
 }
 private function counts(array $changes=[]):array {
  return array_replace(['auth_config_streak'=>0,'provider_rejections'=>0,'rate_limits'=>0,'unclassified'=>0],$changes);
 }
 private function health(array $connection,?array $counts):array {
  return Health::evaluate('smtp',self::REV,$connection,['accepted'=>20,'failed'=>0],['accepted'=>20,'failed'=>0],null,$counts);
 }
 public function test_fresh_auth_config_failure_is_critical_but_stale_and_unknown_are_not():void {
  foreach(['auth','config'] as $category) {
   $this->assertSame('critical',$this->health(['status'=>'failed','failure_category'=>$category],$this->counts())['health']);
   $this->assertSame('warning',$this->health(['status'=>'stale','failure_category'=>$category],$this->counts())['health']);
   $this->assertSame('unknown',$this->health(['status'=>'unknown','failure_category'=>$category],$this->counts())['health']);
  }
  foreach([null,'unknown','private-token','timeout','tls'] as $category) {
   $this->assertSame('unknown',$this->health(['status'=>'failed','failure_category'=>$category],$this->counts())['health']);
  }
 }
 public function test_three_consecutive_failures_and_rejection_rules_have_no_ten_attempt_minimum():void {
  foreach([0,1,2,3,4] as $streak) {
   $a=Health::evaluate('smtp',self::REV,['status'=>'unknown'],['accepted'=>0,'failed'=>$streak],['accepted'=>0,'failed'=>$streak],null,$this->counts(['auth_config_streak'=>$streak]));
   $this->assertSame($streak>=3?'critical':'unknown',$a['health']);
  }
  foreach(['provider_rejections','rate_limits'] as $field) {
   $this->assertSame('warning',$this->health(['status'=>'passed'],$this->counts([$field=>1]))['health']);
   $this->assertSame('critical',$this->health(['status'=>'passed'],$this->counts([$field=>1,'auth_config_streak'=>3]))['health']);
  }
 }
 public function test_missing_and_unclassified_evidence_cannot_establish_healthy():void {
  foreach([null,$this->counts(['unclassified'=>1]),$this->counts(['rate_limits'=>-1])] as $counts) {
   $this->assertSame('unknown',$this->health(['status'=>'passed'],$counts)['health']);
  }
  $this->assertSame('healthy',$this->health(['status'=>'passed'],$this->counts())['health']);
 }
 public function test_sequence_breaks_on_accepted_prepared_unknown_or_other_failure():void {
  $repo=new MailLogRepository();
  $auth=['status'=>'failed','failure_category'=>'auth'];
  foreach([['status'=>'accepted'],['status'=>'prepared'],['status'=>'failed'],['status'=>'failed','failure_category'=>'timeout']] as $break) {
   $GLOBALS['wpdb']->get_results_return=[$auth,$break,$auth,$auth];
   $this->assertSame(1,$repo->configuration_failure_evidence(self::REV,'smtp')['auth_config_streak']);
  }
  $GLOBALS['wpdb']->get_results_return=[$auth,['status'=>'failed','failure_category'=>'config'],$auth,['status'=>'failed','failure_category'=>'rate-limit'],['status'=>'failed','failure_category'=>'provider-rejection']];
  $r=$repo->configuration_failure_evidence(self::REV,'smtp');
  $this->assertSame(3,$r['auth_config_streak']);$this->assertSame(1,$r['rate_limits']);$this->assertSame(1,$r['provider_rejections']);
  $call=end($GLOBALS['wpdb']->prepare_calls);
  foreach(['configuration_id=%s AND provider=%s','INTERVAL 24 HOUR','created_at <= %s','ORDER BY created_at DESC,id DESC LIMIT 1001'] as $guard) {$this->assertStringContainsString($guard,$call['query']);}
  $this->assertSame([self::REV,'smtp'],array_slice($call['args'],1,2));
  $this->assertStringNotContainsString('response_message',$call['query']);
 }
 public function test_schema_scope_overflow_and_storage_fail_closed():void {
  $repo=new MailLogRepository();
  $this->assertNull($repo->configuration_failure_evidence('bad','smtp'));
  $this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
  $GLOBALS['wpdb']->get_results_return=array_fill(0,1001,['status'=>'accepted']);
  $this->assertNull($repo->configuration_failure_evidence(self::REV,'smtp'));
  $GLOBALS['wpdb']->get_results_return=[];$GLOBALS['wpdb']->last_error='private SQL';
  $this->assertNull($repo->configuration_failure_evidence(self::REV,'smtp'));
  $this->assertFalse($GLOBALS['wpdb']->errors_suppressed);
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.9.0';
  $this->assertNull($repo->configuration_failure_evidence(self::REV,'smtp'));
 }
 public function test_log_persistence_allowlist_and_unconfirmed_exclusion():void {
  $repo=new MailLogRepository();$m=new MailMessage(self::REV,'from@example.test',['to@example.test'],'Subject','Body');
  foreach(['auth','config','rate-limit','provider-rejection','unknown','private-secret',null] as $category) {
   $repo->upsert($m,new SendResult(false,'smtp',failure_category:$category),'failed',self::REV);
   $this->assertSame($category==='private-secret'?null:$category,end($GLOBALS['wpdb']->inserts)['data']['failure_category']);
  }
  $GLOBALS['wpdb']->get_var_return=1;
  foreach([new SendResult(true,'smtp',failure_category:'auth'),new SendResult(false,'smtp',failure_category:'auth',acceptance_unconfirmed:true)] as $result) {
   $repo->upsert($m,$result,'failed',self::REV);
   $this->assertNull(end($GLOBALS['wpdb']->updates)['data']['failure_category']);
  }
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.9.0';
  $repo->upsert($m,new SendResult(false,'smtp',failure_category:'auth'),'failed',self::REV);
  $this->assertArrayNotHasKey('failure_category',end($GLOBALS['wpdb']->updates)['data']);
 }
 public function test_connection_upsert_category_is_atomic_allowlisted_and_cleared_by_success():void {
  $repo=new ConnectionEvidenceRepository();
  foreach([['failed','auth','auth'],['failed','private',''],['passed','auth',''],['unknown','auth','']] as [$status,$category,$expected]) {
   $repo->record(self::REV,'smtp',$status,self::TIME,self::TIME,$category);
   $call=end($GLOBALS['wpdb']->prepare_calls);
   $this->assertSame($expected,end($call['args']));
   $this->assertStringContainsString('failure_category=IF(VALUES(started_at)>started_at,VALUES(failure_category),failure_category)',$call['query']);
  }
  $GLOBALS['wpdb']->get_row_return=['status'=>'failed','started_at'=>self::TIME,'checked_at'=>self::TIME,'failure_category'=>'auth','private'=>'secret'];
  $this->assertSame('auth',$repo->find(self::REV,'smtp')['failure_category']);
  $GLOBALS['wpdb']->get_row_return['failure_category']='private';
  $this->assertArrayNotHasKey('failure_category',$repo->find(self::REV,'smtp'));
 }
 public function test_migration_is_additive_retryable_and_does_not_backfill():void {
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.9.0';
  $GLOBALS['wpdb']=new DeliverySchemaWpdbStub();$GLOBALS['wpdb']->missingFailureCategory=true;
  try {Migrator::migrate();$this->fail('Must reject missing column');}catch(RuntimeException $e){$this->assertSame('Failure category migration failed.',$e->getMessage());}
  $this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
  $GLOBALS['wpdb']->missingFailureCategory=false;Migrator::migrate();
  $this->assertSame(SCALYN_MAIL_RELAY_DB_VERSION,get_option('scalyn_mail_relay_db_version'));
  $before=count($GLOBALS['_test_dbdelta_queries']);Migrator::migrate();$this->assertCount($before,$GLOBALS['_test_dbdelta_queries']);
  foreach($GLOBALS['_test_dbdelta_queries'] as $sql){if(str_contains($sql,'scalyn_mail_logs') || str_contains($sql,'scalyn_connection_evidence')){$this->assertStringContainsString('failure_category varchar(32) NULL',$sql);}$this->assertStringNotContainsString('UPDATE',$sql);}
 }
}
