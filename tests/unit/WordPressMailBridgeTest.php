<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\WordPressMailBridge;
use Scalyn\MailRelay\Providers\SendGrid\SendGridProvider;

final class BridgeSendGridProvider extends SendGridProvider {
	public int $code = 202;
	public array $requests = array();

	protected function post( array $args ): mixed {
		$this->requests[] = $args;
		return array( 'response' => array( 'code' => $this->code ) );
	}

	protected function response_code( mixed $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

final class WordPressMailBridgeTest extends TestCase {
	private const KEY = 'SG.synthetic_test_credential_0123456789';
	private WordPressMailBridge $bridge;
	private BridgeSendGridProvider $provider;
	private SettingsRepository $settings;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_actions'] = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$GLOBALS['_test_wp_filters'] = array();
		$cipher = new CredentialCipher( base64_encode( str_repeat( 'q', 32 ) ) );
		$this->settings = new SettingsRepository( $cipher );
		$this->settings->save_sendgrid(
			array(
				'key_action' => 'replace',
				'api_key' => self::KEY,
				'from_email' => 'sender@example.com',
				'from_name' => 'Scalyn',
			),
			$cipher
		);
		$this->provider = new BridgeSendGridProvider();
		$registry = new ProviderRegistry();
		$registry->register( $this->provider );
		$this->bridge = new WordPressMailBridge( $this->settings, new MailDispatcher( $registry, $this->settings ) );
	}

	private function atts( array $changes = array() ): array {
		return array_replace(
			array(
				'to' => 'recipient@example.com',
				'subject' => 'Subject',
				'message' => 'Body',
				'headers' => array(),
				'attachments' => array(),
				'embeds' => array(),
			),
			$changes
		);
	}

	public function test_only_selected_sendgrid_short_circuits_wordpress_mail(): void {
		$this->assertNull( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$this->bridge->register();
		$this->assertTrue( apply_filters( 'pre_wp_mail', null, $this->atts() ) );
		$this->assertCount( 1, $this->provider->requests );
		$this->assertSame( 'prior', $this->bridge->maybe_send( 'prior', $this->atts() ) );
		$this->assertCount( 1, $this->provider->requests );
	}

	public function test_standard_wordpress_fields_map_without_leaking_key_to_events(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$events = array();
		$GLOBALS['_test_wp_actions'][HookNames::MAIL_SENT] = static function( $result, $message ) use ( &$events ): void {
			$events[] = array( $result->provider, $message->uuid, $message->content_type );
		};
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'Content-Type: text/html; charset=UTF-8', 'Cc: cc@example.com', 'Reply-To: reply@example.com', 'From: Scalyn <sender@example.com>' ) ) ) ) );
		$payload = json_decode( $this->provider->requests[0]['body'], true );
		$this->assertSame( 'text/html', $payload['content'][0]['type'] );
		$this->assertSame( 'sender@example.com', $payload['from']['email'] );
		$this->assertSame( 'Scalyn', $payload['from']['name'] );
		$this->assertSame( 'cc@example.com', $payload['personalizations'][0]['cc'][0]['email'] );
		$this->assertSame( 'reply@example.com', $payload['reply_to']['email'] );
		$this->assertSame( $events[0][1], $payload['custom_args']['scalyn_message_uuid'] );
		$this->assertStringNotContainsString( self::KEY, json_encode( $events ) );
	}

	public function test_unsupported_shapes_fail_closed_before_network(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'From: other@example.com' ) ) ) ) );
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts( array( 'embeds' => array( 'image.png' ) ) ) ) );
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'X-Unsafe: value' ) ) ) ) );
		$this->assertSame( array(), $this->provider->requests );
	}

	public function test_missing_response_is_unconfirmed_not_failed(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$this->provider->code = 0;
		$events = array();
		$GLOBALS['_test_wp_actions'][HookNames::MAIL_OUTCOME_UNCONFIRMED] = static function( $result ) use ( &$events ): void { $events[] = $result; };
		$GLOBALS['_test_wp_actions'][HookNames::MAIL_FAILED] = static function() use ( &$events ): void { $events[] = 'failed'; };
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]->acceptance_unconfirmed );
		$this->assertFalse( $events[0]->retryable );
	}

	public function test_rotated_key_fails_closed_without_network_or_secret_exposure(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$other = new SettingsRepository( new CredentialCipher( base64_encode( str_repeat( 'z', 32 ) ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $this->provider );
		$bridge = new WordPressMailBridge( $other, new MailDispatcher( $registry, $other ) );
		$this->assertFalse( $bridge->maybe_send( null, $this->atts() ) );
		$this->assertSame( array(), $this->provider->requests );
	}

	public function test_real_adapter_dispatcher_and_repositories_preserve_uuid_and_privacy(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$subscriber = new \Scalyn\MailRelay\Logging\MailEventSubscriber( new \Scalyn\MailRelay\Logging\MailLogRepository(), new \Scalyn\MailRelay\Logging\TimelineRepository() );
		$subscriber->register();
		try {
			foreach ( array( 202 => 'accepted', 401 => 'failed', 429 => 'failed', 500 => 'prepared', 0 => 'prepared' ) as $code => $status ) {
				$GLOBALS['wpdb'] = $db = new WpdbStub();
				$this->provider->code = $code;
				$this->provider->requests = array();
				$this->assertSame( 202 === $code, $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'Bcc: confidential@example.com' ) ) ) ) );
				$this->assertCount( 1, $this->provider->requests );
				$this->assertCount( 2, $db->inserts );
				$payload = json_decode( $this->provider->requests[0]['body'], true );
				$log = $db->inserts[0]['data'];
				$event = $db->inserts[1]['data'];
				$this->assertSame( $payload['custom_args']['scalyn_message_uuid'], $log['message_uuid'] );
				$this->assertSame( $log['message_uuid'], $event['message_uuid'] );
				$this->assertSame( $status, $log['status'] );
				$this->assertSame( $status, $event['event_status'] );
				$this->assertSame( 'wordpress', $log['source_type'] );
				$this->assertSame( 'wp_mail', $log['source_name'] );
				$this->assertSame( 'sendgrid', $log['provider'] );
				$this->assertSame( 'prepared' === $status ? 'mail_outcome_unconfirmed' : ( 'accepted' === $status ? 'mail_sent' : 'mail_failed' ), $event['event_type'] );
				if ( 'prepared' === $status ) {
					$this->assertNull( $log['sent_at'] );
					$this->assertNull( $log['failed_at'] );
				}
				foreach ( array( self::KEY, 'Subject', 'Body', 'confidential@example.com', 'recipient@example.com', 'sender@example.com' ) as $private ) {
					$this->assertStringNotContainsString( $private, serialize( $db->inserts ) );
				}
			}
		} finally {
			unset( $GLOBALS['wpdb'] );
			$GLOBALS['_test_wp_added_actions'] = array();
		}
	}

	public function test_preparation_failure_has_a_private_correlated_log_without_sending(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$GLOBALS['wpdb'] = $db = new WpdbStub();
		( new \Scalyn\MailRelay\Logging\MailEventSubscriber( new \Scalyn\MailRelay\Logging\MailLogRepository(), new \Scalyn\MailRelay\Logging\TimelineRepository() ) )->register();
		try {
			$this->assertFalse( $this->bridge->maybe_send( null, $this->atts( array( 'embeds' => array( 'private.png' ) ) ) ) );
			$this->assertSame( array(), $this->provider->requests );
			$this->assertCount( 2, $db->inserts );
			$log = $db->inserts[0]['data'];
			$this->assertSame( 'failed', $log['status'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/', $log['message_uuid'] );
			$this->assertSame( $log['message_uuid'], $db->inserts[1]['data']['message_uuid'] );
			foreach ( array( 'private.png', 'recipient@example.com', 'Subject', 'Body', self::KEY ) as $private ) {
				$this->assertStringNotContainsString( $private, serialize( $db->inserts ) );
			}
		} finally {
			unset( $GLOBALS['wpdb'] );
			$GLOBALS['_test_wp_added_actions'] = array();
		}
	}

	public function test_observer_exception_cannot_reverse_acceptance_or_send_again(): void {
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$GLOBALS['_test_wp_actions'][HookNames::MAIL_SENT] = static function(): void { throw new \RuntimeException( 'private observer detail' ); };
		$failures = 0;
		$GLOBALS['_test_wp_actions'][HookNames::MAIL_FAILED] = static function() use ( &$failures ): void { ++$failures; };
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->assertCount( 1, $this->provider->requests );
		$this->assertSame( 0, $failures );
	}
}
