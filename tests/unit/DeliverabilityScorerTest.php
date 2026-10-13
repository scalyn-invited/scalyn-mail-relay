<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\DeliverabilityScorer;

final class DeliverabilityScorerTest extends TestCase {
 private const NOW=1791763200;
 private function input():array {
  $checks=[];foreach(['spf_record','dkim_record','dmarc_policy','mx_record','reverse_dns'] as $name){$checks[$name]=['status'=>'pass','checked_at'=>self::NOW];}
  return ['evaluated_at'=>self::NOW,'fresh_for'=>7200,'days'=>30,'transport'=>'api','checks'=>$checks,'alignment'=>'possible_with_configured_selector','submissions'=>['accepted'=>100,'failed'=>0],'delivery'=>['status'=>'off']];
 }
 private function score(array $input):array {return (new DeliverabilityScorer())->evaluate($input);}
 public function test_weights_and_deterministic_replay_with_configuration_alignment_only():void {
  $s=$this->score($this->input());
  $this->assertSame(['authentication'=>35,'domain_alignment'=>15,'dns_quality'=>10,'historical_performance'=>40,'header_quality'=>0,'provider_reputation'=>0],DeliverabilityScorer::WEIGHTS);
  $this->assertSame(92,$s['score']);$this->assertSame(100,$s['weight_coverage']);
  $this->assertSame(50,$s['components']['domain_alignment']['included']['dmarc_configuration']);
  $this->assertSame($s,$this->score($s['inputs']));
  $this->assertSame('not_applicable',$s['components']['dns_quality']['excluded']['reverse_dns']);
  $this->assertNull($s['components']['header_quality']['score']);$this->assertNull($s['components']['provider_reputation']['score']);
 }
 public function test_each_gate_is_mandatory_and_missing_is_not_failure():void {
  $i=$this->input();$i['submissions']=['accepted'=>19,'failed'=>0];$s=$this->score($i);$this->assertNull($s['score']);$this->assertNull($s['components']['historical_performance']['score']);
  $i=$this->input();$i['checks']['spf_record']['status']='unknown';$i['checks']['dkim_record']['status']='error';$s=$this->score($i);$this->assertNull($s['score']);$this->assertSame(1,$s['authentication_count']);
  $i=$this->input();$i['submissions']=null;$s=$this->score($i);$this->assertNull($s['score']);$this->assertSame(60,$s['weight_coverage']);
  $i['alignment']='not_assessed';$s=$this->score($i);$this->assertSame(45,$s['weight_coverage']);$this->assertCount(2,$s['missing']);
  $i=$this->input();$i['submissions']=['accepted'=>20,'failed'=>0];$i['alignment']='not_assessed';$i['checks']['mx_record']['status']='unknown';$s=$this->score($i);$this->assertSame(75,$s['weight_coverage']);$this->assertSame(100,$s['score']);
 }
 public function test_unknown_error_unavailable_and_stale_are_excluded_not_zero():void {
  foreach(['unknown','error','not_assessed','unavailable','stale','private value'] as $status){$i=$this->input();$i['checks']['spf_record']['status']=$status;$s=$this->score($i);$this->assertSame(92,$s['score']);$this->assertArrayNotHasKey('spf_record',$s['components']['authentication']['included']);}
  $i=$this->input();$i['checks']['spf_record']['status']='warn';$s=$this->score($i);$this->assertEqualsWithDelta(250/3,$s['components']['authentication']['score'],0.00001);
  $i['checks']['spf_record']['status']='fail';$s=$this->score($i);$this->assertEqualsWithDelta(200/3,$s['components']['authentication']['score'],0.00001);$this->assertSame('Critical',$s['recommendations'][0]['priority']);
 }
 public function test_exact_freshness_boundary_and_future_or_missing_dates():void {
  foreach([0,7200,7201,-1] as $age){$i=$this->input();foreach($i['checks'] as &$row){$row['checked_at']=self::NOW-$age;}unset($row);$s=$this->score($i);$this->assertSame($age>=0&&$age<=7200?92:null,$s['score']);}
  $i=$this->input();foreach($i['checks'] as &$row){unset($row['checked_at']);}unset($row);$this->assertNull($this->score($i)['score']);
  $i=$this->input();$i['fresh_for']=9999999;foreach($i['checks'] as &$row){$row['checked_at']=self::NOW-604801;}unset($row);$this->assertNull($this->score($i)['score']);
 }
 public function test_submission_thresholds_use_exact_integer_boundaries():void {
  foreach([0=>100,1=>100,2=>50,5=>50,6=>0,100=>0] as $failed=>$points){$i=$this->input();$i['submissions']=['accepted'=>100-$failed,'failed'=>$failed];$s=$this->score($i);$this->assertSame($points,$s['components']['historical_performance']['included']['submission_reliability']);}
  $i=$this->input();$i['submissions']=['accepted'=>0,'failed'=>20];$this->assertSame(52,$this->score($i)['score']);
 }
 public function test_bounce_minimum_and_coverage_boundaries_and_no_delivery_points():void {
  foreach([[40,20,0,100],[40,20,1,50],[40,20,2,0],[41,20,0,null],[38,19,0,null],[0,0,0,null]] as [$tracked,$observed,$bounced,$points]){
   $i=$this->input();$i['delivery']=['status'=>'available','tracked'=>$tracked,'observed'=>$observed,'hard_bounced'=>$bounced];$s=$this->score($i);$this->assertSame($points,$s['components']['historical_performance']['included']['hard_bounce_rate']??null);
   $this->assertArrayNotHasKey('delivery_confirmation',$s['components']['historical_performance']['included']);
   if(null===$points){$this->assertSame(100,$s['components']['historical_performance']['included']['submission_reliability']);}
  }
  foreach([1=>100,2=>50,5=>50,6=>0] as $bounced=>$points){$i=$this->input();$i['delivery']=['status'=>'available','tracked'=>100,'observed'=>100,'hard_bounced'=>$bounced];$this->assertSame($points,$this->score($i)['components']['historical_performance']['included']['hard_bounce_rate']);}
 }
 public function test_off_paused_unsupported_and_malformed_do_not_create_failures():void {
  foreach(['off','paused','unsupported','unavailable'] as $status){$i=$this->input();$i['delivery']=['status'=>$status];$this->assertSame(92,$this->score($i)['score']);}
  foreach([['tracked'=>20,'observed'=>21,'hard_bounced'=>0],['tracked'=>20,'observed'=>20,'hard_bounced'=>21],['tracked'=>'20','observed'=>20,'hard_bounced'=>0]] as $counts){$i=$this->input();$i['delivery']=$counts+['status'=>'available'];$this->assertNull($this->score($i)['delivery_coverage']);}
  foreach([null,['accepted'=>-1,'failed'=>1],['accepted'=>'20','failed'=>0],['accepted'=>20]] as $counts){$i=$this->input();$i['submissions']=$counts;$this->assertNull($this->score($i)['score']);}
 }
 public function test_trace_is_allowlisted_and_smtp_reverse_dns_is_applicable():void {
  $i=$this->input();$i['raw_headers']='secret';$i['checks']['spf_record']['evidence']='secret';$i['delivery']['recipient']='secret';$s=$this->score($i);$this->assertStringNotContainsString('secret',json_encode($s));
  $i['transport']='smtp';$i['checks']['reverse_dns']['status']='fail';$s=$this->score($i);$this->assertSame(0,$s['components']['dns_quality']['included']['reverse_dns']);$this->assertSame(87,$s['score']);
  $this->assertSame(30,$s['inputs']['days']);$this->assertSame(self::NOW-30*86400,$s['inputs']['window_start']);
 }
}
