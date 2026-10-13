<?php
/** CLI-only: real dbDelta against disposable tables; no plugin boot, mail or settings writes. */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
define('SHORTINIT',true);
define('WP_ADMIN',true);
require dirname(__DIR__,5).'/wp-load.php';
require_once ABSPATH . 'wp-includes/theme.php';
require_once ABSPATH . 'wp-includes/pomo/translations.php';
require_once ABSPATH . 'wp-includes/l10n.php';
require_once ABSPATH . 'wp-includes/class-wp-walker.php';
require dirname(__DIR__,2).'/vendor/autoload.php';
use Scalyn\MailRelay\Database\FailureCategorySchema;
use Scalyn\MailRelay\Database\ConnectionEvidenceRepository;
use Scalyn\MailRelay\Logging\MailLogRepository;

global $wpdb;
$original=$wpdb->prefix;$prefix='scalyn_category_qa_'.bin2hex(random_bytes(6)).'_';
$tables=[$prefix.'scalyn_mail_logs',$prefix.'scalyn_connection_evidence'];
$old=$wpdb->suppress_errors(true);$ok=false;$cleanup=true;$stage='setup';
$ready=static fn()=>'0.10.0';
$check=static function(bool $result):void{if(!$result){throw new RuntimeException('Fixture assertion');}};
try {
 // Regular disposable tables: dbDelta introspection must exercise the real upgrade path.
 $check(false!==$wpdb->query($wpdb->prepare('CREATE TABLE %i (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, configuration_id char(36) NULL, provider varchar(100), status varchar(20), created_at datetime, KEY configuration_created(configuration_id,created_at,id)) ENGINE=InnoDB',$tables[0])));
 $check(false!==$wpdb->query($wpdb->prepare('CREATE TABLE %i (configuration_id char(36),provider varchar(100),status varchar(16),started_at datetime(6),checked_at datetime(6),PRIMARY KEY(configuration_id,provider)) ENGINE=InnoDB',$tables[1])));
 $rev='11111111-1111-4111-8111-111111111111';$other='22222222-2222-4222-8222-222222222222';
 $now=current_time('mysql');$utc='2026-10-06 00:00:00.000000';
 $check(false!==$wpdb->insert($tables[0],['configuration_id'=>$rev,'provider'=>'smtp','status'=>'failed','created_at'=>$now]));
 $wpdb->prefix=$prefix;
 $stage='migration';
 FailureCategorySchema::migrate();FailureCategorySchema::migrate();
 $legacy=$wpdb->get_row($wpdb->prepare('SELECT failure_category FROM %i WHERE id=1',$tables[0]),ARRAY_A);
 $check(is_array($legacy) && array_key_exists('failure_category',$legacy) && null===$legacy['failure_category']);
 add_filter('pre_option_scalyn_mail_relay_db_version',$ready);
 $stage='atomic connection categories';
 $repo=new ConnectionEvidenceRepository();
 $repo->record($rev,'smtp','failed',$utc,$utc,'auth');
 $repo->record($rev,'smtp','passed','2026-10-05 00:00:00.000000',$utc);
 $check('auth'===$repo->find($rev,'smtp')['failure_category']);
 $repo->record($rev,'smtp','passed','2026-10-07 00:00:00.000000','2026-10-07 00:00:00.000000');
 $check(!isset($repo->find($rev,'smtp')['failure_category']));
 $stage='sequence and scope';
 foreach(['auth','config','auth'] as $category) {$check(false!==$wpdb->insert($tables[0],['configuration_id'=>$rev,'provider'=>'smtp','status'=>'failed','created_at'=>$now,'failure_category'=>$category]));}
 // Other revisions/providers and future/old rows must not break the current streak.
 foreach([[$other,'smtp',$now],[$rev,'brevo',$now],[$rev,'smtp','2099-01-01 00:00:00'],[$rev,'smtp','2000-01-01 00:00:00']] as [$r,$p,$t]) {$check(false!==$wpdb->insert($tables[0],['configuration_id'=>$r,'provider'=>$p,'status'=>'accepted','created_at'=>$t]));}
 $logs=new MailLogRepository();$counts=$logs->configuration_failure_evidence($rev,'smtp');
 $check(3===$counts['auth_config_streak'] && 1===$counts['unclassified']);
 $check(false!==$wpdb->insert($tables[0],['configuration_id'=>$rev,'provider'=>'smtp','status'=>'prepared','created_at'=>$now]));
 $check(0===$logs->configuration_failure_evidence($rev,'smtp')['auth_config_streak']);
 foreach(['rate-limit','provider-rejection'] as $category) {$check(false!==$wpdb->insert($tables[0],['configuration_id'=>$rev,'provider'=>'smtp','status'=>'failed','created_at'=>$now,'failure_category'=>$category]));}
 $counts=$logs->configuration_failure_evidence($rev,'smtp');
 $check(1===$counts['rate_limits'] && 1===$counts['provider_rejections']);
 $ok=true;
} catch(Throwable $error) {
 // Fixed stage label only; no SQL or connection details.
 echo get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n";
} finally {
 remove_filter('pre_option_scalyn_mail_relay_db_version',$ready);$wpdb->prefix=$original;
 foreach($tables as $table) {
  if(preg_match('/^scalyn_category_qa_[a-f0-9]{12}_scalyn_(mail_logs|connection_evidence)$/D',$table)) {
   $cleanup=(false!==$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table))) && $cleanup;
  }
 }
 $wpdb->suppress_errors($old);
}
echo $ok && $cleanup ? "PASS: idempotent migration, legacy NULL, atomic categories, scoped sequence and uncertainty; disposable tables removed.\n" : "FAIL: $stage or cleanup.\n";
exit($ok && $cleanup?0:1);
