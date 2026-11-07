<?php
/**
 * Offline native WordPress option-cache/early-query regression; no SQL or connection.
 * Usage: php tests/integration/local-key-options-wpdb.php WP_ROOT [SOURCE_ROOT]
 * Preparation, helpers, cache and successful driver outcomes are explicit shims.
 */
$fixture = $argv[1] ?? '';
$root = $argv[2] ?? dirname(__DIR__, 2);
define('ABSPATH', '/nonexistent/synthetic/');
require $fixture.'/wp-includes/class-wpdb.php';
require $fixture.'/wp-includes/option.php';
require $root.'/includes/Core/LocalKeyReferenceRepository.php';
require $root.'/includes/Core/LocalCredentialKeyStore.php';
function apply_filters($hook,$value,...$args) {return ($GLOBALS['option_mode']??'')==='preoption-option' && $hook==='pre_option_scalyn_mail_relay_settings' ? [] : $value;}
function maybe_unserialize($value) {return is_string($value)?@unserialize($value,['allowed_classes'=>false]):$value;}
function wp_installing() {return false;}
function wp_cache_get($key,$group='',$force=false) {return $GLOBALS['cache'][$group][$key]??false;}
function wp_cache_set($key,$value,$group='') {$GLOBALS['cache'][$group][$key]=$value;return true;}
function wp_cache_add($key,$value,$group='') {if(isset($GLOBALS['cache'][$group][$key]))return false;return wp_cache_set($key,$value,$group);}
function get_current_blog_id() {return 1;}
function wp_load_translations_early() {}
function is_multisite() {return false;}
final class OfflineLocalOptionWpdb extends wpdb {
    public string $mode;
    public int $reads = 0;
    public int $writes = 0;
    public function __construct($mode) {$this->mode=$mode;$this->prefix='synthetic_';$this->options='synthetic_options';$this->ready=false;$this->check_current_query=false;}
    public function prepare($sql,...$args) {return $sql.' /* '.implode(' | ',$args).' */';}
    public function get_caller() {return 'offline_options_fixture';}
    public function query($sql) {
        if (!str_starts_with($sql,'SELECT ')) {++$this->writes;throw new RuntimeException('Unexpected mutation');}
        ++$this->reads;
        if (str_contains($sql,'SELECT option_value')) {
            if (in_array($this->mode,['early','early-stale'],true)) {return parent::query($sql);}
            if ($this->mode==='throw') {throw new RuntimeException('SYNTHETIC_PRIVATE_DETAIL');}
            if ($this->mode==='sql') {$this->last_query=$sql;$this->last_error='SYNTHETIC_PRIVATE_DETAIL';$this->print_error($this->last_error);return false;}
            $hit = (in_array($this->mode,['hidden-option','alloptions-option','preoption-option'],true) && str_contains($sql,'scalyn_mail_relay_settings')) || ($this->mode==='hidden-marker' && str_contains($sql,'scalyn_mail_relay_local_key_state'));
            $this->last_result=$hit?[(object)['option_value'=>serialize(['retained'=>'v2:synthetic'])]]:[];
            $this->last_error='';return $hit?1:0;
        }
        $this->last_error='';$this->last_result=[];return 0;
    }
}
$failures=[];$checks=0;
$names=['scalyn_mail_relay_settings','scalyn_mail_relay_postmark_webhook','scalyn_mail_relay_smtp2go_webhook','scalyn_mail_relay_brevo_webhook','scalyn_mail_relay_ai','scalyn_mail_relay_local_key_state'];
foreach (['early','early-stale','throw','sql','empty','hidden-option','hidden-marker','alloptions-option','preoption-option'] as $mode) {
 foreach ([false,true] as $suppressed) {
  foreach ([false,true] as $show) {
   $GLOBALS['cache']=['options'=>['alloptions'=>['synthetic_unrelated'=>'1'],'notoptions'=>array_fill_keys($names,true)]];
   $GLOBALS['option_mode']=$mode;
   if(in_array($mode,['early','early-stale','throw','sql'],true))$GLOBALS['cache']['options']['notoptions']=[];
   if($mode==='alloptions-option')$GLOBALS['cache']['options']['alloptions']['scalyn_mail_relay_settings']=serialize([]);
   $wpdb = new OfflineLocalOptionWpdb($mode);$wpdb->suppress_errors($suppressed);$wpdb->show_errors=$show;
   $wpdb->last_result=$mode==='early-stale'?[(object)['option_value'=>serialize([])]]:[];
   $scratch=sys_get_temp_dir().'/scalyn-native-options-'.bin2hex(random_bytes(8));mkdir($scratch,0700);
   $log=tmpfile();$prior=ini_set('error_log',stream_get_meta_data($log)['uri']);ob_start();
   $thrown=false;$has=null;
   try {
    try {$has=(new Scalyn\MailRelay\Core\LocalKeyReferenceRepository())->has_references();}
    catch (RuntimeException $error) {$thrown=$error->getPrevious()===null && $error->getMessage()==='Secure credential storage is unavailable. Restore the site key or check private storage permissions.';}
    $ready=(new Scalyn\MailRelay\Core\LocalCredentialKeyStore($scratch))->can_initialize();
    $output=ob_get_contents();rewind($log);$logged=stream_get_contents($log);
   } finally {ob_end_clean();ini_set('error_log',$prior);fclose($log);rmdir($scratch);}
   $valid=in_array($mode,['early','early-stale','throw','sql'],true)?($thrown && !$ready):(!$thrown && $has===in_array($mode,['hidden-option','alloptions-option','preoption-option'],true) && $ready===($mode==='empty'));
   $valid=$valid && $wpdb->suppress_errors===$suppressed && $wpdb->show_errors===$show && $output==='' && $logged==='' && $wpdb->writes===0;
   ++$checks;if(!$valid)$failures[]=$mode.':'.(int)$suppressed.':'.(int)$show;
  }
 }
}
print json_encode(['checks'=>$checks,'failures'=>$failures,'native_core_sha256'=>['wpdb'=>hash_file('sha256',$fixture.'/wp-includes/class-wpdb.php'),'option'=>hash_file('sha256',$fixture.'/wp-includes/option.php')],'qualification'=>'Native query early-false, option cache and suppress_errors/print_error; helpers/preparation/cache storage/success driver shims; no SQL or network'],JSON_PRETTY_PRINT)."\n";
exit($failures?1:0);
