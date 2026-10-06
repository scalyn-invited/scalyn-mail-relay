<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\{ApiWebhookSettings,SettingsRepository,CredentialCipher,Capabilities};
use Scalyn\MailRelay\Database\{DeliveryKeyRepository,DeliveryAttemptRepository,DeliveryEventRepository,WebhookRateLimitRepository};
use Scalyn\MailRelay\Rest\ApiWebhookEndpoint;

final class ApiReceiverDb extends WpdbStub {
 public string $options='wp_options';
 public string $last_error='';
 public string $envelope='';
 public string $member='';
 public array $attempt=[];
 public ?array $event=null;
 public bool $failCommit=false;
 public function get_var(string $query): mixed {
  if(str_contains($query,'GET_LOCK')||str_contains($query,'RELEASE_LOCK'))return 1;
  if(str_contains($query,'FLOOR'))return 10000;
  if(str_contains($query,'option_value'))return null;
  if(str_contains($query,'information_schema'))return 4;
  if(str_contains($query,'key_envelope'))return $this->envelope;
  if(str_contains($query,'recipient_token'))return $this->member;
  return null;
 }
 public function get_results(string $query,string $output=OBJECT):array {return [$this->attempt];}
 public function get_row(string $query,string $output=OBJECT):array|object|null {return str_contains($query,'SELECT provider_message_id')?['provider_message_id'=>$this->attempt['provider_message_id']]:$this->event;}
 public function insert(string $table,array $data,mixed $format=null):int|false {if(str_ends_with($table,'delivery_events'))$this->event=$data;return parent::insert($table,$data,$format);}
 public function query(string $query):int|false {if($query==='COMMIT' && $this->failCommit)return false;return parent::query($query);}
}

/** Simulated full receiver path through the actual repositories, not live provider proof. */
final class ApiWebhookEndpointTest extends TestCase {
 private const ID='11111111-1111-4111-8111-111111111111';
 protected function tearDown():void {unset($GLOBALS['wpdb'],$GLOBALS['_test_is_ssl'],$_SERVER['REMOTE_ADDR']);$GLOBALS['_test_current_user_can']=[];}
 public function test_authenticated_events_commit_deduplicate_and_do_not_acknowledge_uncertain_commit():void {
  foreach(['smtp2go'=>'abc123-def456','brevo'=>'<CaseSensitive@relay.test>'] as $provider=>$messageId) {
   $GLOBALS['_test_wp_options']=[];$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>true];$GLOBALS['_test_wp_nonce_valid']=true;
   $GLOBALS['_test_is_ssl']=true;$_SERVER['REMOTE_ADDR']='192.0.2.1';
   $cipher=new CredentialCipher(base64_encode(str_repeat('a',32)));
   $GLOBALS['wpdb']=$db=new ApiReceiverDb();
   $db->envelope=$cipher->encrypt(json_encode(['version'=>self::ID,'key'=>base64_encode(str_repeat('k',32))]),'delivery-matching');
   $db->attempt=['message_uuid'=>self::ID,'provider_message_id'=>null,'key_version'=>self::ID];
   $keys=new DeliveryKeyRepository($cipher);
   $db->member=$keys->token(self::ID,self::ID,self::ID,'private@example.com');
   $secret=str_repeat('s',40);
   $GLOBALS['_test_wp_options']['scalyn_mail_relay_'.$provider.'_webhook']=['id'=>self::ID,'enabled'=>true,'credentials'=>$cipher->encrypt(json_encode(['password'=>$secret]),$provider.'-webhook'),'allowed_ips'=>['192.0.2.1']];
   $endpoint=new ApiWebhookEndpoint(new ApiWebhookSettings($cipher,$provider),new SettingsRepository($cipher),new WebhookRateLimitRepository(),new DeliveryAttemptRepository(),$keys,new DeliveryEventRepository());
   $body=$provider==='smtp2go'?['event'=>'delivered','email_id'=>$messageId,'time'=>gmdate('Y-m-d H:i:s'),'rcpt'=>'private@example.com','X-Scalyn-Message-UUID'=>self::ID,'auth'=>'DO_NOT_STORE_KEY']:['event'=>'delivered','message-id'=>$messageId,'ts_event'=>time(),'email'=>'private@example.com','X-Mailin-custom'=>self::ID];
   $r=new WP_REST_Request();$r->set_param('source',self::ID);$r->set_header('Authorization','Bearer '.$secret);$r->set_header('Content-Type','application/json');$r->set_body(json_encode($body));
   $response=$endpoint->handle($r);
   $this->assertSame(200,$response->get_status());$this->assertNull($response->get_data());
   $this->assertCount(2,$db->inserts);$this->assertSame($provider,$db->inserts[0]['data']['provider']);
   foreach(['private@example.com','DO_NOT_STORE_KEY',$secret] as $s){$this->assertStringNotContainsString($s,json_encode($db->inserts));}
   $this->assertSame(200,$endpoint->handle($r)->get_status());$this->assertCount(2,$db->inserts);
   $db->event=null;$db->failCommit=true;
   $this->assertSame(503,$endpoint->handle($r)->get_status());
   $this->assertSame('ROLLBACK',end($db->queries));
   $calls=array_values(array_filter($db->prepare_calls,fn($c)=>str_contains($c['query'],'LIMIT 2')));
   $this->assertSame($provider,$calls[0]['args'][2]);
  }
 }
}
