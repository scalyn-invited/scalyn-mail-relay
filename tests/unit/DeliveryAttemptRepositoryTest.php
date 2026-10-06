<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;

final class DeliveryAttemptDb extends WpdbStub {
	public array $attempts=[];
	public array $members=[];
	public array $keys=[];
	public array $snapshot=[];
	public string $last_error='';
	public int $failInsert=0;
	public bool $failCommit=false;
	public int $engines=3;
	public function get_var(string $query): mixed {
		$args=end($this->prepare_calls)['args'];
		if(str_contains($query,'information_schema')) { return $this->engines; }
		if(str_contains($query,'SELECT key_version')) { return isset($this->keys[$args[1]]) ? $args[1] : null; }
		if(str_contains($query,'SELECT key_envelope')) { return $this->keys[$args[1]] ?? null; }
		if(str_contains($query,'SELECT recipient_token')) { return in_array($args[2],$this->members[$args[1]] ?? [],true) ? $args[2] : null; }
		if(str_contains($query,'SELECT provider_message_id')) {
			$r=$this->attempts[$args[1]] ?? null;
			return $r && $r['source_id']===$args[2] && $r['provider']===$args[3] ? $r['provider_message_id'] : null;
		}
		return null;
	}
	public function insert(string $table,array $data,mixed $format=null): int|false {
		if($this->failInsert && count($this->inserts)+1===$this->failInsert) { return false; }
		if(str_ends_with($table,'delivery_keys')) { $this->keys[$data['key_version']]=$data['key_envelope']; }
		elseif(str_ends_with($table,'delivery_attempts')) {
			if(isset($this->attempts[$data['message_uuid']])) { return false; }
			$this->attempts[$data['message_uuid']]=$data+['provider_message_id'=>null];
		} else { $this->members[$data['message_uuid']][]=$data['recipient_token']; }
		return parent::insert($table,$data,$format);
	}
	public function query(string $query): int|false {
		$this->queries[]=$query;
		if($query==='START TRANSACTION') { $this->snapshot=[$this->attempts,$this->members]; }
		if($query==='ROLLBACK') { [$this->attempts,$this->members]=$this->snapshot; }
		if($query==='COMMIT' && $this->failCommit) { return false; }
		if(str_starts_with($query,'UPDATE')) {
			$a=end($this->prepare_calls)['args']; $r=$this->attempts[$a[2]] ?? null;
			if(!$r || $r['source_id']!==$a[3] || $r['provider']!==$a[4] || ($r['provider_message_id']!==null && $r['provider_message_id']!==$a[1])) { return 0; }
			$this->attempts[$a[2]]['provider_message_id']=$a[1];
		}
		return 1;
	}
	public function get_results(string $query,string $output=OBJECT): array {
		$a=end($this->prepare_calls)['args'];
		return array_slice(array_values(array_filter($this->attempts,static fn($r)=>$r['source_id']===$a[1] && $r['provider']===$a[2] && $r[$a[3]]===$a[4] && $r['created_at'] >= $a[5])),0,2);
	}
}

final class DeliveryAttemptRepositoryTest extends TestCase {
	private const ID='12345678-1234-4234-8234-123456789abc';
	private const OTHER='22345678-1234-4234-8234-123456789abc';
	private DeliveryAttemptDb $db;
	private DeliveryAttemptRepository $repo;
	private DeliveryKeyRepository $keys;
	private string $version;
	protected function setUp(): void {
		$GLOBALS['wpdb']=$this->db=new DeliveryAttemptDb();
		$this->repo=new DeliveryAttemptRepository();
		$this->keys=new DeliveryKeyRepository(new CredentialCipher(base64_encode(str_repeat('k',32))));
		$this->version=$this->keys->provision();
	}
	protected function tearDown(): void { unset($GLOBALS['wpdb']); }
	public function test_expanded_providers_are_scoped_and_preserve_identifier_case(): void {
		foreach (['smtp2go'=>'AbCd-12345','brevo'=>'<UpperCase@relay.test>'] as $p=>$id) {
			$this->setUp();
			$t=$this->keys->token($this->version,self::ID,self::ID,'User@example.com');
			$this->repo->prepare(['message_uuid'=>self::ID,'source_id'=>self::ID,'configuration_id'=>self::OTHER,'key_version'=>$this->version],[$t],$p);
			$this->assertNotNull($this->repo->resolve(self::ID,$id,self::ID,'User@example.com','2000-01-01 00:00:00',$this->keys,$p));
			$this->assertNull($this->repo->resolve(self::ID,self::OTHER,self::ID,'User@example.com','2000-01-01 00:00:00',$this->keys,'postmark'));
			$this->repo->acknowledge(self::ID,self::ID,$id,$p);
			$this->assertSame($id,$this->db->attempts[self::ID]['provider_message_id']);
			$this->assertNull($this->repo->resolve(self::ID,strtolower($id),self::ID,'User@example.com','2000-01-01 00:00:00',$this->keys,$p));
			$this->assertNull($this->repo->resolve(self::ID,$id,self::ID,'stranger@example.com','2000-01-01 00:00:00',$this->keys,$p));
		}
	}
	private function prepare(): string {
		$t=$this->keys->token($this->version,self::ID,self::ID,'User@example.com');
		$this->repo->prepare(['message_uuid'=>self::ID,'source_id'=>self::ID,'configuration_id'=>self::OTHER,'key_version'=>$this->version],[$t,$t]);
		return $t;
	}
	private function resolve(?string $hint=self::ID,string $address='User@example.com',string $source=self::ID,string $message=self::OTHER): ?array {
		return $this->repo->resolve($source,$message,$hint,$address,'2000-01-01 00:00:00',$this->keys);
	}
	public function test_membership_is_deduplicated_and_correlation_can_precede_acknowledgement(): void {
		$t=$this->prepare();
		$this->assertSame(1,$this->db->attempts[self::ID]['expected_recipients']);
		$this->assertSame([$t],$this->db->members[self::ID]);
		$this->assertSame(['message_uuid'=>self::ID,'recipient_token'=>$t],$this->resolve());
		$this->assertNull($this->resolve(null));
		$this->assertNull($this->resolve(self::ID,'user@example.com'));
		$this->assertNull($this->resolve(self::ID,'User@example.com',self::OTHER));
		$this->assertStringNotContainsString('User@example.com',serialize($this->db->inserts));
		$this->assertStringNotContainsString('User@example.com',serialize($this->db->prepare_calls));
		$this->assertFalse($this->db->errors_suppressed);
	}
	public function test_acknowledgement_is_append_only_and_fallback_requires_unique_match(): void {
		$this->prepare(); $this->repo->acknowledge(self::ID,self::ID,self::OTHER);
		$this->assertNotNull($this->resolve(null));
		$this->assertNull($this->resolve(self::ID,'User@example.com',self::ID,self::ID));
		$this->repo->acknowledge(self::ID,self::ID,self::OTHER);
		try { $this->repo->acknowledge(self::ID,self::ID,self::ID); $this->fail('Must not overwrite'); }
		catch(RuntimeException $e) { $this->assertSame('Delivery acknowledgement could not be stored.',$e->getMessage()); }
		$this->assertSame(self::OTHER,$this->db->attempts[self::ID]['provider_message_id']);
		$this->db->attempts[self::OTHER]=$this->db->attempts[self::ID];
		$this->db->attempts[self::OTHER]['message_uuid']=self::OTHER;
		$this->assertNull($this->resolve(null));
	}
	public function test_expired_missing_and_wrong_provider_attempts_never_match(): void {
		$this->assertNull($this->resolve());
		$this->prepare();
		$this->db->attempts[self::ID]['created_at']='1999-01-01 00:00:00';
		$this->assertNull($this->resolve());
		$this->db->attempts[self::ID]['created_at']='2026-10-02 00:00:00';
		$this->db->attempts[self::ID]['provider']='sendgrid';
		$this->assertNull($this->resolve());
	}
	public function test_failed_membership_or_commit_rolls_back_and_hides_internal_error(): void {
		foreach (['insert','commit'] as $failure) {
			$this->db->failInsert=$failure==='insert' ? count($this->db->inserts)+2 : 0;
			$this->db->failCommit=$failure==='commit';
			try { $this->prepare(); $this->fail('Must roll back'); }
			catch(RuntimeException $e) { $this->assertSame('Delivery association could not be stored.',$e->getMessage()); $this->assertNull($e->getPrevious()); }
			$this->assertSame([],$this->db->attempts);
			$this->assertSame([],$this->db->members);
			$this->assertSame('ROLLBACK',end($this->db->queries));
			$this->assertFalse($this->db->errors_suppressed);
		}
	}
	public function test_rebinding_an_existing_attempt_fails_without_modifying_it(): void {
		$this->prepare(); $before=$this->db->attempts;
		try { $this->prepare(); $this->fail('Must reject duplicate association'); }
		catch(RuntimeException $e) { $this->assertSame($before,$this->db->attempts); }
		$this->db->keys=[];
		try { $this->resolve(); $this->fail('Must fail closed without key'); }
		catch(RuntimeException $e) { $this->assertSame('Delivery correlation is unavailable.',$e->getMessage()); }
	}
	public function test_nontransactional_storage_cannot_start_collection(): void {
		$this->db->engines=2;
		try { $this->prepare(); $this->fail('Must reject'); }
		catch(RuntimeException $e) { $this->assertSame([],$this->db->queries); }
		$this->assertSame([],$this->db->attempts);
	}
}
