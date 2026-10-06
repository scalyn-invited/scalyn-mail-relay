<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Providers\Smtp2go\Smtp2goProvider;
use Scalyn\MailRelay\Mail\MailMessage;

class StubSmtp2goProvider extends Smtp2goProvider {
	public array $calls=[];
	public mixed $response=['response'=>['code'=>200],'body'=>'{"data":{"succeeded":1,"failed":0,"failures":[],"email_id":"1er8bV-6Tw0Mi-7h"}}'];
	protected function http(string $path,array $args): mixed {
		$this->calls[]=[$path,$args];
		if ($this->response instanceof Throwable) throw $this->response;
		return $this->response;
	}
}

final class Smtp2goProviderTest extends TestCase {
	public function test_wordpress_bridge_routes_only_selected_provider_and_preserves_revision_scope(): void {
		$GLOBALS['_test_wp_options']=[]; $GLOBALS['_test_wp_actions']=[]; $GLOBALS['_test_wp_filters']=[];
		$cipher=new \Scalyn\MailRelay\Core\CredentialCipher(base64_encode(str_repeat('a',32)));
		$s=new \Scalyn\MailRelay\Core\SettingsRepository($cipher);
		$s->save_smtp2go($this->config()+['key_action'=>'replace'],$cipher);
		$p=new StubSmtp2goProvider(); $registry=new \Scalyn\MailRelay\Core\ProviderRegistry(); $registry->register($p);
		$bridge=new \Scalyn\MailRelay\Mail\WordPressMailBridge($s,new \Scalyn\MailRelay\Mail\MailDispatcher($registry,$s));
		$atts=['to'=>'recipient@example.com','subject'=>'Form','message'=>'Body','headers'=>['Content-Type: text/plain; charset=UTF-8'],'attachments'=>[]];
		$this->assertNull($bridge->maybe_send(null,$atts)); $s->save(['provider'=>['active'=>'smtp2go']]);
		$revision=$s->get_diagnostic_revision();
		$this->assertSame('prior',$bridge->maybe_send('prior',$atts)); $this->assertCount(0,$p->calls);
		$this->assertTrue($bridge->maybe_send(null,$atts)); $this->assertCount(1,$p->calls);
		$this->assertSame($revision,$s->get_diagnostic_revision());
		$s->save_smtp2go($this->config()+['key_action'=>'replace'],$cipher);
		$this->assertNotSame($revision,$s->get_diagnostic_revision());
		$this->assertFalse($s->is_provider_verified());
		$GLOBALS['_test_wp_actions']=[]; $GLOBALS['_test_wp_filters']=[];
	}
	private function config(): array {return ['api_key'=>'api-synthetic_credential_0123456789','from_email'=>'sender@example.com','from_name'=>'Sender'];}
	private function message(array $changes=[]): MailMessage {return new MailMessage(...array_replace(['uuid'=>'12345678-1234-4234-8234-123456789abc','from'=>'sender@example.com','to'=>['recipient@example.com'],'subject'=>'Hello','body'=>'Body','content_type'=>'text/plain','headers'=>[],'attachments'=>[]],$changes));}
	public function test_acceptance_payload_and_secure_boundary(): void {
		$p=new StubSmtp2goProvider(); $r=$p->send($this->message(),$this->config());
		$this->assertTrue($r->success); $this->assertSame('smtp2go',$r->provider);
		$this->assertSame('1er8bV-6Tw0Mi-7h',$r->provider_message_id);
		$this->assertStringContainsString('delivery is unconfirmed',$r->response_message);
		$this->assertCount(1,$p->calls); [$path,$args]=$p->calls[0];
		$this->assertSame('/email/send',$path); $this->assertSame('POST',$args['method']);
		$this->assertSame(0,$args['redirection']); $this->assertTrue($args['sslverify']); $this->assertTrue($args['reject_unsafe_urls']);
		$this->assertSame(15,$args['timeout']); $this->assertSame(16384,$args['limit_response_size']);
		$this->assertSame($this->config()['api_key'],$args['headers']['X-Smtp2go-Api-Key']);
		$body=json_decode($args['body'],true); $this->assertSame('Body',$body['text_body']); $this->assertFalse($body['fastaccept']);
		$this->assertStringNotContainsString($this->config()['api_key'],serialize($r));
	}
	public function test_verification_never_sends_and_discards_account_data(): void {
		$p=new StubSmtp2goProvider(); $p->response=['response'=>['code'=>200],'body'=>'{"data":{"cycle_used":0,"private":"secret"}}'];
		$r=$p->test_connection($this->config()); $this->assertTrue($r->success);
		$this->assertSame('/stats/email_cycle',$p->calls[0][0]); $this->assertSame('{}',$p->calls[0][1]['body']);
		$this->assertStringNotContainsString('secret',serialize($r));
		$p->response=['response'=>['code'=>200],'body'=>'{"data":{"cycle_used":0,"error_code":"denied"}}'];
		$this->assertFalse($p->test_connection($this->config())->success);
	}
	#[DataProvider('outcomes')]
	public function test_rejections_and_uncertainty_never_retry(int $code,string $body,bool $uncertain): void {
		$p=new StubSmtp2goProvider(); $p->response=['response'=>['code'=>$code],'body'=>$body];
		$r=$p->send($this->message(),$this->config()); $this->assertFalse($r->success); $this->assertSame($uncertain,$r->acceptance_unconfirmed);
		$this->assertFalse($r->retryable); $this->assertCount(1,$p->calls); $this->assertStringNotContainsString('private',serialize($r));
	}
	public static function outcomes(): array {return [
		[200,'{"data":{"succeeded":0,"failed":1,"failures":["private"]}}',true],
		[200,'{"data":{"succeeded":1,"failed":1,"email_id":"safe-id"}}',true],
		[200,'{"data":{"succeeded":"1","failed":0,"email_id":"safe-id"}}',true],
		[200,'{"data":{"succeeded":1,"failed":0,"email_id":"private@example.com"}}',true],
		[200,'{}',true],[200,'private',true],[200,str_repeat('x',16385),true],[302,'{}',true],[408,'{}',true],[500,'{}',true],
		[400,'{"error":"private"}',false],[401,'{}',false],[403,'{}',false],[429,'{}',false]
	];}
	public function test_timeout_is_uncertain_and_not_retried(): void {
		$p=new StubSmtp2goProvider(); $p->response=new RuntimeException('private');
		$r=$p->send($this->message(),$this->config()); $this->assertTrue($r->acceptance_unconfirmed); $this->assertFalse($r->retryable); $this->assertCount(1,$p->calls);
	}
	#[DataProvider('invalid_messages')]
	public function test_invalid_content_never_reaches_http(array $changes): void {
		$p=new StubSmtp2goProvider(); $r=$p->send($this->message($changes),$this->config());
		$this->assertFalse($r->success); $this->assertFalse($r->acceptance_unconfirmed); $this->assertSame([],$p->calls);
	}
	public static function invalid_messages(): array {return [
		[['from'=>'other@example.com']],[['subject'=>"bad\r\nBcc:private@example.com"]],[['body'=>str_repeat('x',1048577)]],
		[['to'=>array_fill(0,51,'recipient@example.com')]],[['to'=>[]]],[['headers'=>['Authorization: private']]],
		[['headers'=>['Reply-To: reply@example.com','Reply-To: other@example.com']]],[['attachments'=>['https://example.com/private']]],
		[['uuid'=>'invalid']],[['content_type'=>'multipart/mixed']],[['body'=>"\xFF"]]
	];}
	public function test_html_reply_cc_bcc_and_attachment_mapping(): void {
		$p=new StubSmtp2goProvider(); $p->response=['response'=>['code'=>200],'body'=>'{"data":{"succeeded":3,"failed":0,"email_id":"safe-id"}}'];
		$r=$p->send($this->message(['content_type'=>'text/html','body'=>'<b>Hello</b>','headers'=>['Cc: copy@example.com','Bcc: hidden@example.com','Reply-To: reply@example.com'],'attachments'=>[__FILE__]]),$this->config());
		$this->assertTrue($r->success); $body=json_decode($p->calls[0][1]['body'],true);
		$this->assertSame(['hidden@example.com'],$body['bcc']); $this->assertSame('<b>Hello</b>',$body['html_body']);
		$this->assertSame('Reply-To',$body['custom_headers'][0]['header']);
		$this->assertSame(basename(__FILE__),$body['attachments'][0]['filename']);
		$this->assertSame(file_get_contents(__FILE__),base64_decode($body['attachments'][0]['fileblob']));
	}
}
