<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\{SettingsRepository,CredentialCipher,Capabilities,ProviderRegistry};
use Scalyn\MailRelay\Mail\{MailMessage,SendResult,MailDispatcher,RoutingRepository};
use Scalyn\MailRelay\Recovery\{RecoveryPayload,RecoveryService,RecoveryPolicyRepository};
use Scalyn\MailRelay\Database\{RecoveryQueueRepository,SubmissionAttemptRepository};
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Providers\{ConnectionResult,ValidationResult};

final class MemoryRecoveryQueue extends RecoveryQueueRepository {
	public ?array $row=null;
	public array $outcomes=[];
	public bool $writeFailure=false;
	public bool $claimFailure=false;
	public int $sweeps=0;
	public function enqueue(string $logical,string $payload,int $now):void {
		if($this->writeFailure){throw new RuntimeException('private simulated storage failure');}
		$this->row=['logical_uuid'=>$logical,'payload'=>$payload,'attempt_count'=>0,'expires_at'=>gmdate('Y-m-d H:i:s',$now+86400),'next_at'=>gmdate('Y-m-d H:i:s',$now),'state'=>'pending'];
	}
	public function claim(int $now):?array {
		if($this->claimFailure || !$this->row || $this->row['state']!=='pending' || strtotime($this->row['next_at'].' UTC')>$now){return null;}
		$this->row['state']='running'; $this->row['claim_token']=wp_generate_uuid4(); return $this->row;
	}
	public function submitting(array $row,int $now):bool {
		if($this->row['state']!=='running'){return false;} $this->row['state']='submitting'; ++$this->row['attempt_count']; return true;
	}
	public function finish(array $row,string $state,string $reason,int $now,int $next_at=0):void {
		$this->outcomes[]=[$state,$reason]; $this->row['state']=$state; $this->row['next_at']=gmdate('Y-m-d H:i:s',$next_at ?: $now); if($state!=='pending'){$this->row['payload']='';}
	}
	public function sweep(int $now):void { ++$this->sweeps; }
}
final class MemorySubmissionAttempts extends SubmissionAttemptRepository {
	public array $records=[];
	public bool $fail=false;
	public function begin(string $logical,string $attempt,int $number,string $provider,?string $revision,string $origin):void {
		if($this->fail){throw new RuntimeException('simulated evidence failure');}
		$this->records[]=['logical'=>$logical,'attempt'=>$attempt,'number'=>$number,'revision'=>$revision];
	}
	public function finish(string $attempt,SendResult $result):void {}
}
final class RecoveryServiceTest extends TestCase {
	private SettingsRepository $settings;
	private CredentialCipher $cipher;
	private MemoryRecoveryQueue $queue;
	private MemorySubmissionAttempts $attempts;
	private ProviderInterface $provider;
	private RecoveryService $service;
	protected function setUp():void {
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.13.0',RecoveryPolicyRepository::OPTION=>['enabled'=>true,'token'=>'']];
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true,'manage_options'=>true];
		$GLOBALS['_test_wp_added_actions']=[];
		$this->cipher=new CredentialCipher(base64_encode(str_repeat('q',32)));
		$this->settings=new SettingsRepository($this->cipher);
		$this->settings->save(['provider'=>['active'=>'smtp'],'smtp'=>['host'=>'smtp.example.test','from_email'=>'sender@example.test','username'=>'login','password'=>'private-secret']]);
		$this->settings->configure('import');
		$this->queue=new MemoryRecoveryQueue(); $this->attempts=new MemorySubmissionAttempts();
		$this->provider=new class implements ProviderInterface {
			public array $results=[]; public array $calls=[];
			public function get_id():string{return 'smtp';}
			public function get_label():string{return 'Offline synthetic SMTP';}
			public function get_capabilities():array{return [];}
			public function validate_config(array $config):ValidationResult{return new ValidationResult(true);}
			public function test_connection(array $config):ConnectionResult{return new ConnectionResult(true);}
			public function send(MailMessage $message,array $config):SendResult {
				$this->calls[]=['uuid'=>$message->uuid,'host'=>$config['host'],'body'=>$message->body];
				return array_shift($this->results) ?? new SendResult(true,'smtp');
			}
		};
		$registry=new ProviderRegistry(); $registry->register($this->provider);
		$this->service=new RecoveryService($this->queue,new RecoveryPolicyRepository(),new RecoveryPayload($this->cipher),new MailDispatcher($registry,$this->settings,null,$this->attempts),$this->settings);
	}
	protected function tearDown():void {$GLOBALS['_test_wp_options']=[];$GLOBALS['_test_current_user_can']=[];$GLOBALS['_test_wp_added_actions']=[];}
	private function message():MailMessage {return new MailMessage(wp_generate_uuid4(),'sender@example.test',['recipient@example.test'],'private subject','private body', 'text/plain');}
	private function transient():SendResult {return new SendResult(false,'smtp',retryable:true,failure_category:'connectivity',safe_to_retry:true);}
	public function test_ownership_is_durable_but_not_acceptance_and_storage_is_encrypted():void {
		$this->assertTrue($this->service->own($this->message()));
		$this->assertSame('pending',$this->queue->row['state']); $this->assertSame([],$this->provider->calls);
		foreach(['private body','recipient@example.test','private-secret'] as $private){$this->assertStringNotContainsString($private,$this->queue->row['payload']);}
	}
	public function test_acceptance_stops_and_erases_payload():void {
		$this->service->own($this->message()); $this->service->tick(); $this->service->tick();
		$this->assertCount(1,$this->provider->calls);$this->assertSame('accepted',$this->queue->row['state']);$this->assertSame('',$this->queue->row['payload']);
	}
	public function test_unknown_and_permanent_never_resubmit():void {
		foreach([new SendResult(false,'smtp',failure_category:'auth'),new SendResult(false,'smtp',acceptance_unconfirmed:true),new SendResult(false,'smtp',retryable:true,failure_category:'timeout')] as $result){
			$this->provider->results=[$result];$this->service->own($this->message());$this->service->tick();$before=count($this->provider->calls);$this->service->tick();$this->assertCount($before,$this->provider->calls);$this->assertSame('',$this->queue->row['payload']);
		}
	}
	public function test_transient_maximum_three_attempts_with_minimum_delays_and_unique_correlation():void {
		$logical=$this->message();$this->provider->results=[$this->transient(),$this->transient(),$this->transient()];$this->service->own($logical);
		$before=time();$this->service->tick();$this->assertGreaterThanOrEqual($before+60,strtotime($this->queue->row['next_at'].' UTC'));
		$this->service->tick();$this->assertCount(1,$this->provider->calls);
		$this->queue->row['next_at']=gmdate('Y-m-d H:i:s',time()-1);$this->service->tick();$this->assertGreaterThanOrEqual($before+300,strtotime($this->queue->row['next_at'].' UTC'));
		$this->queue->row['next_at']=gmdate('Y-m-d H:i:s',time()-1);$this->service->tick();$this->service->tick();
		$this->assertCount(3,$this->provider->calls);$this->assertSame('failed',$this->queue->row['state']);
		$this->assertCount(3,array_unique(array_column($this->attempts->records,'attempt')));
		$this->assertSame([$logical->uuid],array_values(array_unique(array_column($this->attempts->records,'logical'))));
	}
	public function test_revision_edit_and_missing_attempt_storage_stop_before_transport():void {
		$this->service->own($this->message());$this->settings->save(['smtp'=>['host'=>'changed.example.test','from_email'=>'sender@example.test']]);$this->service->tick();
		$this->assertSame([],$this->provider->calls);$this->assertSame('configuration_changed',$this->queue->outcomes[0][1]);
		$this->service->own($this->message());$this->attempts->fail=true;$this->service->tick();$this->assertSame([],$this->provider->calls);$this->assertSame('review',$this->queue->row['state']);
	}
	public function test_disabled_queue_still_sweeps_but_never_sends():void {
		$this->service->own($this->message());$GLOBALS['_test_wp_options'][RecoveryPolicyRepository::OPTION]['enabled']=false;$this->service->tick();
		$this->assertSame([],$this->provider->calls);$this->assertSame(1,$this->queue->sweeps);$this->assertSame('pending',$this->queue->row['state']);
	}
	public function test_storage_failure_never_acknowledges_ownership():void {$this->queue->writeFailure=true;$this->assertFalse($this->service->own($this->message()));$this->assertSame([],$this->provider->calls);}
	public function test_expired_claim_stops_before_transport():void {$this->service->own($this->message());$this->queue->row['expires_at']=gmdate('Y-m-d H:i:s',time()-1);$this->service->tick();$this->assertSame([],$this->provider->calls);}
	public function test_payload_is_bound_to_its_logical_identity():void {
		$payload=new RecoveryPayload($this->cipher);$message=$this->message();$sealed=$payload->seal($message,[$this->settings->configuration_target($this->settings->get_active_configuration_id())],'unknown/default');
		$this->expectException(RuntimeException::class);$payload->open($sealed,wp_generate_uuid4());
	}
	public function test_private_attachment_copy_restores_name_bytes_and_deletes_temporary_file():void {
		$handle=tmpfile();$path=stream_get_meta_data($handle)['uri'];fwrite($handle,'private attachment');
		$payload=new RecoveryPayload($this->cipher);$message=$this->message();$mail=new MailMessage($message->uuid,$message->from,$message->to,$message->subject,$message->body,attachments:[$path],attachment_names:['invoice.txt']);
		$sealed=$payload->seal($mail,[$this->settings->configuration_target($this->settings->get_active_configuration_id())],'unknown/default');fclose($handle);
		$handles=[];try{$restored=$payload->message($payload->open($sealed,$message->uuid),wp_generate_uuid4(),1,$handles);$this->assertSame('invoice.txt',$restored->attachment_names[0]);$restoredPath=$restored->attachments[0];$this->assertSame('private attachment',file_get_contents($restoredPath));}finally{foreach($handles as $item){fclose($item);}}
		$this->assertFileDoesNotExist($restoredPath);
	}
	public function test_enabling_requires_explicit_consent_and_checks_stale_token():void {
		$policy=new RecoveryPolicyRepository();try{$policy->save(true,false,'',$this->settings,$this->cipher);$this->fail();}catch(RuntimeException $error){}
		$policy->save(true,true,'',$this->settings,$this->cipher);$this->assertTrue($policy->policy()['enabled']);
		$this->expectException(RuntimeException::class);$policy->save(false,false,'',$this->settings,$this->cipher);
	}
}
