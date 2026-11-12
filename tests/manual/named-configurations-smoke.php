<?php
/** CLI-only WordPress option/database smoke; disposable options table, no mail. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,5).'/wp-load.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
if (wp_using_ext_object_cache()) { fwrite(STDERR,"Blocked: this disposable-options smoke requires WordPress's process-local cache. No test writes performed.\n"); exit(1); }

use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\Capabilities;

global $wpdb, $wp_object_cache, $current_user, $wp_filter;
$original_table=$wpdb->options;
$original_cache=$wp_object_cache;
$original_user=$current_user;
$original_audit=$wp_filter[HookNames::AUDIT_EVENT] ?? null;
$table='scalyn_config_qa_'.bin2hex(random_bytes(6)).'_options';
$ok=false; $cleanup=true; $stage='setup';
$assertion=0;
$check=static function(bool $value) use (&$assertion):void { ++$assertion; if(!$value) throw new RuntimeException('Fixture assertion'); };
$previous=$wpdb->suppress_errors(true);
try {
	$check(false!==$wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i',$table,$original_table)));
	$wpdb->options=$table;
	// Isolate option caches and observers; never flush the site's real cache.
	$wp_object_cache=new WP_Object_Cache();
	remove_all_actions(HookNames::AUDIT_EVENT);
	$current_user=new WP_User(); $current_user->ID=999999919;
	$current_user->allcaps=['manage_options'=>true,Capabilities::MANAGE_SETTINGS=>true];
	$cipher=new CredentialCipher(base64_encode(str_repeat('q',32)));
	$stage='legacy import';
	$settings=new SettingsRepository($cipher);
	$check($settings->save(['provider'=>['active'=>'smtp'],'smtp'=>['host'=>'first.example.test','from_email'=>'sender@example.test','username'=>'synthetic_login','password'=>'synthetic_password']]));
	$revision=$settings->get_diagnostic_revision();
	$check($settings->configure('import'));
	$check($revision===$settings->get_diagnostic_revision());
	$first=$settings->get_active_configuration_id();
	$check($settings->configure('import'));
	$check(1===count($settings->get_configurations()));
	$stage='same-provider isolation';
	$check($settings->configure('create','smtp','Secondary'));
	$items=$settings->get_configurations(); $second=$items[1]['id'];
	$check($first===$settings->get_active_configuration_id());
	$check($settings->configure('select',$second));
	$check(''===$settings->get_smtp_config()['password']);
	$check($settings->save(['smtp'=>['host'=>'second.example.test','from_email'=>'sender@example.test','username'=>'second_login','password'=>'second_password']]));
	$second_revision=$settings->get_diagnostic_revision();
	$check($settings->configure('select',$first));
	$check($revision===$settings->get_diagnostic_revision());
	$check('synthetic_password'===$settings->get_smtp_config()['password']);
	$stage='rename and archive';
	$check($settings->configure('rename',$first,'Default'));
	$check($revision===$settings->get_diagnostic_revision());
	$check($settings->configure('archive',$second));
	$stage='fresh-process representation';
	$fresh=new SettingsRepository($cipher);
	$check($first===$fresh->get_active_configuration_id());
	$stored=get_option(SettingsRepository::OPTION_KEY);
	$check(isset($stored['configurations']['revisions'][$second_revision]));
	$check(!str_contains(wp_json_encode($stored),'synthetic_password'));
	$check(!str_contains(wp_json_encode($stored),'second_password'));
	$lock_rows=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name=%s',$table,SettingsRepository::OPTION_KEY.'_write_lock'));
	$check(0===$lock_rows);
	$stage='stale-writer rejection';
	$check($settings->configure('rename',$first,'Latest'));
	try { $fresh->configure('rename',$first,'Stale'); throw new LogicException('Expected conflict'); }
	catch(RuntimeException $error) { $check(str_contains($error->getMessage(),'another request')); }
	$check('Latest'===(new SettingsRepository($cipher))->get_configurations()[0]['name']);
	$ok=true;
} catch(Throwable $error) {
	fwrite(STDERR,'Named configuration smoke failed at '.$stage.' assertion '.$assertion.' ('.get_class($error).")\n");
} finally {
	$wpdb->options=$original_table;
	$wp_object_cache=$original_cache;
	$current_user=$original_user;
	if(null!==$original_audit) $wp_filter[HookNames::AUDIT_EVENT]=$original_audit;
	if(preg_match('/^scalyn_config_qa_[a-f0-9]{12}_options$/D',$table)) $cleanup=false!==$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table));
	$wpdb->suppress_errors($previous);
}
echo $ok && $cleanup ? "PASS: real WordPress import, selection, revisions, encrypted storage, archiving and stale-writer rejection; disposable table removed; no mail or real settings changed.\n" : "FAIL: smoke test or cleanup.\n";
exit($ok && $cleanup ? 0 : 1);
