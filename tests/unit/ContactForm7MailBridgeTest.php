<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\WordPressMailBridge;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
use Scalyn\MailRelay\Providers\SendGrid\SendGridProvider;

final class ContactForm7MailBridgeTest extends TestCase {

	private function setupBridge(string $id): array {
		$GLOBALS['wpdb'] = new WpdbStub();
		foreach (['_test_wp_options', '_test_wp_actions', '_test_wp_added_actions', '_test_wp_filters'] as $global) {
			$GLOBALS[$global] = [];
		}
		$cipher = new CredentialCipher(base64_encode(str_repeat('q', 32)));
		$settings = new SettingsRepository($cipher);
		$method = 'save_' . $id;
		$settings->$method(['key_action'=>'replace', 'api_key'=>'synthetic_credential_0123456789', 'from_email'=>'sender@example.com', 'from_name'=>'Sender'], $cipher);
		$settings->save(['provider'=>['active'=>$id]]);
		$provider = 'postmark' === $id ? new class extends PostmarkProvider {
			public array $requests = [];
			protected function http(string $path, array $args): mixed {
				$this->requests[] = $args;
				return ['response'=>['code'=>200], 'body'=>'/server' === $path ? '{"ID":1,"DeliveryType":"Live"}' : '{"ErrorCode":0,"MessageID":"12345678-1234-4234-8234-123456789abc"}'];
			}
		} : new class extends SendGridProvider {
			public array $requests = [];
			protected function post(array $args): mixed {
				$this->requests[] = $args;
				return ['response'=>['code'=>202]];
			}
			protected function response_code(mixed $response): int { return $response['response']['code']; }
		};
		$registry = new ProviderRegistry();
		$registry->register($provider);
		return [new WordPressMailBridge($settings, new MailDispatcher($registry, $settings)), $provider];
	}

	public function test_cf7_plain_and_html_with_attachment_use_real_adapters_without_internal_header(): void {
		foreach (['postmark', 'sendgrid'] as $id) {
			foreach (['text/plain', 'text/html'] as $type) {
				foreach ([false, true] as $stringHeaders) {
					[$bridge, $provider] = $this->setupBridge($id);
					$headers = ['From: Site <sender@example.com>', 'X-WPCF7-Content-Type: ' . $type, 'Reply-To: visitor@example.com', ''];
					if ('text/html' === $type) { $headers[] = 'Content-Type: text/html; charset=UTF-8'; }
					$fixture = dirname(__DIR__) . '/fixtures/cf7-attachment.txt';
					$this->assertTrue($bridge->maybe_send(null, ['to'=>'recipient@example.com', 'subject'=>'CF7 test', 'message'=>'Synthetic body', 'headers'=>$stringHeaders ? implode("\r\n", $headers) : $headers, 'attachments'=>[$fixture]]), $id . ' ' . $type);
					$payload = json_decode($provider->requests[count($provider->requests)-1]['body'], true);
					$this->assertStringNotContainsString('x-wpcf7-content-type', strtolower(json_encode($payload)));
					if ('postmark' === $id) {
						$this->assertSame('Synthetic body', $payload['text/html' === $type ? 'HtmlBody' : 'TextBody']);
						$this->assertSame('visitor@example.com', $payload['ReplyTo']);
						$this->assertSame(base64_encode(file_get_contents($fixture)), $payload['Attachments'][0]['Content']);
					} else {
						$this->assertSame($type, $payload['content'][0]['type']);
						$this->assertSame('Synthetic body', $payload['content'][0]['value']);
						$this->assertSame('visitor@example.com', $payload['reply_to']['email']);
						$this->assertSame(base64_encode(file_get_contents($fixture)), $payload['attachments'][0]['content']);
					}
				}
			}
		}
	}

	public function test_invalid_conflicting_repeated_and_unknown_headers_still_fail_before_network(): void {
		$cases = [
			['X-WPCF7-Content-Type: application/json'],
			['X-WPCF7-Content-Type: text/plain; charset=UTF-8'],
			['X-WPCF7-Content-Type: text/plain', 'x-wpcf7-content-type: text/plain'],
			['Content-Type: text/html', 'X-WPCF7-Content-Type: text/plain'],
			['X-WPCF7-Content-Type: text/html', 'Content-Type: text/plain'],
			["X-WPCF7-Content-Type: text/plain\r\nBcc: hidden@example.com"],
			["X-WPCF7-Content-Type: text/plain\0"],
			['X-WPCF7-Content-Type: text/plain', 'X-Unsafe: value'],
		];
		foreach (['postmark', 'sendgrid'] as $id) {
			foreach ($cases as $headers) {
				[$bridge, $provider] = $this->setupBridge($id);
				$this->assertFalse($bridge->maybe_send(null, ['to'=>'recipient@example.com', 'subject'=>'Test', 'message'=>'Body', 'headers'=>$headers, 'attachments'=>[]]));
				$this->assertSame([], $provider->requests);
			}
		}
	}
}
