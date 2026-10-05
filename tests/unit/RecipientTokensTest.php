<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Delivery\RecipientTokens;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;

final class RecipientTokensTest extends TestCase {
	private const ID='12345678-1234-4234-8234-123456789abc';
	private const OTHER='22345678-1234-4234-8234-123456789abc';
	protected function tearDown(): void { unset($GLOBALS['wpdb']); }
	private function token(string $address): string { return RecipientTokens::create(str_repeat('a',32),self::ID,self::ID,self::ID,$address); }
	public function test_case_tags_dots_scope_and_versions_are_not_guessed(): void {
		$token=$this->token('User+tag@Example.COM');
		$this->assertSame($token,$this->token('User+tag@example.com'));
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$token);
		foreach (['user+tag@example.com','User@example.com','U.ser+tag@example.com'] as $address) { $this->assertNotSame($token,$this->token($address)); }
		foreach ([['version',self::OTHER],['source',self::OTHER],['attempt',self::OTHER]] as [$field,$id]) {
			$ids=['version'=>self::ID,'source'=>self::ID,'attempt'=>self::ID]; $ids[$field]=$id;
			$this->assertNotSame($token,RecipientTokens::create(str_repeat('a',32),$ids['version'],$ids['source'],$ids['attempt'],'User+tag@example.com'));
		}
	}
	public function test_invalid_inputs_are_safe(): void {
		foreach (['Name <secret@example.com>',"secret@example.com\r\nBcc: leak@example.com",'invalid',' user@example.com'] as $address) {
			try { $this->token($address); $this->fail('Must reject'); }
			catch (InvalidArgumentException $e) { $this->assertSame('Recipient matching inputs are invalid.',$e->getMessage()); }
		}
		$this->expectException(InvalidArgumentException::class);
		RecipientTokens::create('short',self::ID,self::ID,self::ID,'user@example.com');
	}
	public function test_provisioning_encrypts_random_key_and_reads_exact_version_only(): void {
		$db=$GLOBALS['wpdb']=new WpdbStub();
		$cipher=new CredentialCipher(base64_encode(str_repeat('b',32)));
		$repo=new DeliveryKeyRepository($cipher);
		$version=$repo->provision();
		$this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/',$version);
		$row=$db->inserts[0]['data'];
		$this->assertSame($version,$row['key_version']);
		$this->assertSame(['key_version','key_envelope','created_at'],array_keys($row));
		$this->assertStringStartsWith('v1:',$row['key_envelope']);
		$db->get_var_return=$row['key_envelope'];
		$token=$repo->token($version,self::ID,self::ID,'User@Example.com');
		$this->assertSame($token,$repo->token($version,self::ID,self::ID,'User@example.com'));
		$this->assertCount(1,$db->inserts);
		// Swapping ciphertext into another version must not derive a token.
		try { $repo->token(self::OTHER,self::ID,self::ID,'private@example.com'); $this->fail('Must reject'); }
		catch (RuntimeException $e) { $this->assertSame('Recipient matching is unavailable.',$e->getMessage()); $this->assertNull($e->getPrevious()); }
	}
	public function test_missing_corrupt_wrong_context_and_lost_root_keys_do_not_self_heal(): void {
		$db=$GLOBALS['wpdb']=new WpdbStub();
		$cipher=new CredentialCipher(base64_encode(str_repeat('b',32)));
		$repo=new DeliveryKeyRepository($cipher);
		foreach ([null,'bad',$cipher->encrypt('not a matching key','postmark'),$cipher->encrypt('{"version":"bad"}','delivery-matching')] as $envelope) {
			$db->get_var_return=$envelope;
			try { $repo->token(self::ID,self::ID,self::ID,'private@example.com'); $this->fail('Must reject'); }
			catch(RuntimeException $e) { $this->assertSame('Recipient matching is unavailable.',$e->getMessage()); }
		}
		$this->assertSame([],$db->inserts);
		$bad=new DeliveryKeyRepository(new CredentialCipher('unavailable'));
		try { $bad->provision(); $this->fail('Must reject'); }
		catch(RuntimeException $e) { $this->assertSame('Recipient matching key could not be provisioned.',$e->getMessage()); }
		$this->assertSame([],$db->inserts);
		$db->return_false_on_insert=true;
		$this->expectException(RuntimeException::class);
		$repo->provision();
	}
}
