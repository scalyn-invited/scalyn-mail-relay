<?php
/** CLI-only disposable, explicitly synthetic chat preview. No OpenAI request or mail. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('WP_ADMIN',true);
require dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Scalyn\MailRelay\AI\AiRepository;
use Scalyn\MailRelay\Core\CredentialCipher;
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins){echo "Fixture unavailable.\n";exit(1);}
$principal=isset($argv[3])?get_user_by('login',$argv[3]):null;
if(isset($argv[3])&&(!$principal||!user_can($principal,'manage_options'))){echo "Administrator fixture unavailable.\n";exit(1);}
wp_set_current_user($principal?(int)$principal->ID:(int)$admins[0]);
$repo=new AiRepository(new CredentialCipher());
$title='QA fixture only — synthetic chat preview';
try{
 if(($argv[1]??'')==='create'){
  $uuid=$repo->create();$claim=$repo->claim($uuid);
  try{$repo->save($uuid,$claim,['title'=>$title,'messages'=>[
   ['role'=>'user','text'=>'Explain the plugin diagnostic results (synthetic QA fixture).','at'=>gmdate('c')],
   ['role'=>'assistant','at'=>gmdate('c'),'revision'=>'synthetic-preview-not-live','captured_at_utc'=>gmdate('Y-m-d H:i:s'),'context_truncated'=>true,'answer'=>['in_scope'=>true,'observed'=>'Synthetic preview only. No live diagnostic result is asserted by this fixture.','possible_causes'=>'Example advisory explanation for layout review, not an actual finding.','next_steps'=>'Review the Diagnostics page for recorded findings.','references'=>['help','diagnostics'],'suggest_diagnostics'=>false]]
  ]]);}finally{$repo->release($uuid,$claim);}
  echo json_encode(['fixture_uuid'=>$uuid,'synthetic'=>true,'api_calls'=>0,'mail_sent'=>0])."\n";
 }elseif(($argv[1]??'')==='remove'){
  $uuid=$argv[2]??'';$session=$repo->read($uuid);
  if($session['title']!==$title)throw new RuntimeException();
  $repo->delete($uuid);echo "Synthetic QA fixture removed.\n";
 }else{throw new RuntimeException();}
}catch(Throwable $e){echo "Synthetic fixture operation unavailable; no private details displayed.\n";exit(1);}
