<?php
/** CLI-only chat preflight. Live mode requires explicit, separately obtained consent. */
if ( PHP_SAPI !== 'cli' ) { http_response_code(404); exit; }
define('WP_ADMIN', true);
require dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Scalyn\MailRelay\AI\{AiSettings,AiEvidence,AiRepository,OpenAiAdapter};
use Scalyn\MailRelay\Core\Plugin;
$user=get_user_by('login',$argv[1]??'');
if(!$user||!user_can($user,'manage_options')){echo "Administrator required.\n";exit(1);}
wp_set_current_user((int)$user->ID);
$container=Plugin::instance()->container();
$config=$container->get(AiSettings::class)->summary();
$result=['enabled'=>$config['enabled'],'consent'=>$config['consent'],'has_key'=>$config['has_key'],'encryption'=>$config['encryption'],'model'=>$config['model'],'live_requests'=>0];
try{
 $evidence=$container->get(AiEvidence::class)->snapshot();
 $result['evidence_bytes']=strlen(wp_json_encode($evidence));
 if(($argv[2]??'')==='--live-approved'){
  if(!$config['enabled']||!$config['consent']||!$config['has_key']||!$config['encryption'])throw new RuntimeException();
  add_action('http_api_debug',static function($response,$context,$class,$args,$url)use(&$result){
   if($context!=='response'||$url!=='https://api.openai.com/v1/responses')return;
   if(is_wp_error($response)){$result['transport_failed']=true;return;}
   $result['http_status']=wp_remote_retrieve_response_code($response);
   $body=json_decode(wp_remote_retrieve_body($response),true);
   $result['response_status']=in_array($body['status']??'', ['completed','incomplete','failed'],true)?$body['status']:'unavailable';
   $result['output_tokens']=(int)($body['usage']['output_tokens']??0);
   $result['reasoning_tokens']=(int)($body['usage']['output_tokens_details']['reasoning_tokens']??0);
   foreach(['incomplete_reason'=>$body['incomplete_details']['reason']??'', 'error_code'=>$body['error']['code']??'', 'error_type'=>$body['error']['type']??'']as$key=>$value){
    $result[$key]=in_array($value,['max_output_tokens','content_filter','insufficient_quota','rate_limit_exceeded','invalid_api_key','model_not_found'],true)?$value:'other_or_none';
   }
  },10,5);
  $repo=$container->get(AiRepository::class);
  $repo->consume('ai-minute:'.get_current_user_id().':'.gmdate('Y-m-d-H-i'),5,time()+120);
  $repo->consume('ai-day:'.gmdate('Y-m-d'),100,time()+2*DAY_IN_SECONDS);
  $result['live_requests']=1;
  $answer=$container->get(OpenAiAdapter::class)->answer($config['model'],[['role'=>'user','content'=>'Explain what the plugin records about email delivery. This is a synthetic QA question.']],$evidence);
  $result['validated_answer']=true;$result['in_scope']=$answer['in_scope'];
 }
}catch(Throwable $error){$result['validated_answer']=false;if($error instanceof \Scalyn\MailRelay\AI\AiFailure && isset(\Scalyn\MailRelay\AI\AiFailure::MESSAGES[$error->reason]))$result['safe_failure']=$error->reason;}
echo wp_json_encode($result,JSON_PRETTY_PRINT)."\n";
