<?php

namespace Scalyn\MailRelay\Providers\Postmark {
	function wp_safe_remote_request(string $url, array $args): array {
		$GLOBALS['_postmark_http_calls'][] = [$url, $args];
		return ['response'=>['code'=>200], 'body'=>json_encode(str_ends_with($url, '/server') ? ['ID'=>1,'DeliveryType'=>'Live','ApiTokens'=>['private-returned-token']] : ['ErrorCode'=>0,'MessageID'=>'12345678-1234-4234-8234-123456789abc'])];
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
	use Scalyn\MailRelay\Mail\MailMessage;
	use Scalyn\MailRelay\Core\SettingsRepository;
	use Scalyn\MailRelay\Core\CredentialCipher;
	use Scalyn\MailRelay\Core\ProviderRegistry;
	use Scalyn\MailRelay\Core\HookNames;
	use Scalyn\MailRelay\Mail\MailDispatcher;
	use Scalyn\MailRelay\Mail\WordPressMailBridge;

	class StubPostmarkProvider extends PostmarkProvider {
		public array $calls = [];
		public mixed $server = ['response'=>['code'=>200], 'body'=>'{"ID":1,"DeliveryType":"Live","ApiTokens":["private-returned-token"]}'];
		public mixed $email = ['response'=>['code'=>200], 'body'=>'{"ErrorCode":0,"MessageID":"12345678-1234-4234-8234-123456789abc","To":"private@example.com","Message":"private"}'];
		protected function http(string $path, array $args): mixed {
			$this->calls[] = [$path, $args];
			$value = '/server' === $path ? $this->server : $this->email;
			if ($value instanceof \Throwable) { throw $value; }
			return $value;
		}
	}

	final class PostmarkProviderTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['wpdb'] = new \WpdbStub();
			$GLOBALS['_test_wp_options']=[];
			$GLOBALS['_test_wp_actions']=[];
			$GLOBALS['_test_wp_added_actions']=[];
			$GLOBALS['_test_wp_filters']=[];
		}
		protected function tearDown(): void {
			$GLOBALS['_test_wp_actions']=[];
			$GLOBALS['_test_wp_added_actions']=[];
			$GLOBALS['_test_wp_filters']=[];
		}
		private const UUID = '12345678-1234-4234-8234-123456789abc';
		private const KEY = 'PM.synthetic_test_credential_0123456789';
		private function config(): array { return ['api_key'=>self::KEY, 'from_email'=>'sender@example.com','from_name'=>'Sender']; }
		private function message(array $changes=[]): MailMessage {
			return new MailMessage(...array_replace(['uuid'=>self::UUID,'from'=>'sender@example.com','to'=>['recipient@example.com'],'subject'=>'Hello','body'=>'Body','content_type'=>'text/plain','headers'=>[],'attachments'=>[],'context'=>['private'=>'private-context']], $changes));
		}

		public function test_fixed_https_boundary_and_private_success(): void {
			$GLOBALS['_postmark_http_calls']=[];
			$result=(new PostmarkProvider())->send($this->message(),$this->config());
			$this->assertTrue($result->success);
			$this->assertSame('postmark',$result->provider);
			$this->assertSame(self::UUID,$result->provider_message_id);
			$this->assertStringContainsString('delivery is unconfirmed',$result->response_message);
			$this->assertCount(2,$GLOBALS['_postmark_http_calls']);
			$this->assertSame(['https://api.postmarkapp.com/server','https://api.postmarkapp.com/email'],array_column($GLOBALS['_postmark_http_calls'],0));
			foreach ($GLOBALS['_postmark_http_calls'] as [$url,$args]) {
				$this->assertSame(0,$args['redirection']); $this->assertTrue($args['sslverify']);
				$this->assertTrue($args['reject_unsafe_urls']); $this->assertSame(15,$args['timeout']);
				$this->assertSame(16384,$args['limit_response_size']);
				$this->assertSame(self::KEY,$args['headers']['X-Postmark-Server-Token']);
			}
			$this->assertStringNotContainsString('private',serialize($result));
			$this->assertStringNotContainsString(self::KEY,serialize($result));
		}

		public function test_connection_never_posts_or_leaks_server_tokens(): void {
			$p=new StubPostmarkProvider(); $r=$p->test_connection($this->config());
			$this->assertTrue($r->success); $this->assertCount(1,$p->calls);
			$this->assertSame('GET',$p->calls[0][1]['method']);
			$this->assertArrayNotHasKey('body',$p->calls[0][1]);
			$this->assertStringContainsString('No email was sent',$r->message);
			$this->assertStringNotContainsString('private',serialize($r));
		}

		public static function invalid_servers(): array { return [
			[['response'=>['code'=>200],'body'=>'{"ID":1,"DeliveryType":"Sandbox"}']],
			[['response'=>['code'=>200],'body'=>'{"ID":1}']],
			[['response'=>['code'=>200],'body'=>'{"ID":1,"DeliveryType":"Live","ErrorCode":10}']],
			[['response'=>['code'=>401],'body'=>'private']],
			[['response'=>['code'=>500],'body'=>'private']],
			[['response'=>['code'=>302],'body'=>'private']],
			[['response'=>['code'=>200],'body'=>'broken']],
			[['response'=>['code'=>200],'body'=>str_repeat('x',16385)]],
			[new \RuntimeException('private-token')],
		]; }
		/** @dataProvider invalid_servers */
		public function test_invalid_or_sandbox_server_never_submits(mixed $server): void {
			$p=new StubPostmarkProvider(); $p->server=$server;
			$this->assertFalse($p->test_connection($this->config())->success);
			$p->calls=[]; $r=$p->send($this->message(),$this->config());
			$this->assertFalse($r->success); $this->assertFalse($r->acceptance_unconfirmed);
			$this->assertCount(1,$p->calls); $this->assertSame('/server',$p->calls[0][0]);
			$this->assertStringNotContainsString('private',serialize($r));
		}

		public static function outcomes(): array { return [
			[200,'{"ErrorCode":0}',true], [200,'{"ErrorCode":"0","MessageID":"12345678-1234-4234-8234-123456789abc"}',true],
			[200,'{"ErrorCode":0,"MessageID":"private-token"}',true], [200,'private',true],
			[202,'{}',true], [302,'{}',true], [408,'{}',true], [500,'{}',true], [503,'{}',true],
			[401,'{"Message":"private-token"}',false], [422,'{"ErrorCode":406,"Message":"private-recipient"}',false], [429,'{}',false],
		]; }
		/** @dataProvider outcomes */
		public function test_only_complete_acknowledgements_are_accepted(int $code,string $body,bool $uncertain): void {
			$p=new StubPostmarkProvider(); $p->email=['response'=>['code'=>$code],'body'=>$body];
			$r=$p->send($this->message(),$this->config());
			$this->assertFalse($r->success); $this->assertSame($uncertain,$r->acceptance_unconfirmed);
			$this->assertFalse($r->retryable); $this->assertNull($r->provider_message_id);
			$this->assertCount(2,$p->calls); $this->assertStringNotContainsString('private',serialize($r));
		}

		public function test_timeout_after_submission_is_not_retried(): void {
			$p=new StubPostmarkProvider(); $p->email=new \RuntimeException('private credential');
			$r=$p->send($this->message(),$this->config());
			$this->assertTrue($r->acceptance_unconfirmed); $this->assertFalse($r->retryable);
			$this->assertCount(2,$p->calls); $this->assertStringNotContainsString('private',serialize($r));
		}

		public function test_test_token_and_message_limits_fail_before_network(): void {
			$p=new StubPostmarkProvider();
			$this->assertFalse($p->validate_config(array_replace($this->config(),['api_key'=>'POSTMARK_API_TEST']))->valid);
			foreach ([['to'=>array_fill(0,51,'recipient@example.com')],['body'=>str_repeat('x',1048577)],['headers'=>['X-Unknown: private']],['subject'=>"bad\nsubject"],['uuid'=>'invalid'],['body'=>"\xff"],['from'=>'other@example.com']] as $change) {
				$this->assertFalse($p->send($this->message($change),$this->config())->success);
			}
			$this->assertSame([],$p->calls);
		}

		public function test_wordpress_bridge_uses_postmark_and_correlates_outcomes(): void {
			$GLOBALS['_test_wp_options']=[]; $GLOBALS['_test_wp_actions']=[]; $GLOBALS['_test_wp_filters']=[];
			$cipher=new CredentialCipher(base64_encode(str_repeat('a',32)));
			$s=new SettingsRepository($cipher); $s->save_postmark($this->config()+['key_action'=>'replace'],$cipher);
			$p=new StubPostmarkProvider(); $registry=new ProviderRegistry(); $registry->register($p);
			$bridge=new WordPressMailBridge($s,new MailDispatcher($registry,$s));
			$atts=['to'=>'recipient@example.com','subject'=>'Test','message'=>'Body','headers'=>['Bcc: blind@example.com'],'attachments'=>[]];
			$this->assertNull($bridge->maybe_send(null,$atts));
			$s->save(['provider'=>['active'=>'postmark']]);
			$events=[];
			foreach ([HookNames::MAIL_SENT,HookNames::MAIL_FAILED,HookNames::MAIL_OUTCOME_UNCONFIRMED] as $hook) {
				$GLOBALS['_test_wp_actions'][$hook]=static function($result,$message)use(&$events,$hook){$events[]=[$hook,$result,$message->uuid];};
			}
			$this->assertSame('prior',$bridge->maybe_send('prior',$atts)); $this->assertSame([],$p->calls);
			$this->assertTrue($bridge->maybe_send(null,$atts));
			$payload=json_decode($p->calls[1][1]['body'],true);
			$this->assertSame($events[0][2],$payload['Metadata']['scalyn_message_uuid']);
			$this->assertSame('blind@example.com',$payload['Bcc']);
			$this->assertSame('postmark',$events[0][1]->provider);
			$p->email=new \RuntimeException('private'); $this->assertFalse($bridge->maybe_send(null,$atts));
			$this->assertSame(HookNames::MAIL_OUTCOME_UNCONFIRMED,$events[1][0]);
			$this->assertFalse($bridge->maybe_send(null,array_replace($atts,['embeds'=>['private']])));
			$this->assertSame(HookNames::MAIL_FAILED,$events[2][0]); $this->assertSame('postmark',$events[2][1]->provider);
			$this->assertStringNotContainsString(self::KEY,serialize($events));
			$this->assertStringNotContainsString('blind@example.com',serialize($events));
			$GLOBALS['_test_wp_actions']=[]; $GLOBALS['_test_wp_filters']=[];
		}
	}
}
