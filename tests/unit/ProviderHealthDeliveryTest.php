<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Health\ProviderHealthAssessment;

final class ProviderHealthDeliveryTest extends TestCase {
 private function assess(array $delivery,?array $day=['accepted'=>20,'failed'=>0],string $connection='passed'):array {
  return ProviderHealthAssessment::evaluate('brevo','11111111-1111-4111-8111-111111111111',['status'=>$connection,'checked_at'=>'2026-10-06 00:00:00'],$day,['accepted'=>20,'failed'=>0],$delivery);
 }
 private function evidence(int $tracked,int $observed,int $hard):array {return ['status'=>'available','tracked'=>$tracked,'observed'=>$observed,'hard_bounced'=>$hard,'days'=>7,'window_start'=>'2026-09-29 00:00:00.000000','window_end'=>'2026-10-06 00:00:00.000000'];}
 public function test_bounce_threshold_is_strict_and_minimum_sample_required():void {
  $this->assertSame('healthy',$this->assess($this->evidence(20,20,1))['health']);
  $a=$this->assess($this->evidence(20,20,2));$this->assertSame('warning',$a['health']);$this->assertSame(0.1,$a['delivery']['rate']);
  $this->assertStringContainsString('over 5%',$a['findings'][0]);
  $a=$this->assess($this->evidence(19,19,19));$this->assertSame('unknown',$a['health']);$this->assertNull($a['delivery']['rate']);
 }
 public function test_missing_callbacks_are_not_healthy_or_failed():void {
  foreach([$this->evidence(30,0,0),$this->evidence(30,20,0),['status'=>'unavailable'],['status'=>'paused']] as $e) {
   $a=$this->assess($e);$this->assertSame('unknown',$a['health']);$this->assertSame(0,$a['window_24h']['failed']);
  }
  $a=$this->assess($this->evidence(40,20,2));$this->assertSame('warning',$a['health']);$this->assertSame('partial',$a['delivery']['status']);$this->assertSame(0.5,$a['delivery']['coverage']);
 }
 public function test_independent_failures_override_missing_delivery_and_stale_is_not_critical():void {
  $this->assertSame('critical',$this->assess(['status'=>'unavailable'],['accepted'=>7,'failed'=>3])['health']);
  $this->assertSame('warning',$this->assess($this->evidence(20,20,0),['accepted'=>20,'failed'=>0],'stale')['health']);
  $this->assertSame('unknown',$this->assess($this->evidence(20,20,0),null)['health']);
 }
 public function test_off_and_unsupported_use_only_submission_and_connection_evidence():void {
  foreach(['off','unsupported'] as $status) {
   $a=$this->assess(['status'=>$status]);$this->assertSame('healthy',$a['health']);$this->assertNull($a['delivery']['rate']);
  }
 }
 public function test_malformed_counts_are_unavailable_and_zero_sample_has_no_rate():void {
  foreach([['tracked'=>-1],['observed'=>21],['hard_bounced'=>21],['observed'=>'20']] as $bad) {
   $a=$this->assess(array_replace($this->evidence(20,20,0),$bad));$this->assertSame('unavailable',$a['delivery']['status']);$this->assertSame('unknown',$a['health']);
  }
  $this->assertNull($this->assess($this->evidence(0,0,0))['delivery']['coverage']);
 }
}
