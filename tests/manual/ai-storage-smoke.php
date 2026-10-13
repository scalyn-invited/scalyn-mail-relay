<?php
/** CLI-only disposable AI storage QA. No API requests, mail or saved production settings. */
if ( PHP_SAPI !== 'cli' ) { http_response_code(404); exit; }
define('WP_ADMIN',true);
require dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Scalyn\MailRelay\AI\{AiRepository,AiSettings,AiService,AiEvidence};
use Scalyn\MailRelay\Core\{CredentialCipher,Plugin};
use Scalyn\MailRelay\Database\AiSchema;
use Scalyn\MailRelay\Contracts\AiAdapterInterface;

global $wpdb;
$originalPrefix=$wpdb->prefix;
$prefix='scalyn_ai_qa_'.bin2hex(random_bytes(6)).'_';
$tables=[$prefix.'scalyn_ai_sessions',$prefix.'scalyn_ai_usage'];
$originalUser=get_current_user_id();
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins){echo "AI disposable QA unavailable: no administrator fixture.\n";exit(1);}
wp_set_current_user((int)$admins[0]);
$previous=$wpdb->suppress_errors(true);$checks=0;$ok=false;$cleanup=true;
$check=static function(bool $condition)use(&$checks):void {if(!$condition)throw new RuntimeException('Fixture assertion');$checks++;};
try {
 $wpdb->prefix=$prefix;AiSchema::migrate();AiSchema::migrate();
 $cipher=new CredentialCipher(base64_encode(random_bytes(32)));$repo=new AiRepository($cipher);
 $uuid=$repo->create();$check($repo->read($uuid)['messages']===[]);
 $payload=$wpdb->get_var($wpdb->prepare('SELECT payload FROM %i WHERE uuid=%s',$tables[0],$uuid));
 $check(is_string($payload)&&!str_contains($payload,'New chat'));
 $claim=$repo->claim($uuid);$blocked=false;try{$repo->claim($uuid);}catch(RuntimeException $e){$blocked=true;}$check($blocked);
 $repo->renew($uuid,$claim);$check(true);
 $repo->save($uuid,$claim,['title'=>'Fixture chat','messages'=>[]],false);$repo->release($uuid,$claim);
 $check($repo->read($uuid)['title']==='Fixture chat');
 $check(count($repo->listing())===1);
 $wpdb->query($wpdb->prepare('UPDATE %i SET owner=%d WHERE uuid=%s',$tables[0],99999999,$uuid));
 $blocked=false;try{$repo->read($uuid);}catch(RuntimeException $e){$blocked=true;}$check($blocked);
 $wpdb->query($wpdb->prepare('UPDATE %i SET owner=%d WHERE uuid=%s',$tables[0],get_current_user_id(),$uuid));
 for($n=0;$n<5;$n++)$repo->consume('smoke:minute',5,time()+120);
 $blocked=false;try{$repo->consume('smoke:minute',5,time()+120);}catch(RuntimeException $e){$blocked=true;}$check($blocked);
 $repo->consume('smoke:day',100,time()+86400);
 $wpdb->query($wpdb->prepare('UPDATE %i SET used=100 WHERE scope=%s',$tables[1],'smoke:day'));
 $blocked=false;try{$repo->consume('smoke:day',100,time()+86400);}catch(RuntimeException $e){$blocked=true;}$check($blocked);
 $wpdb->query($wpdb->prepare("UPDATE %i SET expires_at='2000-01-01 00:00:00' WHERE uuid=%s",$tables[0],$uuid));
 $blocked=false;try{$repo->read($uuid);}catch(RuntimeException $e){$blocked=true;}$check($blocked);
 $repo->cleanup();$check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$tables[0]))===0);
 $uuid=$repo->create();$repo->delete($uuid);$check(count($repo->listing())===0);
 $ok=true;
} catch(Throwable $e) {
 echo "AI disposable SQL QA failed; no internal exception data displayed.\n";
} finally {
 foreach($tables as $table){if(!str_starts_with($table,$prefix)||!preg_match('/^scalyn_ai_qa_[a-f0-9]{12}_scalyn_ai_(sessions|usage)$/D',$table)){$cleanup=false;continue;}if(false===$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table)))$cleanup=false;}
 $wpdb->prefix=$originalPrefix;$wpdb->suppress_errors($previous);wp_set_current_user($originalUser);
}
echo json_encode(['passed'=>$ok,'checks'=>$checks,'disposable_tables_removed'=>$cleanup,'api_calls'=>0,'mail_sent'=>0])."\n";
exit($ok&&$cleanup?0:1);
