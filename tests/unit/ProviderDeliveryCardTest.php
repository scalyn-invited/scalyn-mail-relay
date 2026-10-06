<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Health\ProviderHealthAssessment;

if (!function_exists('number_format_i18n')) {
 function number_format_i18n($number,$decimals=0) {return number_format($number,$decimals);}
}
final class ProviderDeliveryCardTest extends TestCase {
 private function render(bool $active):string {
  $active_label='Brevo';$can_configure=true;
  $health=ProviderHealthAssessment::evaluate('brevo','revision',['status'=>'passed'],['accepted'=>20,'failed'=>0],['accepted'=>20,'failed'=>0],[
   'status'=>'available','tracked'=>40,'observed'=>20,'hard_bounced'=>2,
   'window_start'=>'<script>private</script>','window_end'=>'2026-10-06 00:00:00'
  ]);
  $providers=[['id'=>'brevo','label'=>'Brevo','is_active'=>$active,'configured'=>true,'transport'=>'API (HTTPS)','health'=>$health,'evidence'=>'collecting']];
  ob_start();
  try {include dirname(__DIR__,2).'/admin/views/providers.php';return ob_get_contents();}
  finally {ob_end_clean();}
 }
 public function test_active_card_shows_counts_rate_window_and_escapes():void {
  $html=$this->render(true);
  foreach(['20 of 40 tracked','2 hard-bounced','10.0%','Some tracked recipient outcomes remain unknown','Window (UTC):','&lt;script&gt;private&lt;/script&gt;'] as $text) {$this->assertStringContainsString($text,$html);}
  $this->assertStringNotContainsString('<script>',$html);
 }
 public function test_inactive_card_never_reuses_delivery_summary():void {
  $html=$this->render(false);
  $this->assertStringNotContainsString('20 of 40 tracked',$html);
  $this->assertStringNotContainsString('10.0%',$html);
 }
}
