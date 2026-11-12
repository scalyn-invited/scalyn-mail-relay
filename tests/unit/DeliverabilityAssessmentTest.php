<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliverabilityRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Database\ProviderDeliveryRepository;
use Scalyn\MailRelay\Diagnostics\CurrentDiagnostics;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;
use Scalyn\MailRelay\Diagnostics\DeliverabilityAssessment;
use Scalyn\MailRelay\Health\ProviderDeliveryEvidence;
use Scalyn\MailRelay\Admin\Components\DeliverabilityCard;
use Scalyn\MailRelay\Admin\Components\HeaderAnalysisForm;

final class DeliverabilityWpdb extends WpdbStub {
 public array $diagnostics=[];
 public array $counts=[['status'=>'accepted','row_count'=>'20']];
 public ?array $score=null;
 public function get_results(string $q,string $out=OBJECT):array {return str_contains($q,'COUNT(*) AS row_count')?$this->counts:$this->diagnostics;}
 public function get_row(string $q,string $out=OBJECT):array|object|null {return str_contains($q,'computation_uuid')?$this->score:null;}
 public function insert(string $table,array $data,mixed $format=null):int|false {
  $result=parent::insert($table,$data,$format);if($result===1&&($data['kind']??'')==='score'){$this->score=$data;}return $result;
 }
}

final class DeliverabilityAssessmentTest extends TestCase {
 private const REV='11111111-1111-4111-8111-111111111111';
 protected function setUp():void {
  $GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.11.0',SettingsRepository::OPTION_KEY=>['provider'=>['active'=>'brevo'],'diagnostic_revision'=>self::REV,'advanced'=>['log_retention_days'=>7,'diagnostic_schedule'=>'hourly']]];
  $GLOBALS['_test_current_user_can']=[Capabilities::RUN_DIAGNOSTICS=>true,Capabilities::VIEW_DASHBOARD=>true];$GLOBALS['_test_wp_nonce_valid']=true;
  $GLOBALS['_test_timezone']='UTC';$GLOBALS['wpdb']=new DeliverabilityWpdb();foreach(['spf_record','dkim_record','dmarc_policy','mx_record'] as $name){$GLOBALS['wpdb']->diagnostics[]=['check_name'=>$name,'status'=>'pass','created_at'=>gmdate('Y-m-d H:i:s'),'configuration_id'=>self::REV,'provider_id'=>'brevo','sending_domain'=>'example.com','diagnostic_uuid'=>self::REV,'raw_result'=>json_encode(['raw'=>['alignment'=>['dkim'=>'possible_with_configured_selector']],'evidence'=>'private@example.com'])];}
  $_POST=[];$_SERVER['REQUEST_METHOD']='GET';
 }
 protected function tearDown():void {$GLOBALS['_test_wp_options']=[];$GLOBALS['_test_current_user_can']=[];$GLOBALS['_test_wp_nonce_valid']=false;unset($GLOBALS['wpdb'],$GLOBALS['_test_timezone']);$_POST=[];$_SERVER['REQUEST_METHOD']='GET';}
 private function service():DeliverabilityAssessment {
  $settings=new SettingsRepository();return new DeliverabilityAssessment($settings,new CurrentDiagnostics(new DiagnosticRepository(),new HealthScoreRepository(),$settings,new DiagnosticContextBuilder()),new DeliverabilityRepository(),new ProviderDeliveryEvidence($settings,new ProviderDeliveryRepository(),[]));
 }
 public function test_shared_read_model_reads_without_writing_and_explicit_assessment_persists():void {
  $s=$this->service();$this->assertNull($s->current());$this->assertSame([],$GLOBALS['wpdb']->inserts);$a=$s->assess();$this->assertSame(92,$a['score']);$this->assertSame(7,$a['inputs']['days']);$this->assertSame(7200,$a['inputs']['fresh_for']);$this->assertSame(50,$a['components']['domain_alignment']['included']['dmarc_configuration']);$this->assertSame($a,$s->current());$this->assertCount(1,$GLOBALS['wpdb']->inserts);$this->assertStringNotContainsString('private',json_encode($a));
 }
 public function test_configuration_change_new_run_retention_and_stale_invalidate_number():void {
  $s=$this->service();$s->assess();$GLOBALS['wpdb']->diagnostics[0]['diagnostic_uuid']='22222222-2222-4222-8222-222222222222';$this->assertNull($s->current()['score']);$this->assertTrue($s->current()['needs_refresh']);
  $GLOBALS['wpdb']->diagnostics[0]['diagnostic_uuid']=self::REV;
  $payload=json_decode($GLOBALS['wpdb']->score['payload'],true);foreach($payload['inputs']['checks'] as &$row){$row['checked_at']=time()-7201;}unset($row);$trace=(new \Scalyn\MailRelay\Diagnostics\DeliverabilityScorer())->evaluate($payload['inputs']);$GLOBALS['wpdb']->score['payload']=json_encode($trace);$this->assertNull($s->current()['score']);
  $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['diagnostic_revision']='22222222-2222-4222-8222-222222222222';$GLOBALS['wpdb']->score=null;$this->assertNull($this->service()->current());
 }
 public function test_nonce_capability_and_failed_storage_protect_refresh():void {
  $card=new DeliverabilityCard($this->service());$_POST=['scalyn_deliverability_assess'=>'1'];$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['_test_wp_nonce_valid']=false;
  try{$card->handle();$this->fail('Nonce must protect write');}catch(RuntimeException $e){}$this->assertSame([],$GLOBALS['wpdb']->inserts);
  $GLOBALS['_test_wp_nonce_valid']=true;$GLOBALS['_test_current_user_can']=[];try{$card->handle();$this->fail('Capability must protect write');}catch(RuntimeException $e){}$this->assertSame([],$GLOBALS['wpdb']->inserts);
  $GLOBALS['_test_current_user_can']=[Capabilities::RUN_DIAGNOSTICS=>true];$GLOBALS['wpdb']->return_false_on_insert=true;$card->handle();ob_start();$card->render();$html=ob_get_clean();$this->assertStringContainsString('Not assessed',$html);$this->assertStringContainsString('could not be saved',$html);$this->assertStringNotContainsString('92/100',$html);
 }
 public function test_header_analysis_records_only_verdicts_after_protected_action():void {
  $s=$this->service();$form=new HeaderAnalysisForm(recorder:static fn(array $a)=>$s->record_header($a));$_SERVER['REQUEST_METHOD']='POST';$_POST=['scalyn_header_analysis'=>'1','scalyn_headers'=>"From: Private <secret@example.com>\r\nAuthentication-Results: mx.example.com; spf=pass smtp.mailfrom=example.com; dkim=pass header.d=example.com; dmarc=pass\r\nSubject: Private\r\n\r\nbody"];
  $form->handle();$this->assertSame('',$_POST['scalyn_headers']);$insert=end($GLOBALS['wpdb']->inserts)['data'];$this->assertSame('header',$insert['kind']);$this->assertStringNotContainsString('secret',$insert['payload']);$this->assertStringNotContainsString('example.com',$insert['payload']);$this->assertStringNotContainsString('Private',$insert['payload']);$this->assertSame('pass',json_decode($insert['payload'],true)['verdicts']['dmarc']);
 }
 public function test_component_ui_is_separate_explained_and_escaped():void {
  $s=$this->service();$s->assess();ob_start();(new DeliverabilityCard($s))->render();$html=ob_get_clean();foreach(['Deliverability Score (evidence-based estimate)','92/100','Component-weight coverage:','100%','Historical window (UTC):','retention setting','Ranked recommendations','Only submission reliability','Provider reputation','not confirmed delivery'] as $text){$this->assertStringContainsString($text,$html);}
  $this->assertStringNotContainsString('private@example.com',$html);$this->assertStringContainsString('method="post"',$html);
 }
}
