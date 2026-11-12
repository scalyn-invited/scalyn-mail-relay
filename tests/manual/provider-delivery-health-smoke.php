<?php
/** CLI-only SQL check. Temporary tables only; no plugin boot, mail or settings changes. */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
define('SHORTINIT',true);
require dirname(__DIR__,5).'/wp-load.php';
require dirname(__DIR__,2).'/vendor/autoload.php';

use Scalyn\MailRelay\Database\DeliveryEvidenceSchema;
use Scalyn\MailRelay\Database\ProviderDeliveryRepository;

global $wpdb;
$original=$wpdb->prefix;
$wpdb->prefix='scalyn_health_qa_'.bin2hex(random_bytes(6)).'_';
$tables=[];$passed=false;$cleanup=true;$stage='tables';
$old=$wpdb->suppress_errors(true);
$check=static function(bool $ok):void {if(!$ok){throw new RuntimeException('Assertion failed');}};
try {
 foreach(DeliveryEvidenceSchema::definitions() as $suffix=>$definition) {
  if($suffix==='scalyn_delivery_keys'){continue;}
  $table=$wpdb->prefix.$suffix;
  $check(false!==$wpdb->query($wpdb->prepare('CREATE TEMPORARY TABLE %i ('.$definition['sql'].') ENGINE=InnoDB',$table)));
  $tables[]=$table;
 }
 $id='11111111-1111-4111-8111-111111111111';
 $other='22222222-2222-4222-8222-222222222222';
 $time='2026-10-06 00:00:00';
 $attempts=$wpdb->prefix.'scalyn_delivery_attempts';
 $recipients=$wpdb->prefix.'scalyn_delivery_recipients';
 $events=$wpdb->prefix.'scalyn_delivery_events';
 $stage='fixtures';
 for($i=1;$i<=6;$i++) {
  $message=sprintf('00000000-0000-4000-8000-%012d',$i);
  $check(false!==$wpdb->insert($attempts,['message_uuid'=>$message,'source_id'=>$i===4?$other:$id,'provider'=>$i===5?'postmark':'brevo','configuration_id'=>$i===3?$other:$id,'provider_message_id'=>'qa-'.$i,'key_version'=>$id,'expected_recipients'=>1,'created_at'=>$i===6?'2026-09-01 00:00:00':$time]));
  $check(false!==$wpdb->insert($recipients,['message_uuid'=>$message,'recipient_token'=>str_repeat('a',64)]));
  // First recipient has duplicate hard bounces and delivery; second has no callback.
  if($i===2){continue;}
  for($n=0;$n<3;$n++) {
   $check(false!==$wpdb->insert($events,['schema_version'=>1,'source_id'=>$i===4?$other:$id,'provider'=>$i===5?'postmark':'brevo','message_uuid'=>$message,'provider_message_id'=>'qa-'.$i,'event_key'=>hash('sha256',"$i-$n"),'kind'=>$n===2?'delivery':'bounce','recipient_token'=>str_repeat('a',64),'occurred_at'=>$time,'received_at'=>$time,'authentication_method'=>'basic','reason_code'=>$n===2?null:'hard_bounce']));
  }
 }
 $repo=new ProviderDeliveryRepository();
 $now=new DateTimeImmutable('2026-10-06T01:00:00Z');
 $stage='scope and deduplication';
 $result=$repo->counts($id,'brevo',$id,7,$now);
 $check(is_array($result) && $result['tracked']===2 && $result['observed']===1 && $result['hard_bounced']===1);
 $stage='expired evidence';
 $result=$repo->counts($id,'brevo',$id,7,$now->modify('+8 days'));
 $check(is_array($result) && $result['tracked']===0 && $result['observed']===0);
 $stage='future evidence';
 $check(false!==$wpdb->query($wpdb->prepare('UPDATE %i SET occurred_at=%s',$events,'2026-11-01 00:00:00')));
 $result=$repo->counts($id,'brevo',$id,7,$now);
 $check(is_array($result) && $result['tracked']===2 && $result['observed']===0);
 $passed=true;
} catch(Throwable $error) {
 // Only the fixed phase label is reported; no SQL, identifiers or credentials.
} finally {
 foreach($tables as $table) {
  if(preg_match('/^scalyn_health_qa_[a-f0-9]{12}_scalyn_delivery_(attempts|recipients|events)$/D',$table)) {
   $cleanup=(false!==$wpdb->query($wpdb->prepare('DROP TEMPORARY TABLE IF EXISTS %i',$table))) && $cleanup;
  }
 }
 $wpdb->prefix=$original;$wpdb->suppress_errors($old);
}
echo $passed && $cleanup ? "PASS: scoped SQL, deduplication, missing/future/expired evidence; temporary tables removed.\n" : "FAIL: $stage or temporary cleanup.\n";
exit($passed && $cleanup?0:1);
