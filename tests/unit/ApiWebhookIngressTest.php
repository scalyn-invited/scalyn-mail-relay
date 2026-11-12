<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Providers\ApiWebhookIngress;
use Scalyn\MailRelay\Providers\ApiWebhookNormalizer;

final class ApiWebhookIngressTest extends TestCase {
	private const ID = '12345678-1234-4234-8234-123456789abc';
	private int $budgets = 0;
	private int $resolutions = 0;
	private function source(): array {
		return ['id'=>self::ID,'provider'=>'smtp2go','enabled'=>true,'username'=>'synthetic_username','password'=>'synthetic_password_01234567890123456789','allowed_ips'=>['192.0.2.1']];
	}
	private function request(): array {
		$s = $this->source();
		return ['method'=>'POST','https'=>true,'peer_ip'=>'192.0.2.1','headers'=>['Authorization'=>['Bearer '.$s['password']], 'Content-Type'=>['application/json']], 'body'=>json_encode(['event'=>'delivered','email_id'=>'test-email-123','rcpt'=>'private@example.com','time'=>'2026-10-02 00:00:00'])];
	}
	private function inspect(array $request, ?array $source=null, ?callable $budget=null): array {
		return (new ApiWebhookIngress())->inspect($request, $source ?? $this->source(), $budget ?? function($id) { $this->budgets++; $this->assertSame(self::ID,$id); return true; }, function() { $this->resolutions++; return ['message_uuid'=>self::ID,'recipient_token'=>str_repeat('a',64)]; }, new DateTimeImmutable('2026-10-02T00:01:00Z'));
	}
	public function test_valid_request_is_ready_but_never_acknowledged_as_persisted(): void {
		$result=$this->inspect($this->request());
		$this->assertSame('ready',$result['disposition']);
		$this->assertArrayNotHasKey('http_status',$result);
		$this->assertSame(1,$this->budgets);
		$this->assertSame(1,$this->resolutions);
		$this->assertStringNotContainsString('private@example.com',json_encode($result));
		$this->assertStringNotContainsString($this->source()['password'],json_encode($result));
	}
	public function test_rejected_requests_never_parse_correlate_or_consume_authenticated_budget(): void {
		$base=$this->request();
		$cases=[];
		foreach ([['method','GET',405],['https',false,401],['peer_ip','192.0.2.2',401],['body',str_repeat('x',ApiWebhookNormalizer::MAX_BYTES+1),413]] as [$key,$value,$status]) { $r=$base; $r[$key]=$value; $cases[]=[$r,$status]; }
		foreach ([['Authorization',[],400],['Authorization',['one','two'],400],['authorization',['second'],400],['Content-Type',['text/plain'],415],['Content-Encoding',['gzip'],415],['Content-Length',['999'],400],['X-Test',["bad\r\nheader"],400],['Authorization',['Basic invalid'],401]] as [$key,$value,$status]) { $r=$base; $r['headers'][$key]=$value; $cases[]=[$r,$status]; }
		foreach ($cases as [$request,$status]) {
			$result=$this->inspect($request);
			$this->assertSame(['disposition'=>'rejected','http_status'=>$status],$result);
		}
		$this->assertSame(0,$this->budgets);
		$this->assertSame(0,$this->resolutions);
	}
	public function test_forwarded_headers_cannot_override_network_facts(): void {
		$r=$this->request(); $r['peer_ip']='192.0.2.2';
		$r['headers']['X-Forwarded-For']=['192.0.2.1'];
		$r['headers']['X-Forwarded-Proto']=['https'];
		$this->assertSame(401,$this->inspect($r)['http_status']);
		$r['peer_ip']='192.0.2.1'; $r['https']=false;
		$this->assertSame(401,$this->inspect($r)['http_status']);
		$this->assertSame(0,$this->resolutions);
	}
	public function test_disabled_source_still_authenticates_but_does_not_collect(): void {
		$s=$this->source(); $s['enabled']=false;
		$this->assertSame(['disposition'=>'ignored'],$this->inspect($this->request(),$s));
		$r=$this->request(); unset($r['headers']['Authorization']);
		$this->assertSame(401,$this->inspect($r,$s)['http_status']);
		$this->assertSame(0,$this->budgets);
		$this->assertSame(0,$this->resolutions);
	}
	public function test_budget_gate_fails_closed_and_hides_dependency_errors(): void {
		$this->assertSame(429,$this->inspect($this->request(),null,static fn()=>false)['http_status']);
		$this->assertSame(503,$this->inspect($this->request(),null,static function() { throw new InvalidArgumentException('private budget error'); })['http_status']);
		$this->assertSame(503,$this->inspect($this->request(),null,static fn()=>null)['http_status']);
		$result=$this->inspect($this->request(),null,static function() { throw new RuntimeException('secret SQL detail'); });
		$this->assertSame(['disposition'=>'rejected','http_status'=>503],$result);
		$this->assertSame(0,$this->resolutions);
	}
	public function test_malformed_body_is_checked_only_after_authentication_and_budget(): void {
		$r=$this->request(); $r['body']='{';
		$this->assertSame(400,$this->inspect($r)['http_status']);
		$this->assertSame(1,$this->budgets);
		$this->assertSame(0,$this->resolutions);
	}
}
