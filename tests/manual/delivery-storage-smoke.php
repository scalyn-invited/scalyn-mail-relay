<?php
/** CLI-only disposable-table integration check; never sends or enables tracking. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__,5) . '/wp-load.php';

use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Database\DeliveryEvidenceSchema;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;
use Scalyn\MailRelay\Database\DeliveryEventRepository;
use Scalyn\MailRelay\Database\DeliveryRetentionRepository;

global $wpdb;
$originalPrefix=$wpdb->prefix;
$oldSuppression=$wpdb->suppress_errors(true);
$testPrefix='scalyn_qa_'.bin2hex(random_bytes(6)).'_';
$tables=[];
foreach(array_keys(DeliveryEvidenceSchema::definitions()) as $suffix) { $tables[]=$testPrefix.$suffix; }
$tables[]=$testPrefix.'scalyn_mail_timeline';
$passed=false;
$cleanup=true;
function delivery_smoke_assert(bool $condition): void {
	if(!$condition) { throw new RuntimeException('Delivery storage assertion failed.'); }
}
function delivery_smoke_race(string $prefix,string $attempt): void {
	global $wpdb;
	$children=[]; $outputs=['','']; $locked=false;
	try {
		delivery_smoke_assert(false!==$wpdb->query('START TRANSACTION'));
		$locked=true;
		delivery_smoke_assert($attempt===$wpdb->get_var($wpdb->prepare('SELECT message_uuid FROM %i WHERE message_uuid=%s FOR UPDATE',$prefix.'scalyn_delivery_attempts',$attempt)));
		for($i=0;$i<2;$i++) {
			$pipes=[];
			$stdout=tempnam(sys_get_temp_dir(),'scalyn_delivery_qa_');
			$stderr=tempnam(sys_get_temp_dir(),'scalyn_delivery_qa_');
			delivery_smoke_assert(is_string($stdout) && is_string($stderr));
			// File-backed output avoids blocking Windows anonymous pipe reads.
			$process=proc_open([PHP_BINARY,__DIR__.'/delivery-concurrency-worker.php',$prefix],[0=>['pipe','r'],1=>['file',$stdout,'w'],2=>['file',$stderr,'w']],$pipes,null,null,['bypass_shell'=>true,'create_no_window'=>true]);
			$children[]=[$process,$stdout,$stderr];
			delivery_smoke_assert(is_resource($process));
			fclose($pipes[0]);
		}
		$deadline=microtime(true)+15;
		do {
			foreach($children as $i=>[$process,$stdout,$stderr]) { $outputs[$i]=file_get_contents($stdout); }
			$ready=str_contains($outputs[0],'READY') && str_contains($outputs[1],'READY');
			if(!$ready) { usleep(20000); }
		} while(!$ready && microtime(true)<$deadline);
		delivery_smoke_assert($ready);
		// Both workers are ready; the retained attempt is still locked by the parent.
		delivery_smoke_assert(false!==$wpdb->query('COMMIT')); $locked=false;
		do {
			$running=false;
			foreach($children as $i=>[$process,$stdout,$stderr]) {
				$outputs[$i]=file_get_contents($stdout);
				$running=proc_get_status($process)['running'] || $running;
			}
			if($running) { usleep(20000); }
		} while($running && microtime(true)<$deadline);
		delivery_smoke_assert(!$running);
		foreach($children as $i=>[$process,$stdout,$stderr]) { $outputs[$i]=file_get_contents($stdout); delivery_smoke_assert(file_get_contents($stderr)===''); }
		$results=array_map(static fn($text)=>trim(str_replace('READY','',$text)),$outputs); sort($results);
		delivery_smoke_assert($results===['duplicate','stored']);
	} finally {
		if($locked) { $wpdb->query('ROLLBACK'); }
		foreach($children as [$process,$stdout,$stderr]) {
			if(is_resource($process)) {
				if(proc_get_status($process)['running']) { proc_terminate($process); }
				proc_close($process);
			}
			foreach([$stdout,$stderr] as $path) {
				if(str_starts_with(basename($path),'scalyn_delivery_qa_') && realpath(dirname($path))===realpath(sys_get_temp_dir())) { unlink($path); }
			}
		}
	}
}
try {
	$wpdb->prefix=$testPrefix;
	DeliveryEvidenceSchema::migrate();
	DeliveryEvidenceSchema::migrate();
	DeliveryEvidenceSchema::migrate_key_retirement();
	DeliveryEvidenceSchema::migrate_key_retirement();
	$wpdb->query($wpdb->prepare('CREATE TABLE %i (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, message_uuid char(36) NOT NULL, event_type varchar(50), event_status varchar(32), event_label varchar(191), event_message text, event_data longtext, created_at datetime) ENGINE=InnoDB',$testPrefix.'scalyn_mail_timeline'));
	delivery_smoke_assert(empty($wpdb->last_error));
	$keys=new DeliveryKeyRepository(new CredentialCipher(base64_encode(random_bytes(32))));
	$repo=new DeliveryAttemptRepository();
	$version=$keys->provision();
	$source=wp_generate_uuid4(); $attempt=wp_generate_uuid4(); $config=wp_generate_uuid4(); $provider=wp_generate_uuid4();
	$token=$keys->token($version,$source,$attempt,'Synthetic+test@EXAMPLE.com');
	$association=['message_uuid'=>$attempt,'source_id'=>$source,'configuration_id'=>$config,'key_version'=>$version];
	$repo->prepare($association,[$token,$token]);
	$cutoff=gmdate('Y-m-d H:i:s',time()-86400);
	$match=$repo->resolve($source,$provider,$attempt,'Synthetic+test@example.com',$cutoff,$keys);
	delivery_smoke_assert($match===['message_uuid'=>$attempt,'recipient_token'=>$token]);
	delivery_smoke_assert($repo->resolve($source,$provider,null,'Synthetic+test@example.com',$cutoff,$keys)===null);
	$repo->acknowledge($source,$attempt,$provider);
	delivery_smoke_assert($repo->resolve($source,$provider,null,'Synthetic+test@example.com',$cutoff,$keys)===$match);
	delivery_smoke_assert($repo->resolve($source,$provider,$attempt,'synthetic+test@example.com',$cutoff,$keys)===null);
	delivery_smoke_assert($repo->resolve(wp_generate_uuid4(),$provider,$attempt,'Synthetic+test@example.com',$cutoff,$keys)===null);
	$rejected=false;
	try { $repo->prepare($association,[$token]); } catch(RuntimeException $error) { $rejected=true; }
	delivery_smoke_assert($rejected);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_attempts'))===1);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_recipients'))===1);
	$events=new DeliveryEventRepository();
	$at=gmdate('Y-m-d\TH:i:s').'.000000Z';
	$event=['schema_version'=>1,'source_id'=>$source,'provider'=>'postmark','message_uuid'=>$attempt,'provider_message_id'=>$provider,'event_key'=>str_repeat('e',64),'kind'=>'delivery','recipient_token'=>$token,'occurred_at'=>$at,'received_at'=>$at,'authentication_method'=>'postmark_basic_tls','reason_code'=>null];
	delivery_smoke_assert($events->append($event,$cutoff)==='stored');
	delivery_smoke_assert($events->append($event,$cutoff)==='duplicate');
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_mail_timeline'))===1);
	$wpdb->query($wpdb->prepare("CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic QA failure'",$testPrefix.'reject_timeline',$testPrefix.'scalyn_mail_timeline'));
	delivery_smoke_assert(empty($wpdb->last_error));
	$bounce=array_replace($event,['event_key'=>str_repeat('f',64),'kind'=>'bounce','reason_code'=>'hard_bounce']);
	$rejected=false;
	try { $events->append($bounce,$cutoff); } catch(RuntimeException $error) { $rejected=true; }
	delivery_smoke_assert($rejected);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_events'))===1);
	$wpdb->query($wpdb->prepare('DROP TRIGGER %i',$testPrefix.'reject_timeline'));
	delivery_smoke_assert($events->append($bounce,$cutoff)==='stored');
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_mail_timeline'))===2);
	delivery_smoke_race($testPrefix,$attempt);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_events'))===3);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_mail_timeline'))===3);
	// Force failure after the attempt INSERT: recipient membership must roll back.
	$second=wp_generate_uuid4();
	// A trigger is scoped to this disposable table only and contains no user data.
	$wpdb->query($wpdb->prepare("CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic QA failure'",$testPrefix.'reject_member',$testPrefix.'scalyn_delivery_recipients'));
	delivery_smoke_assert(empty($wpdb->last_error));
	$rejected=false;
	try { $repo->prepare(array_replace($association,['message_uuid'=>$second]),[$token]); } catch(RuntimeException $error) { $rejected=true; }
	delivery_smoke_assert($rejected);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_attempts'))===1);
	$wpdb->query($wpdb->prepare('DROP TRIGGER %i',$testPrefix.'reject_member'));
	$keys->retire($version);
	$keys->retire($version);
	delivery_smoke_assert($keys->token($version,$source,$attempt,'Synthetic+test@example.com')===$token);
	delivery_smoke_assert($keys->prune_retired()===0);
	$rejected=false;
	try { $repo->prepare(array_replace($association,['message_uuid'=>$second]),[$token]); } catch(RuntimeException $error) { $rejected=true; }
	delivery_smoke_assert($rejected);
	$unused=$keys->provision();
	$wpdb->update($testPrefix.'scalyn_delivery_attempts',['created_at'=>'2000-01-01 00:00:00'],['message_uuid'=>$attempt]);
	$wpdb->insert($testPrefix.'scalyn_mail_timeline',['message_uuid'=>$attempt,'event_type'=>'mail_sent','event_status'=>'accepted','event_label'=>'Accepted','created_at'=>gmdate('Y-m-d H:i:s')]);
	$retention=new DeliveryRetentionRepository();
	$wpdb->query($wpdb->prepare("CREATE TRIGGER %i BEFORE DELETE ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic QA failure'",$testPrefix.'reject_delete',$testPrefix.'scalyn_delivery_recipients'));
	delivery_smoke_assert(empty($wpdb->last_error));
	$rejected=false;
	try { $retention->delete_expired_batch('2001-01-01 00:00:00'); } catch(RuntimeException $error) { $rejected=true; }
	delivery_smoke_assert($rejected);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_events'))===3);
	$wpdb->query($wpdb->prepare('DROP TRIGGER %i',$testPrefix.'reject_delete'));
	delivery_smoke_assert($retention->delete_expired_batch('2001-01-01 00:00:00')===1);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_events'))===0);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_recipients'))===0);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_mail_timeline'))===1);
	delivery_smoke_assert($events->append($event,$cutoff)==='ignored');
	delivery_smoke_assert($keys->prune_retired()===1);
	delivery_smoke_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$testPrefix.'scalyn_delivery_keys'))===1);
	$passed=true;
} catch(Throwable $error) {
	// Never echo SQL, credentials, addresses, envelopes or raw exceptions.
	fwrite(STDERR,"Delivery storage smoke check failed.\n");
} finally {
	foreach(array_reverse($tables) as $table) {
		if(!preg_match('/^scalyn_qa_[a-f0-9]{12}_scalyn_(delivery_(keys|attempts|recipients|events)|mail_timeline)$/D',$table) || strpos($table,$testPrefix)!==0) { $cleanup=false; continue; }
		if(false===$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table))) { $cleanup=false; }
	}
	$wpdb->prefix=$originalPrefix;
	$wpdb->suppress_errors($oldSuppression);
}
echo $cleanup ? "Disposable QA tables removed.\n" : "QA table cleanup incomplete; inspect the scalyn_qa_ tables.\n";
if($passed && $cleanup) { echo "PASS: real database migration, encryption, exact correlation, concurrent duplicates, rollback, retention and safe key retirement. No mail or live settings changed.\n"; }
exit($passed && $cleanup ? 0 : 1);
