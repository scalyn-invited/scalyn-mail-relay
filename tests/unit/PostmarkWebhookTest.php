<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Providers\Postmark\WebhookAuthenticator;
use Scalyn\MailRelay\Providers\Postmark\WebhookNormalizer;

final class PostmarkWebhookTest extends TestCase {
	private const SOURCE = '12345678-1234-4234-8234-123456789abc';
	private const MESSAGE = '22345678-1234-4234-8234-123456789abc';
	private const ATTEMPT = '32345678-1234-4234-8234-123456789abc';
	private const USER = 'synthetic_source_username';
	private const PASSWORD = 'synthetic_webhook_password_0123456789';

	public function test_authentication_requires_tls_exact_credentials_and_allowed_peer(): void {
		$auth = new WebhookAuthenticator();
		$header = 'Basic ' . base64_encode(self::USER . ':' . self::PASSWORD);
		$this->assertTrue($auth->verify($header, self::USER, self::PASSWORD, true, '192.0.2.1', ['192.0.2.1']));
		$this->assertTrue($auth->verify($header, self::USER, self::PASSWORD, true, '2001:db8::1', ['2001:db8:0:0:0:0:0:1']));
		foreach ([
			[$header, false, '192.0.2.1', ['192.0.2.1']],
			[$header, true, '192.0.2.2', ['192.0.2.1']],
			[$header, true, '192.0.2.1', []],
			[$header, true, '192.0.2.1', ['192.0.2.1', 'invalid']],
			[$header, true, '192.0.2.1, 192.0.2.2', ['192.0.2.1']],
			[$header, true, '192.0.2.1', ['192.0.2.0/24']],
			['Bearer private', true, '192.0.2.1', ['192.0.2.1']],
			['Basic !!!', true, '192.0.2.1', ['192.0.2.1']],
			[$header . "\r\n", true, '192.0.2.1', ['192.0.2.1']],
			[$header . ', ' . $header, true, '192.0.2.1', ['192.0.2.1']],
			['Basic ' . base64_encode('wrong:' . self::PASSWORD), true, '192.0.2.1', ['192.0.2.1']],
			['Basic ' . base64_encode(self::USER . ':wrong'), true, '192.0.2.1', ['192.0.2.1']],
			[str_repeat('x', 2049), true, '192.0.2.1', ['192.0.2.1']],
		] as [$value, $tls, $peer, $ips]) {
			$this->assertFalse($auth->verify($value, self::USER, self::PASSWORD, $tls, $peer, $ips));
		}
		$this->assertFalse($auth->verify($header, '', '', true, '192.0.2.1', ['192.0.2.1']));
		$this->assertStringNotContainsString(self::PASSWORD, serialize($auth));
	}

	private function event(array $changes = []): array {
		return array_replace(['RecordType'=>'Delivery', 'ServerID'=>123, 'MessageStream'=>'outbound', 'MessageID'=>self::MESSAGE, 'Recipient'=>'recipient@example.com', 'DeliveredAt'=>'2026-10-02T08:00:00.1234567+08:00', 'Metadata'=>['scalyn_message_uuid'=>self::ATTEMPT, 'private'=>'secret'], 'Details'=>'private response', 'Content'=>'private body'], $changes);
	}

	private function normalize(array $event, ?callable $resolver = null): ?array {
		return (new WebhookNormalizer())->normalize(json_encode($event), self::SOURCE, 123, 'outbound', new DateTimeImmutable('2026-10-02T00:01:00Z'), $resolver ?? static fn($id, $hint, $recipient) => ['message_uuid'=>self::ATTEMPT, 'recipient_token'=>hash('sha256', $recipient)]);
	}

	public function test_normalization_is_allowlisted_correlated_and_deterministic(): void {
		$event = $this->event();
		$first = $this->normalize($event);
		$this->assertSame('2026-10-02T00:00:00.123456Z', $first['occurred_at']);
		$this->assertSame('delivery', $first['kind']);
		$this->assertSame(self::ATTEMPT, $first['message_uuid']);
		$this->assertCount(12, $first);
		$this->assertSame($first['event_key'], $this->normalize($event)['event_key']);
		$event['Details'] = 'different irrelevant response';
		$this->assertSame($first['event_key'], $this->normalize($event)['event_key']);
		$event['Recipient'] = 'second@example.com';
		$this->assertNotSame($first['event_key'], $this->normalize($event)['event_key']);
		foreach (['recipient@example.com', 'private', 'secret', 'Details', 'Content'] as $private) {
			$this->assertStringNotContainsString($private, json_encode($first));
		}
	}

	public function test_bounce_mapping_and_event_identity_do_not_replace_delivery(): void {
		$event = $this->event(['RecordType'=>'Bounce','Email'=>'recipient@example.com','BouncedAt'=>'2026-10-02T00:00:00Z','ID'=>456,'Type'=>'HardBounce']);
		$first = $this->normalize($event);
		$this->assertSame('bounce', $first['kind']);
		$this->assertSame('hard_bounce', $first['reason_code']);
		$this->assertNotSame($first['event_key'], $this->normalize($this->event())['event_key']);
		$event['Type'] = 'SoftBounce';
		$this->assertSame('soft_bounce', $this->normalize($event)['reason_code']);
		$this->assertSame($first['event_key'], $this->normalize($event)['event_key']);
		$event['Type'] = 'UnknownPrivateType';
		$this->assertSame('unknown_bounce', $this->normalize($event)['reason_code']);
		$event['ID']++;
		$this->assertNotSame($first['event_key'], $this->normalize($event)['event_key']);
	}

	public function test_unmatched_and_unsupported_events_create_no_evidence(): void {
		$this->assertNull($this->normalize($this->event(), static fn() => null));
		$this->assertNull($this->normalize($this->event(['RecordType'=>'Open']), static function() { throw new RuntimeException('Must not resolve'); }));
		$called = false;
		$this->normalize($this->event(['Metadata'=>new stdClass()]), function($id, $hint, $recipient) use (&$called) {
			$called = true;
			$this->assertSame(self::MESSAGE, $id);
			$this->assertNull($hint);
			$this->assertSame('recipient@example.com', $recipient);
			return null;
		});
		$this->assertTrue($called);
	}

	public function test_invalid_fields_fail_before_resolver_with_safe_errors(): void {
		foreach ([['ServerID'=>124], ['ServerID'=>'123'], ['MessageStream'=>'broadcast'], ['MessageID'=>'private'], ['Recipient'=>"private\r\n@example.com"], ['DeliveredAt'=>'tomorrow'], ['DeliveredAt'=>'2026-02-30T00:00:00Z'], ['DeliveredAt'=>'2026-10-02T00:06:01Z'], ['DeliveredAt'=>'2026-10-02T00:00:00'], ['DeliveredAt'=>'2026-10-02T00:00:00+14:30'], ['Metadata'=>[]], ['Metadata'=>['scalyn_message_uuid'=>'private']], ['RecordType'=>'Bounce','Email'=>'recipient@example.com','BouncedAt'=>'2026-10-02T00:00:00Z','ID'=>0,'Type'=>'HardBounce']] as $changes) {
			try {
				$this->normalize($this->event($changes), static function() { throw new RuntimeException('Must not resolve'); });
				$this->fail('Invalid event was accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertContains($error->getMessage(), ['Invalid webhook recipient.', 'Invalid webhook event time.', 'Invalid webhook correlation.', 'Invalid webhook bounce.', 'Webhook source or message is invalid.']);
				$this->assertStringNotContainsString('private', $error->getMessage());
			}
		}
	}

	public function test_invalid_json_size_and_match_are_rejected(): void {
		foreach (['[]','null','{','{"RecordType":1}',str_repeat(' ', WebhookNormalizer::MAX_BYTES + 1)] as $body) {
			try {
				(new WebhookNormalizer())->normalize($body, self::SOURCE, 123, 'outbound', new DateTimeImmutable(), static fn()=>null);
				$this->fail('Invalid body was accepted');
			} catch (InvalidArgumentException $error) {
				$this->assertSame('Invalid webhook envelope.', $error->getMessage());
			}
		}
		$this->expectException(InvalidArgumentException::class);
		$this->normalize($this->event(), static fn()=>['message_uuid'=>self::MESSAGE,'recipient_token'=>str_repeat('a',64)]);
	}

	public function test_time_identity_preserves_precision_and_normalizes_offsets(): void {
		$one = $this->normalize($this->event());
		$two = $this->normalize($this->event(['DeliveredAt'=>'2026-10-02T00:00:00.1234567Z']));
		$this->assertSame($one['event_key'], $two['event_key']);
		$three = $this->normalize($this->event(['DeliveredAt'=>'2026-10-02T00:00:00.1234568Z']));
		$this->assertNotSame($one['event_key'], $three['event_key']);
		$this->assertSame($this->normalize($this->event(['DeliveredAt'=>'2026-10-02T00:00:00Z']))['event_key'], $this->normalize($this->event(['DeliveredAt'=>'2026-10-02T00:00:00.0000000Z']))['event_key']);
	}

	public function test_correlation_failure_does_not_expose_internal_exception(): void {
		try {
			$this->normalize($this->event(), static function() { throw new RuntimeException('private database detail'); });
			$this->fail('Resolver exception must not be acknowledged');
		} catch (RuntimeException $error) {
			$this->assertSame('Webhook correlation is unavailable.', $error->getMessage());
			$this->assertNull($error->getPrevious());
		}
	}
}
