<?php
/** CLI-only, disposable real WordPress recovery SQL test. Never sends mail. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
if (wp_using_ext_object_cache()) { fwrite(STDERR,"Blocked: process-local WordPress cache required. No fixture writes.\n"); exit(1); }

use Scalyn\MailRelay\Core\{CredentialCipher,SettingsRepository,Capabilities,HookNames,ProviderRegistry};
use Scalyn\MailRelay\Database\{SubmissionAttemptSchema,SubmissionAttemptRepository,RecoveryQueueSchema,RecoveryQueueRepository};
use Scalyn\MailRelay\Recovery\{RecoveryPolicyRepository,RecoveryPayload,RecoveryService};
use Scalyn\MailRelay\Mail\{MailMessage,MailDispatcher,SendResult};
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Providers\{ValidationResult,ConnectionResult};

global $wpdb,$wp_object_cache,$current_user,$wp_filter;
$original=[$wpdb->prefix,$wpdb->options,$wp_object_cache,$current_user,$wp_filter];
$prefix='scalyn_recovery_qa_'.bin2hex(random_bytes(6)).'_';
$tables=[$prefix.'options',$prefix.'scalyn_submission_attempts',$prefix.'scalyn_recovery_queue'];
$ok=false;$cleanup=true;$stage='setup';$assertions=0;
$check=static function(bool $value)use(&$assertions):void{++$assertions;if(!$value)throw new RuntimeException('Fixture assertion');};
$previous=$wpdb->suppress_errors(true);
try {
    $check(false!==$wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i',$tables[0],$original[1])));
    $wpdb->prefix=$prefix;$wpdb->options=$tables[0];$wp_object_cache=new WP_Object_Cache();
    foreach([HookNames::AUDIT_EVENT,HookNames::MAIL_SENT,HookNames::MAIL_FAILED,HookNames::MAIL_OUTCOME_UNCONFIRMED] as $hook)remove_all_actions($hook);
    $current_user=new WP_User();$current_user->ID=999999919;$current_user->allcaps=['manage_options'=>true,Capabilities::MANAGE_SETTINGS=>true];
    $stage='repeatable schema';
    SubmissionAttemptSchema::migrate();RecoveryQueueSchema::migrate();SubmissionAttemptSchema::migrate();RecoveryQueueSchema::migrate();
    update_option('scalyn_mail_relay_db_version','0.13.0',false);
    $cipher=new CredentialCipher(base64_encode(str_repeat('q',32)));
    $settings=new SettingsRepository($cipher);
    $check($settings->save(['provider'=>['active'=>'smtp'],'smtp'=>['host'=>'synthetic.example.test','from_email'=>'sender@example.test','username'=>'synthetic','password'=>'synthetic-private']]));
    $check($settings->configure('import'));
    $policy=new RecoveryPolicyRepository();$policy->save(true,true,'',$settings,$cipher);
    $provider=new class implements ProviderInterface {
        public int $calls=0;public array $results=[];
        public function get_id():string{return 'smtp';}
        public function get_label():string{return 'Offline fixture';}
        public function get_capabilities():array{return [];}
        public function validate_config(array $config):ValidationResult{return new ValidationResult(true);}
        public function test_connection(array $config):ConnectionResult{return new ConnectionResult(true);}
        public function send(MailMessage $message,array $config):SendResult{++$this->calls;return array_shift($this->results)??new SendResult(true,'smtp');}
    };
    $registry=new ProviderRegistry();$registry->register($provider);
    $queue=new RecoveryQueueRepository();$attempts=new SubmissionAttemptRepository();
    $service=new RecoveryService($queue,$policy,new RecoveryPayload($cipher),new MailDispatcher($registry,$settings,null,$attempts),$settings);
    $message=static fn()=>new MailMessage(wp_generate_uuid4(),'sender@example.test',['recipient@example.test'],'synthetic subject','synthetic body');
    $row=static fn(string $id)=>$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE logical_uuid=%s',$tables[2],$id),ARRAY_A);
    $stage='durable encrypted ownership and acceptance';
    $mail=$message();$check($service->own($mail));$check(0===$provider->calls);
    $stored=$row($mail->uuid);$check('pending'===$stored['state']);$check(!str_contains($stored['payload'],'synthetic body'));$check(!str_contains($stored['payload'],'recipient@example.test'));
    $service->tick();$service->tick();$check(1===$provider->calls);$check('accepted'===$row($mail->uuid)['state']);$check(''===$row($mail->uuid)['payload']);$check(1===count($attempts->history($mail->uuid)));
    $stage='three bounded submissions';
    $transient=new SendResult(false,'smtp',retryable:true,failure_category:'connectivity',safe_to_retry:true);
    $provider->results=[$transient,$transient,$transient];$mail=$message();$check($service->own($mail));
    for($n=1;$n<=3;++$n){$before=time();$service->tick();$r=$row($mail->uuid);$check($n===(int)$r['attempt_count']);if($n<3){$check(strtotime($r['next_at'].' UTC')>=$before+($n===1?60:300));$wpdb->query($wpdb->prepare('UPDATE %i SET next_at=%s WHERE logical_uuid=%s',$tables[2],gmdate('Y-m-d H:i:s',time()-1),$mail->uuid));}}
    $check('failed'===$row($mail->uuid)['state']);$check(''===$row($mail->uuid)['payload']);$check(3===count($attempts->history($mail->uuid)));
    $stage='claim race, submission boundary and crash';
    $mail=$message();$check($service->own($mail));$claim=$queue->claim(time());$check(is_array($claim));$check(null===$queue->claim(time()));$check($queue->submitting($claim,time()));$check(!$queue->cancel($mail->uuid));
    $queue->sweep(time()+301);$check('review'===$row($mail->uuid)['state']);$check(''===$row($mail->uuid)['payload']);
    $stage='expiry and safe cancellation';
    $mail=$message();$check($service->own($mail));$queue->sweep(time()+86401);$check('expired'===$row($mail->uuid)['state']);$check(''===$row($mail->uuid)['payload']);
    $mail=$message();$check($service->own($mail));$check($queue->cancel($mail->uuid));$check(''===$row($mail->uuid)['payload']);
    $stage='edited revision cannot submit';
    $mail=$message();$check($service->own($mail));$check($settings->save(['smtp'=>['host'=>'changed.example.test','from_email'=>'sender@example.test']]));$before=$provider->calls;$service->tick();$check($before===$provider->calls);$check('configuration_changed'===$row($mail->uuid)['reason']);
    $stage='admin projection excludes private payload';
    $summaries=wp_json_encode($queue->summaries());$check(!str_contains($summaries,'payload'));$check(!str_contains($summaries,'recipient@example.test'));$check(!str_contains($summaries,'synthetic body'));
    $ok=true;
} catch(Throwable $error){fwrite(STDERR,'Recovery smoke failed at '.$stage.' assertion '.$assertions.' ('.get_class($error).")\n");}
finally {
    [$wpdb->prefix,$wpdb->options,$wp_object_cache,$current_user,$wp_filter]=$original;
    foreach($tables as $table){if(preg_match('/^scalyn_recovery_qa_[a-f0-9]{12}_(options|scalyn_submission_attempts|scalyn_recovery_queue)$/D',$table)){$cleanup=(false!==$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table)))&&$cleanup;}else{$cleanup=false;}}
    $wpdb->suppress_errors($previous);
}
echo $ok&&$cleanup?"PASS: {$assertions} recovery SQL assertions, repeatable schema, encrypted ownership, bounded attempts, claim isolation, crash review, expiry, cancellation and revision pinning; disposable tables removed; no real mail or settings changes.\n":"FAIL: recovery smoke or cleanup.\n";
exit($ok&&$cleanup?0:1);
