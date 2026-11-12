<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Providers\ApiWebhookNormalizer;

final class ApiWebhookNormalizerTest extends TestCase {
 private const ID='12345678-1234-4234-8234-123456789abc';
 private function event(string $provider, string $event='delivered'): array {
  return $provider==='brevo' ? ['event'=>$event,'email'=>'private@example.com','message-id'=>'example.123@relay.test','ts_event'=>1790899200,'X-Mailin-custom'=>self::ID,'reason'=>'private reason','subject'=>'private subject'] : ['event'=>$event,'rcpt'=>'private@example.com','email_id'=>'abc123-def456','time'=>'2026-10-02 00:00:00','X-Scalyn-Message-UUID'=>self::ID,'bounce'=>'hard','auth'=>'PRIVATE_API_KEY','subject'=>'private subject'];
 }
 private function normalize(string $provider,array $event,?callable $resolve=null): ?array {
  return (new ApiWebhookNormalizer())->normalize(json_encode($event),self::ID,$provider,new DateTimeImmutable('2026-10-03T00:00:00Z'),$resolve??static fn()=>['message_uuid'=>self::ID,'recipient_token'=>str_repeat('a',64)]);
 }
 public function test_providers_delivery_and_bounce_are_minimal_and_deterministic(): void {
  foreach(['smtp2go'=>['delivered','bounce'],'brevo'=>['delivered','hard_bounce','soft_bounce']] as $p=>$events) {
   foreach($events as $kind) {
    $e=$this->event($p,$kind); $out=$this->normalize($p,$e);
    $this->assertSame($p,$out['provider']);
    $this->assertSame($p.'_bearer_tls',$out['authentication_method']);
    $this->assertSame($kind==='delivered'?'delivery':'bounce',$out['kind']);
    $this->assertSame($out,$this->normalize($p,$e));
    foreach(['private','PRIVATE_API_KEY','@example.com'] as $secret) { $this->assertStringNotContainsString($secret,json_encode($out)); }
    $this->assertCount(12,$out);
    $this->assertMatchesRegularExpression('/^2026-10-0[12]T00:00:00\\.000000Z$/',$out['occurred_at']);
   }
  }
 }
 public function test_brevo_documented_id_forms_resolve_to_same_canonical_id(): void {
  $e=$this->event('brevo'); $plain=$this->normalize('brevo',$e); $e['message-id']='<'.$e['message-id'].'>';
  $this->assertSame($plain,$this->normalize('brevo',$e));
  $this->assertSame('<example.123@relay.test>',$plain['provider_message_id']);
 }
 public function test_correlation_is_source_resolved_and_requires_exact_hint_membership(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $called=false;
   $this->assertNull($this->normalize($p,$this->event($p),function($id,$hint,$address) use (&$called) { $called=true; $this->assertSame(self::ID,$hint); $this->assertSame('private@example.com',$address); return null; }));
   $this->assertTrue($called);
   try { $this->normalize($p,$this->event($p),fn()=>['message_uuid'=>'22345678-1234-4234-8234-123456789abc','recipient_token'=>str_repeat('a',64)]); $this->fail('Mismatched hint must fail'); } catch(InvalidArgumentException $e) { $this->assertSame('Invalid webhook correlation.',$e->getMessage()); }
  }
 }
 public function test_unsupported_and_malformed_events_never_resolve(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $this->assertNull($this->normalize($p,$this->event($p,'open'),function(){ $this->fail('No resolver for unsupported'); }));
   foreach(['recipient','id','time','hint'] as $bad) {
    $e=$this->event($p);
    $key= $p==='brevo' ? ['recipient'=>'email','id'=>'message-id','time'=>'ts_event','hint'=>'X-Mailin-custom'][$bad] : ['recipient'=>'rcpt','id'=>'email_id','time'=>'time','hint'=>'X-Scalyn-Message-UUID'][$bad];
    $e[$key]=['invalid'];
    try { $this->normalize($p,$e,function(){ $this->fail('Malformed events must not resolve'); }); $this->fail('Must reject'); } catch(InvalidArgumentException $error) { $this->assertStringNotContainsString('private',$error->getMessage()); }
   }
  }
 }
 public function test_future_dates_and_batches_are_rejected(): void {
  foreach(['smtp2go','brevo'] as $p) {
   $e=$this->event($p); $e[$p==='brevo'?'ts_event':'time']=$p==='brevo'?2000000000:'2040-01-01 00:00:00';
   try { $this->normalize($p,$e); $this->fail('Future'); } catch(InvalidArgumentException $error) { $this->assertSame('Invalid webhook time.',$error->getMessage()); }
   try { $this->normalize($p,[$this->event($p)]); $this->fail('Batch'); } catch(InvalidArgumentException $error) { $this->assertSame('Invalid webhook envelope.',$error->getMessage()); }
  }
 }
}
