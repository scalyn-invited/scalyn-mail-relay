<?php
// Explicit fourth-argument marker writer uses the mocked test add_option backend, not native wpdb.


use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\LocalCredentialKeyStore;

final class LocalCipherTest extends TestCase {
	private string $root;
	protected function setUp(): void {
		$this->root = sys_get_temp_dir().'/scalyn-cipher-fixture-'.bin2hex(random_bytes(8));
		mkdir($this->root,0700);
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_blog_id'] = 1;
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_SETTINGS=>true,Capabilities::MANAGE_MAIL=>true,'manage_options'=>true];
	}
	protected function tearDown(): void {
		if(is_dir($this->root)){
			$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
			foreach($iterator as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
			rmdir($this->root);
		}
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_wp_option_write_failures']=[];
		$GLOBALS['_test_current_user_can']=[];
		$GLOBALS['_test_blog_id']=1;
	}
	public function test_explicit_authorized_initialization_enables_local_v2_without_server_key(): void {
		$caught=null;$envelope=null;$cipher=null;
		try{
			$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
			$cipher=new CredentialCipher(null,$store);
			$this->assertFalse($cipher->is_available());
			$cipher->initialize_for_write();
			$envelope=$cipher->encrypt('synthetic-local-credential','smtp');
		}catch(Throwable $error){$caught=get_class($error);}
		$this->assertNull($caught,'Authorized first use must provision separate storage and protect credentials.');
		$this->assertStringStartsWith('v2:',$envelope);
		$this->assertSame('synthetic-local-credential',$cipher->decrypt($envelope,'smtp'));
		$this->assertTrue($cipher->is_available());
	}

	public function test_fresh_smtp_save_initializes_and_encrypts_without_manual_key_or_catalogue(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$settings=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
		$caught=null;
		try{
			$settings->save(['provider'=>['active'=>'smtp'],'smtp'=>[
				'host'=>'smtp.example.invalid','from_email'=>'sender@example.invalid',
				'username'=>'fixture-local-login','password'=>'fixture-local-password',
			]]);
		}catch(Throwable $error){$caught=get_class($error);}
		$this->assertNull($caught,'An authorized fresh SMTP save must initialize protection before encrypting.');
		$stored=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
		$this->assertStringStartsWith('v2:',$stored['smtp']['credentials_cipher']);
		$this->assertSame('',$stored['smtp']['username']);
		$this->assertSame('',$stored['smtp']['password']);
		$GLOBALS['_test_current_user_can']=[];
		$fresh=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
		$this->assertSame('fixture-local-password',$fresh->get_smtp_config()['password']);
		$this->assertArrayNotHasKey('username',$fresh->get_smtp_settings());
	}

	public function test_first_api_replacements_initialize_local_protection_for_each_provider(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$secret='synthetic-provider-key-1234567890';
		foreach(['sendgrid','postmark','smtp2go','brevo'] as $provider){
			$settings=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
			$caught=null;
			try{
				$settings->{'save_'.$provider}(['key_action'=>'replace','api_key'=>$secret,'from_email'=>'sender@example.invalid','from_name'=>'Fixture'],$cipher);
			}catch(Throwable $error){$caught=get_class($error);}
			$this->assertNull($caught,'An authorized first provider replacement must initialize local protection: '.$provider);
			$stored=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
			$this->assertStringStartsWith('v2:',$stored[$provider]['key_cipher']);
			$this->assertSame($secret,$cipher->decrypt($stored[$provider]['key_cipher'],$provider));
			$this->assertFalse(str_contains(wp_json_encode($stored),$secret));
		}
	}

	public function test_sender_only_edit_keeps_local_envelope_when_external_write_override_is_invalid(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$local=new CredentialCipher(null,$store);
		$settings=new \Scalyn\MailRelay\Core\SettingsRepository($local);
		$settings->save(['provider'=>['active'=>'smtp'],'smtp'=>['host'=>'old.example.invalid','from_email'=>'sender@example.invalid','username'=>'fixture-login','password'=>'fixture-password']]);
		$before=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
		$external_invalid=new CredentialCipher('invalid',$store);
		$edited=new \Scalyn\MailRelay\Core\SettingsRepository($external_invalid);
		$caught=null;
		try{$edited->save(['smtp'=>['host'=>'new.example.invalid','from_email'=>'sender@example.invalid','username'=>'','password'=>'']]);}
		catch(Throwable $error){$caught=get_class($error);}
		$this->assertNull($caught,'An unchanged readable local credential must not be re-encrypted under a different write source.');
		$after=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
		$this->assertTrue(hash_equals($before['smtp']['credentials_cipher'],$after['smtp']['credentials_cipher']));
		$this->assertSame('new.example.invalid',$edited->get_smtp_config()['host']);
		$this->assertSame('fixture-password',$edited->get_smtp_config()['password']);
		$this->assertNotSame($before['diagnostic_revision'],$after['diagnostic_revision']);
	}

	public function test_authorized_import_protects_legacy_login_without_changing_its_revision(): void {
		$revision='11111111-1111-4111-8111-111111111111';
		$GLOBALS['_test_wp_options'][\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY]=[
			'provider'=>['active'=>'smtp','verified'=>true], 'diagnostic_revision'=>$revision,
			'smtp'=>['host'=>'legacy.example.invalid','from_email'=>'sender@example.invalid','username'=>'legacy-fixture-login','password'=>'legacy-fixture-password'],
		];
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$settings=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
		$this->assertTrue($settings->configure('import'));
		$stored=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
		$this->assertFalse(str_contains(wp_json_encode($stored),'legacy-fixture-password'),'Explicit import must not preserve new plaintext copies.');
		$this->assertStringStartsWith('v2:',$stored['smtp']['credentials_cipher']);
		$this->assertSame($revision,$settings->get_diagnostic_revision());
		$this->assertSame('legacy-fixture-password',$settings->get_smtp_config()['password']);
		$this->assertFalse($stored['configurations']['revisions'][$revision]['legacy']);
	}

	public function test_readable_local_envelope_import_is_not_legacy_under_invalid_external_override(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$local=new CredentialCipher(null,$store);
		$settings=new \Scalyn\MailRelay\Core\SettingsRepository($local);
		$settings->save(['provider'=>['active'=>'smtp'],'smtp'=>['host'=>'smtp.example.invalid','from_email'=>'sender@example.invalid','username'=>'fixture-login','password'=>'fixture-password']]);
		$before=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
		$reader=new \Scalyn\MailRelay\Core\SettingsRepository(new CredentialCipher('invalid',$store));
		$this->assertTrue($reader->configure('import'));
		$after=get_option(\Scalyn\MailRelay\Core\SettingsRepository::OPTION_KEY);
		$revision=$reader->get_diagnostic_revision();
		$this->assertFalse($after['configurations']['revisions'][$revision]['legacy']);
		$this->assertTrue(hash_equals($before['smtp']['credentials_cipher'],$after['configurations']['revisions'][$revision]['config']['credentials_cipher']));
		$this->assertSame('fixture-password',$reader->get_smtp_config()['password']);
	}

	public function test_status_encrypt_and_decrypt_do_not_initialize_storage(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$this->assertFalse($cipher->is_available());
		$this->assertTrue($cipher->can_initialize());
		foreach([static fn()=>$cipher->encrypt('fixture','smtp'),static fn()=>$cipher->decrypt('v2:invalid','smtp')] as $operation){
			$thrown=false;ob_start();try{$operation();}catch(RuntimeException $error){$thrown=true;}finally{$output=ob_get_clean();}
			$this->assertTrue($thrown);$this->assertSame('',$output);
		}
		$this->assertSame(['.','..'],scandir($this->root));
		$this->assertSame([],$GLOBALS['_test_wp_options']);
	}

	public function test_all_contexts_round_trip_with_source_binding_and_no_external_fallback(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$local=new CredentialCipher(null,$store);$local->initialize_for_write();
		$external=new CredentialCipher(base64_encode(str_repeat('e',32)),$store);
		$contexts=['sendgrid','postmark','smtp2go','brevo','smtp','postmark-webhook','smtp2go-webhook','brevo-webhook','delivery-matching','recovery-payload','ai-openai','ai-gemini','ai-claude','ai-session'];
		foreach($contexts as $context){
			$v2=$local->encrypt('synthetic-'.$context,$context);
			$v1=$external->encrypt('synthetic-'.$context,$context);
			$this->assertStringStartsWith('v2:',$v2);$this->assertStringStartsWith('v1:',$v1);
			$this->assertSame('synthetic-'.$context,$external->decrypt($v2,$context));
			$this->assertSame('synthetic-'.$context,$external->decrypt($v1,$context));
			$thrown=false;try{$local->decrypt($v1,$context);}catch(RuntimeException $error){$thrown=true;}
			$this->assertTrue($thrown,'A v1 envelope must never try the local key.');
			$thrown=false;try{$external->decrypt('v1:'.substr($v2,3),$context);}catch(RuntimeException $error){$thrown=true;}
			$this->assertTrue($thrown,'Changing envelope source/version must fail authentication.');
		}
	}

	public function test_local_cipher_rejects_wrong_context_tampering_and_cross_site_even_with_copied_key(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);$cipher->initialize_for_write();
		$sealed=$cipher->encrypt('synthetic-credential','smtp');
		$binary=base64_decode(substr($sealed,3),true);$binary[28]=chr(ord($binary[28])^1);
		foreach([[$sealed,'sendgrid'],['v2:'.base64_encode($binary),'smtp']] as [$envelope,$context]){
			$thrown=false;try{$cipher->decrypt($envelope,$context);}catch(RuntimeException $error){$thrown=true;}
			$this->assertTrue($thrown);
		}
		$original=$store->path();$state=get_option(LocalCredentialKeyStore::STATE_OPTION);
		$GLOBALS['_test_blog_id']=2;
		copy($original,$store->path());chmod($store->path(),0600);
		$state['blog_id']=2;$GLOBALS['_test_wp_options'][LocalCredentialKeyStore::STATE_OPTION]=$state;
		$this->assertTrue($store->is_available(),'Copied fixture key must load so this tests site AAD, not missing-file rejection.');
		$thrown=false;try{$cipher->decrypt($sealed,'smtp');}catch(RuntimeException $error){$thrown=true;}
		$this->assertTrue($thrown,'Site identity must be authenticated even if raw key files are copied.');
	}

	public function test_missing_key_never_regenerates_and_invalid_create_never_initializes(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$settings=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
		foreach([['unsupported','',''],['create','not-a-provider','Valid'],['create','smtp','']] as [$op,$id,$name]){
			$thrown=false;try{$settings->configure($op,$id,$name);}catch(InvalidArgumentException $error){$thrown=true;}
			$this->assertTrue($thrown);$this->assertSame(['.','..'],scandir($this->root));
		}
		$cipher->initialize_for_write();$sealed=$cipher->encrypt('synthetic','smtp');
		$before=get_option(LocalCredentialKeyStore::STATE_OPTION);unlink($store->path());
		foreach([static fn()=>$cipher->decrypt($sealed,'smtp'),static fn()=>$cipher->initialize_for_write()] as $operation){
			$thrown=false;try{$operation();}catch(RuntimeException $error){$thrown=true;}
			$this->assertTrue($thrown);$this->assertFalse(file_exists($store->path()));
			$this->assertSame($before,get_option(LocalCredentialKeyStore::STATE_OPTION));
		}
	}

	public function test_first_ai_profile_save_initializes_storage_without_enabling_chat(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$ai=new \Scalyn\MailRelay\AI\AiSettings($cipher);
		$key='sk-synthetic_fixture_not_real_123456789';$caught=null;
		$previous=$GLOBALS['wpdb']??null;
		$GLOBALS['wpdb']=new class {public string $prefix='wp_';public function insert(string $table,array $data,array $format): int{return 1;}};
		try{$ai->save(['model'=>'gpt-4.1-mini','key'=>$key]);}catch(Throwable $error){$caught=get_class($error);}finally{$GLOBALS['wpdb']=$previous;}
		$this->assertNull($caught,'Authorized first AI credential write must initialize local protection.');
		$stored=get_option(\Scalyn\MailRelay\AI\AiSettings::OPTION);
		$this->assertStringStartsWith('v2:',$stored['profiles']['openai']['key']);
		$this->assertSame($key,$ai->credential('openai'));
		$this->assertFalse($ai->summary()['enabled']);
		$this->assertFalse(str_contains(wp_json_encode($stored),$key));
	}

	public function test_first_disabled_webhook_drafts_initialize_protection_without_enabling_collection(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$cipher=new CredentialCipher(null,$store);
		$settings=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
		$GLOBALS['_test_wp_nonce_valid']=true;
		$previous=$GLOBALS['wpdb']??null;
		$GLOBALS['wpdb']=new class {public string $prefix='wp_';public function insert(string $table,array $data,array $format): int{return 1;}};
		try{
			foreach(['postmark','smtp2go','brevo'] as $provider){
				$service=$provider==='postmark'?new \Scalyn\MailRelay\Core\PostmarkWebhookSettings($cipher,null,$settings):new \Scalyn\MailRelay\Core\ApiWebhookSettings($cipher,$provider,null,$settings);
				$input=['credential_action'=>'replace','allowed_ips'=>['192.0.2.1'],'password'=>str_repeat('fixture_',6)];
				if($provider==='postmark')$input+=['server_id'=>1,'stream'=>'outbound','username'=>str_repeat('fixture_',3)];
				$caught=null;$saved=false;try{$saved=$service->save($input,'valid');}catch(Throwable $error){$caught=get_class($error);}
				$this->assertNull($caught,'First protected draft write should initialize storage: '.$provider);
				$this->assertTrue($saved);
				$draft=get_option('scalyn_mail_relay_'.$provider.'_webhook');
				$this->assertStringStartsWith('v2:',$draft['credentials']);
				$this->assertFalse($draft['enabled']);
				$this->assertFalse(str_contains(wp_json_encode($draft),$input['password']));
			}
		}finally{$GLOBALS['wpdb']=$previous;$GLOBALS['_test_wp_nonce_valid']=false;}
	}

	public function test_ai_setup_readiness_is_separate_from_existing_runtime_key_availability(): void {
		$store=new LocalCredentialKeyStore($this->root,static fn()=>false,static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$ai=new \Scalyn\MailRelay\AI\AiSettings(new CredentialCipher(null,$store));
		$summary=$ai->summary();
		$this->assertFalse($summary['encryption']);
		$this->assertTrue($summary['encryption_setup']??false,'A first authorized key save must remain reachable without pretending a key already exists.');
		$this->assertSame(['.','..'],scandir($this->root));
		$this->assertSame([],$GLOBALS['_test_wp_options']);
	}

	public function test_key_and_private_option_parameters_are_redacted_from_exception_traces(): void {
		foreach([[LocalCredentialKeyStore::class,'marker'],[LocalCredentialKeyStore::class,'persist'],[\Scalyn\MailRelay\Core\LocalKeyReferenceRepository::class,'contains']] as [$class,$method]){
			$parameter=(new ReflectionMethod($class,$method))->getParameters()[0];
			$this->assertCount(1,$parameter->getAttributes(SensitiveParameter::class),'Private key/option arguments require native trace redaction: '.$method);
		}
	}

	public function test_missing_openssl_does_not_offer_or_initialize_a_key(): void {
		$script=<<<'PHP'
function extension_loaded(string $name): bool {return 'openssl'!==$name && in_array($name,get_loaded_extensions(),true);}
require $argv[1];
$GLOBALS['_test_wp_options']=[];
$GLOBALS['_test_current_user_can']=[\Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS=>true];
$store=new \Scalyn\MailRelay\Core\LocalCredentialKeyStore($argv[2],static fn()=>false,static fn()=>get_option(\Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION,null), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
$cipher=new \Scalyn\MailRelay\Core\CredentialCipher(null,$store);
$state=['available'=>$cipher->is_available(),'setup'=>$cipher->can_initialize(),'initialized'=>false];
try {$cipher->initialize_for_write();$state['initialized']=true;} catch (Throwable $error) {}
$state['file']=is_file($store->path());
$state['marker']=null!==get_option(\Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION,null);
print json_encode($state);
PHP;
		$process=proc_open([PHP_BINARY,'-n','-d','disable_functions=extension_loaded','-r',$script,dirname(__DIR__).'/bootstrap.php',$this->root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
		$this->assertIsResource($process);
		$output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
		fclose($pipes[1]);fclose($pipes[2]);
		$this->assertSame(0,proc_close($process),$error);
		$this->assertSame(['available'=>false,'setup'=>false,'initialized'=>false,'file'=>false,'marker'=>false],json_decode($output,true));
	}
}
