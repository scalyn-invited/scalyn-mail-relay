<?php
/** CLI-only real-dbDelta smoke test. Disposable plugin tables only; no mail. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('SHORTINIT',true);define('WP_ADMIN',true);
require dirname(__DIR__,5).'/wp-load.php';
require_once ABSPATH.'wp-includes/theme.php';
require_once ABSPATH.'wp-includes/pomo/translations.php';
require_once ABSPATH.'wp-includes/l10n.php';
require_once ABSPATH.'wp-includes/class-wp-walker.php';
require dirname(__DIR__,2).'/vendor/autoload.php';
use Scalyn\MailRelay\Database\DeliverabilitySchema;
use Scalyn\MailRelay\Database\DeliverabilityRepository;
global $wpdb;
$original=$wpdb->prefix;$prefix='scalyn_score_qa_'.bin2hex(random_bytes(6)).'_';
$tables=[$prefix.'scalyn_mail_logs',$prefix.'scalyn_deliverability_evidence'];
$previous=$wpdb->suppress_errors(true);$ok=false;$cleanup=true;$stage='setup';
$ready=static fn()=>'0.11.0';$check=static function(bool $v):void{if(!$v){throw new RuntimeException('Fixture assertion');}};
try {
 $check(false!==$wpdb->query($wpdb->prepare('CREATE TABLE %i (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, configuration_id char(36) NULL, provider varchar(100), status varchar(20), created_at datetime, KEY configuration_created(configuration_id,created_at,id)) ENGINE=InnoDB',$tables[0])));
 $wpdb->prefix=$prefix;add_filter('pre_option_scalyn_mail_relay_db_version',$ready);
 $stage='migration';DeliverabilitySchema::migrate();DeliverabilitySchema::migrate();
 $rev='11111111-1111-4111-8111-111111111111';$other='22222222-2222-4222-8222-222222222222';$now=time();$stamp=wp_date('Y-m-d H:i:s',$now);
 for($n=0;$n<20;$n++){$check(false!==$wpdb->insert($tables[0],['configuration_id'=>$rev,'provider'=>'smtp','status'=>'accepted','created_at'=>$stamp]));}
 foreach([[$other,'smtp',$stamp],[$rev,'brevo',$stamp],[null,'smtp',$stamp],[$rev,'smtp','2099-01-01 00:00:00'],[$rev,'smtp','2000-01-01 00:00:00']] as [$r,$p,$t]){$check(false!==$wpdb->insert($tables[0],['configuration_id'=>$r,'provider'=>$p,'status'=>'failed','created_at'=>$t]));}
 $repo=new DeliverabilityRepository();$stage='attribution';$counts=$repo->submissions($rev,'smtp',30,$now);$check(['accepted'=>20,'failed'=>0]===$counts);
 $checks=[];foreach(['spf_record','dkim_record','dmarc_policy','mx_record','reverse_dns'] as $name){$checks[$name]=['status'=>'pass','checked_at'=>$now];}
 $stage='trace replay';$saved=$repo->persist_score($rev,'smtp',$rev,['evaluated_at'=>$now,'checks'=>$checks,'alignment'=>'possible_with_configured_selector','transport'=>'smtp','submissions'=>$counts,'delivery'=>['status'=>'unsupported'],'raw'=>'private']);
 $check(92===$saved['score']);$check($saved===$repo->latest($rev,'smtp'));$check(null===$repo->latest($other,'smtp'));$check(null===$repo->latest($rev,'brevo'));$check(!str_contains(json_encode($saved),'private'));
 $stage='minimal verdicts';$repo->persist_header($rev,'smtp',['verdicts'=>['spf'=>'pass','dkim'=>'pass','dmarc'=>'pass'],'alignment'=>['spf'=>'strict','dkim'=>'yes'],'raw'=>'private@example.com']);
 $payload=$wpdb->get_var($wpdb->prepare("SELECT payload FROM %i WHERE kind='header' LIMIT 1",$tables[1]));$check(!str_contains($payload,'private'));$check(!str_contains($payload,'example.com'));$check('strict'===json_decode($payload,true)['alignment']['spf']);
 $stage='bounded retention';$check(false!==$wpdb->query($wpdb->prepare('UPDATE %i SET created_at=%s',$tables[1],'2000-01-01 00:00:00')));$check(2===$repo->delete_expired('2026-01-01 00:00:00'));$check(null===$repo->latest($rev,'smtp'));$ok=true;
}catch(Throwable $error){echo 'FAIL stage: '.$stage."\n";}finally{
 remove_filter('pre_option_scalyn_mail_relay_db_version',$ready);$wpdb->prefix=$original;
 foreach($tables as $table){if(preg_match('/^scalyn_score_qa_[a-f0-9]{12}_scalyn_(mail_logs|deliverability_evidence)$/D',$table)){$cleanup=(false!==$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table)))&&$cleanup;}}
 $wpdb->suppress_errors($previous);
}
echo $ok&&$cleanup?"PASS: idempotent schema, exact revision/provider history, score replay, minimal verdicts, retention; disposable tables removed.\n":"FAIL: smoke test or cleanup.\n";
exit($ok&&$cleanup?0:1);
