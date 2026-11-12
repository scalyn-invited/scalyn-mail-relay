<?php
namespace Scalyn\MailRelay\Alerts {
	function wp_safe_remote_post(string $url,array $args):mixed {
		$GLOBALS['_alert_http_calls'][]=[$url,$args];
		if($GLOBALS['_alert_http_throw'] ?? false) throw new \RuntimeException('secret receiver detail');
		return $GLOBALS['_alert_http_response'] ?? ['response'=>['code'=>204]];
	}
	function is_wp_error(mixed $response):bool {return $response instanceof \Throwable;}
	function wp_remote_retrieve_response_code(mixed $response):int {return (int)($response['response']['code'] ?? 0);}
}
namespace {
	use PHPUnit\Framework\TestCase;
	use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Scalyn\MailRelay\Alerts\WebhookChannel;

	#[RunTestsInSeparateProcesses]
	#[PreserveGlobalState(false)]
	final class WebhookChannelTest extends TestCase {
		private function job():array {return ['notification_uuid'=>'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','alert_uuid'=>'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb','alert_type'=>'health','event'=>'opened','secret'=>'must-not-leave'];}
		public function test_payload_is_allowlisted_and_http_is_bounded():void {
			define('SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL','https://example.com/private-hook');
			define('SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN','secret-token');
			$this->assertSame(204,(new WebhookChannel())->send($this->job()));
			[$url,$args]=$GLOBALS['_alert_http_calls'][0];
			$this->assertSame(5,$args['timeout']);$this->assertSame(0,$args['redirection']);
			$this->assertTrue($args['sslverify']);$this->assertSame(1024,$args['limit_response_size']);
			$this->assertSame('Bearer secret-token',$args['headers']['Authorization']);
			$this->assertSame($this->job()['notification_uuid'],$args['headers']['Idempotency-Key']);
			$this->assertSame(['version','notification_uuid','incident_uuid','type','event','message'],array_keys(json_decode($args['body'],true)));
			$this->assertStringNotContainsString('must-not-leave',$args['body']);
			$this->assertStringNotContainsString('secret-token',$args['body']);
		}
		#[DataProvider('bad_urls')]
		public function test_invalid_configuration_refuses_requests(mixed $url,string $token=''):void {
			define('SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL',$url);define('SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN',$token);
			$this->assertFalse((new WebhookChannel())->configured());
			$this->assertSame(0,(new WebhookChannel())->send($this->job()));
			$this->assertEmpty($GLOBALS['_alert_http_calls'] ?? []);
		}
		public static function bad_urls():array {return [['http://example.com'],['https://user:secret@example.com'],['https://example.com/#secret'],[['invalid']],['https://example.com',"bad\r\nHeader: injected"]];}
		public function test_transport_failure_is_safe_and_unknown_not_success():void {
			define('SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL','https://example.com');
			$GLOBALS['_alert_http_throw']=true;
			$this->assertSame(0,(new WebhookChannel())->send($this->job()));
			$GLOBALS['_alert_http_throw']=false;
			$GLOBALS['_alert_http_response']=new RuntimeException('secret');
			$this->assertSame(0,(new WebhookChannel())->send($this->job()));
		}
	}
}
