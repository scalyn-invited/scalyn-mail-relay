<?php
/**
 * Offline native add_option/print_error regression. No SQL, connection or network.
 * Usage: php local-key-marker-wpdb.php WORDPRESS_ROOT
 * Native option/cache control flow and wpdb suppression/display are unchanged;
 * preparation, helper/cache storage and driver outcomes are explicit mocked seams.
 */
define('ABSPATH', '/nonexistent/synthetic/');
$core = $argv[1];
require $core . '/wp-includes/class-wpdb.php';
require $core . '/wp-includes/option.php';
require dirname(__DIR__, 2) . '/includes/Core/Capabilities.php';
require dirname(__DIR__, 2) . '/includes/Core/LocalKeyReferenceRepository.php';
require dirname(__DIR__, 2) . '/includes/Core/LocalCredentialKeyStore.php';
function apply_filters($hook, $value, ...$args) { return $value; }
function do_action(...$args) {}
function __($text) { return $text; }
function sanitize_option($name, $value) { $GLOBALS['marker_value'] = $value; return $value; }
function maybe_serialize($value) { return serialize($value); }
function maybe_unserialize($value) { return unserialize($value, ['allowed_classes'=>false]); }
function wp_installing() { return false; }
function wp_cache_get($key, $group='', $force=false) { return $GLOBALS['cache'][$group][$key] ?? false; }
function wp_cache_set($key, $value, $group='') { $GLOBALS['cache'][$group][$key]=$value; return true; }
function wp_cache_add($key, $value, $group='') { return wp_cache_set($key,$value,$group); }
function get_current_blog_id() { return 1; }
function current_user_can($cap) { return true; }
function wp_load_translations_early() {}
function is_multisite() { return false; }
class MarkerRegressionDb extends wpdb {
    public array $write_policies=[];
    public array $readback_policies=[];
    public string $mode;
    public bool $written=false;
    public function __construct($mode) { $this->mode=$mode; $this->options='synthetic_options'; $this->prefix='synthetic_'; $this->ready=false; }
    public function prepare($sql, ...$args) { return $sql; }
    public function get_caller() { return 'synthetic_marker_regression'; }
    public function query($sql) {
        if (str_starts_with($sql,'SELECT ')) {
            $this->last_error=''; $this->last_result=[];
            if ($this->written) {
                $this->readback_policies[]=$this->suppress_errors;
                if ($this->mode==='readback-error') { $this->last_query=$sql; $this->last_error='SYNTHETIC_PRIVATE_DRIVER_DETAIL /private/synthetic'; $this->print_error($this->last_error); return false; }
                $this->last_result=[(object)['option_value'=>serialize($GLOBALS['marker_value'])]]; return 1;
            }
            return 0;
        }
        $this->write_policies[]=$this->suppress_errors;
        if ($this->mode==='throw') { throw new RuntimeException('SYNTHETIC_PRIVATE_DRIVER_DETAIL /private/synthetic SQL'); }
        if (in_array($this->mode,['success','readback-error'],true)) { $this->written=true; return 1; }
        $this->last_query=$sql; $this->last_error='SYNTHETIC_PRIVATE_DRIVER_DETAIL /private/synthetic'; $this->print_error($this->last_error); return false;
    }
}
$observed=[]; $failures=[];
foreach ([false,true] as $suppressed) foreach ([false,true] as $shown) foreach (['false','throw','readback-error','success'] as $mode) {
    $wpdb=new MarkerRegressionDb($mode); $wpdb->suppress_errors($suppressed); $wpdb->show_errors=$shown;
    $GLOBALS['cache']=['options'=>['alloptions'=>['synthetic_dummy'=>'unused'], 'notoptions'=>['scalyn_mail_relay_local_key_state'=>true]]];
    $scratch=sys_get_temp_dir().'/marker-regression-'.bin2hex(random_bytes(6)); mkdir($scratch,0700);
    $store=new Scalyn\MailRelay\Core\LocalCredentialKeyStore($scratch);
    $log=tmpfile(); $prior=ini_set('error_log',stream_get_meta_data($log)['uri']); ob_start(); $safe=false; $succeeded=false;
    try { $store->initialize_for_write(); $succeeded=true; }
    catch (RuntimeException $e) { $safe=$e->getPrevious()===null && $e->getMessage()==='Secure credential storage is unavailable. Restore the site key or check private storage permissions.'; }
    $output=ob_get_clean(); rewind($log); $logged=stream_get_contents($log); ini_set('error_log',$prior); fclose($log);
    $path=$store->path(); $body=file_get_contents($path); $inode=fileinode($path);
    // Repeated failed writes cannot replace or recreate the published key or write credentials.
    if ($mode!=='success') { $retrylog=tmpfile(); $prior=ini_set('error_log',stream_get_meta_data($retrylog)['uri']); ob_start(); try { $store->initialize_for_write(); } catch (RuntimeException $e) {} ob_end_clean(); ini_set('error_log',$prior); fclose($retrylog); }
    $unchanged=file_get_contents($path)===$body && fileinode($path)===$inode;
    $cache_ok=$mode!=='success' || (get_option(Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION)===$GLOBALS['marker_value'] && !isset($GLOBALS['cache']['options']['notoptions'][Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION]));
    $row=['prior_suppression'=>$suppressed,'show_errors'=>$shown,'mode'=>$mode,'write_suppression'=>$wpdb->write_policies,'readback_suppression'=>$wpdb->readback_policies,'safe_exception'=>$safe,'succeeded'=>$succeeded,'stdout_empty'=>$output==='','error_log_empty'=>$logged==='','driver_detail_in_output'=>str_contains($output,'SYNTHETIC_PRIVATE_DRIVER_DETAIL'),'driver_detail_in_log'=>str_contains($logged,'SYNTHETIC_PRIVATE_DRIVER_DETAIL'),'insert_sql_in_output'=>str_contains($output,'INSERT INTO'),'insert_sql_in_log'=>str_contains($logged,'INSERT INTO'),'private_path_in_output'=>str_contains($output,'/private/synthetic'),'private_path_in_log'=>str_contains($logged,'/private/synthetic'),'restored'=>$wpdb->suppress_errors===$suppressed,'show_errors_unchanged'=>$wpdb->show_errors===$shown,'published_key_unchanged'=>$unchanged,'native_cache_coherent'=>$cache_ok];
    if (!$row['stdout_empty'] || !$row['error_log_empty'] || !$row['restored'] || !$row['show_errors_unchanged'] || !$unchanged || !$cache_ok || in_array(false,$wpdb->write_policies,true) || in_array(false,$wpdb->readback_policies,true) || ($mode==='success' ? !$succeeded : !$safe)) $failures[]=$row;
    $observed[]=$row;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $f) { $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); } rmdir($scratch);
}
print json_encode(['checks'=>count($observed),'failures'=>$failures,'observed'=>$observed,'qualification'=>'Unmodified native WordPress add_option/get_option/print_error/suppress_errors; helper/cache storage/preparation/driver outcomes mocked; actual scratch filesystem; no SQL/network/credential write'],JSON_PRETTY_PRINT)."\n";
exit($failures?1:0);
