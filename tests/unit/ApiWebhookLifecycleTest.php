<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\{ApiWebhookSettings,PostmarkWebhookSettings,SettingsRepository,CredentialCipher,Capabilities};
use Scalyn\MailRelay\Database\{DeliveryKeyRepository,DeliveryAttemptRepository,DeliveryEventRepository,WebhookRateLimitRepository};
use Scalyn\MailRelay\Delivery\DeliveryTracker;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
use Scalyn\MailRelay\Rest\ApiWebhookEndpoint;
use Scalyn\MailRelay\Mail\{MailMessage,SendResult};

final class ApiWebhookLifecycleTest extends TestCase {
 private const REV='11111111-1111-4111-8111-111111111111';
 private const MSG='aaaaaaaa-1111-4111-8111-111111111111';
 private const SECRET='synthetic_webhook_token_01234567890123456789';
 private CredentialCipher $cipher;
 protected function setUp(): void {
  foreach(['_test_wp_options','_test_wp_option_write_failures','_test_wp_actions','_test_registered_rest_routes'] as $key) {$GLOBALS[$key]=[];}
  $GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>true];
  $GLOBALS['_test_wp_nonce_valid']=true;
  $GLOBALS['_test_rest_base']='https://example.com/wp-json/';
  $GLOBALS['_test_is_ssl']=true;
  $_SERVER['REMOTE_ADDR']='192.0.2.1';
  $GLOBALS['wpdb']=new TrackerDb();
  $GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.6.0';
  $this->cipher=new CredentialCipher(base64_encode(str_repeat('a',32)));
 }
 protected function tearDown(): void {
  unset($GLOBALS['wpdb'],$GLOBALS['_test_rest_base'],$GLOBALS['_test_is_ssl'],$_SERVER['REMOTE_ADDR']);
  $GLOBALS['_test_current_user_can']=[]; $GLOBALS['_test_wp_nonce_valid']=true;
 }
 private function service(string $p): ApiWebhookSettings {
  $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['provider'=>['active'=>$p],'diagnostic_revision'=>self::REV];
  return new ApiWebhookSettings($this->cipher,$p,new DeliveryKeyRepository($this->cipher),new SettingsRepository($this->cipher));
 }
 private function input(array $extra=[]): array {return array_replace(['credential_action'=>'replace','password'=>self::SECRET,'allowed_ips'=>['192.0.2.1']],$extra);}
 private function row(string $p): array {return get_option('scalyn_mail_relay_'.$p.'_webhook',[]);}
 public function test_provider_bound_encrypted_drafts_and_public_projection(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $s=$this->service($p); $this->assertTrue($s->save($this->input(),'valid'));
   $row=$this->row($p); $this->assertFalse($row['enabled']); $this->assertSame(self::REV,$row['configuration_id']);
   $this->assertStringNotContainsString(self::SECRET,json_encode($row));
   $this->assertStringNotContainsString(self::SECRET,json_encode($s->public_settings()));
   $this->assertArrayNotHasKey('verified',$s->public_settings(),'Local binding is not remote verification');
   $this->assertSame(self::SECRET,$s->ingress_source($row['id'])['password']);
   foreach(['postmark-webhook',$p==='brevo'?'smtp2go-webhook':'brevo-webhook',$p] as $wrong) {
    try { $this->cipher->decrypt($row['credentials'],$wrong); $this->fail('Cross-context decrypt'); } catch(RuntimeException $e){$this->assertNull($e->getPrevious());}
   }
   $this->assertTrue($s->save($this->input(['credential_action'=>'keep','password'=>'']),'valid'));
   $this->assertSame($row,$this->row($p));
  }
 }
 public function test_disabled_by_default_explicit_enable_and_revision_change_pause(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $s=$this->service($p); $s->save($this->input(),'valid');
   $this->assertNull($s->dispatch_source());
   try {$s->enable(false,'valid');$this->fail('No consent');}catch(RuntimeException $e){$this->assertFalse($s->collection_enabled());}
   $GLOBALS['_test_rest_base']='http://example.com/wp-json/';
   try {$s->enable(true,'valid');$this->fail('No HTTPS');}catch(RuntimeException $e){$this->assertFalse($s->collection_enabled());}
   $GLOBALS['_test_rest_base']='https://example.com/wp-json/';
   $this->assertTrue($s->enable(true,'valid'));
   $this->assertSame('collecting',$s->collection_status());
   $old=$s->dispatch_source();
   $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['diagnostic_revision']='22222222-2222-4222-8222-222222222222';
   $s=new ApiWebhookSettings($this->cipher,$p,new DeliveryKeyRepository($this->cipher),new SettingsRepository($this->cipher));
   $this->assertSame('paused',$s->collection_status());
   $this->assertTrue($s->ingress_source($old['id'])['enabled'],'Retained source still authenticates old callbacks');
   $s->disable('valid'); $this->assertSame('off',$s->collection_status());
   try {$s->save($this->input(),'valid');$this->fail('Must recreate after revision change');}catch(RuntimeException $e){$this->assertSame($old['id'],$this->row($p)['id']);}
  }
 }
 public function test_invalid_credentials_capability_nonce_and_enabled_edits_fail_closed(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $s=$this->service($p);
   foreach([['password'=>'short'],['allowed_ips'=>[]],['allowed_ips'=>['192.0.2.0/24']],['enabled'=>true]] as $bad) {
    try {$s->save($this->input($bad),'valid');$this->fail('Bad source');}catch(RuntimeException $e){$this->assertSame([],$this->row($p));}
   }
   $GLOBALS['_test_wp_nonce_valid']=false;
   try {$s->save($this->input(),'valid');$this->fail('Bad nonce');}catch(RuntimeException $e){$this->assertSame([],$this->row($p));}
   $GLOBALS['_test_wp_nonce_valid']=true;$GLOBALS['_test_current_user_can']=[];
   try {$s->save($this->input(),'valid');$this->fail('No capability');}catch(RuntimeException $e){$this->assertSame([],$this->row($p));}
   $GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_MAIL=>true];
   $s->save($this->input(),'valid');$s->enable(true,'valid');$row=$this->row($p);
   try {$s->save($this->input(),'valid');$this->fail('Enabled edit');}catch(RuntimeException $e){$this->assertSame($row,$this->row($p));}
   try {$s->remove(true,'valid');$this->fail('Enabled remove');}catch(RuntimeException $e){$this->assertSame($row,$this->row($p));}
  }
 }
 public function test_both_providers_track_and_bind_acknowledgements_without_lowercasing(): void {
  foreach(['smtp2go'=>'AbCd-1234','brevo'=>'<UpperCase@relay.test>'] as $p=>$id) {
   $s=$this->service($p);$s->save($this->input(),'valid');$s->enable(true,'valid');
   $tracker=new DeliveryTracker(new PostmarkWebhookSettings($this->cipher),new DeliveryKeyRepository($this->cipher),new DeliveryAttemptRepository(),new PostmarkProvider(),[$p=>$s]);
   $msg=new MailMessage(self::MSG,'sender@example.com',['Alice <alice@example.com>'],'Subject','Body','text/plain',['Cc: other@example.com','Bcc: hidden@example.com']);
   $a=$tracker->prepare($msg,$p);
   $this->assertNotNull($a);
   $this->assertSame($p,$GLOBALS['wpdb']->attempts[self::MSG]['provider']);
   $this->assertCount(3,$GLOBALS['wpdb']->members[self::MSG]);
   $this->assertStringNotContainsString('hidden@example.com',json_encode($GLOBALS['wpdb']->attempts));
   $tracker->acknowledge($a,new SendResult(true,$p,$id));
   $this->assertSame($id,$GLOBALS['wpdb']->attempts[self::MSG]['provider_message_id']);
   $GLOBALS['wpdb']=new TrackerDb();
  }
 }
 public function test_rotation_unreadable_credentials_removal_and_failed_save(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $s=$this->service($p);$s->save($this->input(),'valid');$old=$this->row($p);
   $new=str_repeat('N',40);$s->save($this->input(['password'=>$new]),'valid');
   $this->assertSame($old['id'],$this->row($p)['id']);
   $this->assertSame($new,$s->ingress_source($old['id'])['password']);
   $bad=new ApiWebhookSettings(new CredentialCipher(base64_encode(str_repeat('b',32))),$p,null,new SettingsRepository($this->cipher));
   try {$bad->enable(true,'valid');$this->fail('Unreadable secret');}catch(RuntimeException $e){$this->assertFalse($s->collection_enabled());}
   update_option('scalyn_webhook_budget_'.$old['id'],'counter');
   try {$s->remove(false,'valid');$this->fail('Explicit confirmation required');}catch(RuntimeException $e){$this->assertTrue($s->has_source());}
   $this->assertTrue($s->remove(true,'valid'));
   $this->assertNull($s->ingress_source($old['id']));
   $this->assertFalse(get_option('scalyn_webhook_budget_'.$old['id'],false));
   $GLOBALS['_test_wp_option_write_failures']['scalyn_mail_relay_'.$p.'_webhook']=true;
   $this->assertFalse($s->save($this->input(),'valid'));
   $GLOBALS['_test_wp_option_write_failures']=[];
  }
 }
 private function endpoint(ApiWebhookSettings $s): ApiWebhookEndpoint {return new ApiWebhookEndpoint($s,new SettingsRepository($this->cipher),new WebhookRateLimitRepository(),new DeliveryAttemptRepository(),new DeliveryKeyRepository($this->cipher),new DeliveryEventRepository());}
 public function test_receivers_register_only_saved_sources_and_authenticate_even_when_disabled(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $GLOBALS['_test_registered_rest_routes']=[];
   $s=$this->service($p);$endpoint=$this->endpoint($s);$endpoint->register();$this->assertSame([],$GLOBALS['_test_registered_rest_routes']);
   $s->save($this->input(),'valid');$endpoint->register();$route=$GLOBALS['_test_registered_rest_routes'][0];
   $this->assertStringStartsWith('/webhooks/'.$p.'/',$route['route']);
   $r=new WP_REST_Request();$r->set_param('source',$this->row($p)['id']);$r->set_header('Content-Type','application/json');$r->set_header('Authorization','Bearer '.self::SECRET);$r->set_body('{}');
   $response=$endpoint->handle($r);$this->assertSame(200,$response->get_status());$this->assertNull($response->get_data());
   $this->assertSame('no-store',$response->headers['Cache-Control']);
   $r->set_header('Authorization','Bearer '.str_repeat('x',40));$this->assertSame(401,$endpoint->handle($r)->get_status());
   $this->assertSame([],$GLOBALS['wpdb']->inserts);
  }
 }
}
