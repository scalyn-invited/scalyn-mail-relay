<?php
/** CLI-only, two synthetic requests; requires separately obtained live consent. */
if ( PHP_SAPI !== 'cli' || ( $argv[2] ?? '' ) !== '--two-live-approved' ) { exit(1); }
define('WP_ADMIN', true);
require dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Scalyn\MailRelay\AI\{AiSettings,AiRepository,AiService,AiFailure};
use Scalyn\MailRelay\Core\Plugin;
$user=get_user_by('login',$argv[1]??'');
if(!$user||!user_can($user,'manage_options')){echo "Administrator required.\n";exit(1);}
wp_set_current_user((int)$user->ID);
$container=Plugin::instance()->container();$config=$container->get(AiSettings::class)->summary();
if(!$config['enabled']||!$config['consent']||!$config['has_key']||!$config['encryption']){echo "AI not ready.\n";exit(1);}
$repo=$container->get(AiRepository::class);$id=$repo->create();$results=[];
try {
 foreach(['what to be fixed?','How do I set up email sending with this plugin? Give the setup steps rather than repeating the diagnostic explanation.'] as $question){
  try {
   $session=$container->get(AiService::class)->send($id,$question);$message=end($session['messages']);$answer=$message['answer'];
   $results[]=['question'=>$question,'provider'=>$message['provider'],'model'=>$message['model'],'in_scope'=>$answer['in_scope'],'explanation'=>$answer['possible_causes'],'steps'=>$answer['next_steps'],'references'=>$answer['references'],'context_truncated'=>$message['context_truncated']];
  } catch(Throwable $error){$results[]=['safe_failure'=>$error instanceof AiFailure?$error->reason:'local_request_failed'];}
 }
} finally { $repo->delete($id); }
echo wp_json_encode(['synthetic_chat_removed'=>true,'results'=>$results],JSON_PRETTY_PRINT)."\n";
