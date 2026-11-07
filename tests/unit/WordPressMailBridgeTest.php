<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\WordPressMailBridge;
use Scalyn\MailRelay\Providers\SendGrid\SendGridProvider;

// Minimal native payload type fixture; no WordPress/network bootstrap.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message, public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

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

final class BridgeSmtpProvider extends \Scalyn\MailRelay\Providers\Smtp\SmtpProvider {
	public bool $success = true;
	public bool $throws = false;
	public array $requests = array();

	public function send( \Scalyn\MailRelay\Mail\MailMessage $message, array $config ): \Scalyn\MailRelay\Mail\SendResult {
		$this->requests[] = array( 'message' => $message, 'config' => $config );
		if ( $this->throws ) {
			throw new \RuntimeException( 'Synthetic transport failure.' );
		}
		return new \Scalyn\MailRelay\Mail\SendResult( $this->success, 'smtp' );
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

	private function routed_sender(): string {
		$GLOBALS['_test_current_user_can'] = array( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS => true, 'manage_options' => true );
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$this->settings->configure( 'import' );
		$default = $this->settings->get_active_configuration_id();
		$this->settings->configure( 'create', 'sendgrid', 'Routed sender' );
		$items = $this->settings->get_configurations();
		$route = $items[1]['id'];
		$this->settings->configure( 'select', $route );
		$this->settings->save_sendgrid(
			array( 'key_action' => 'replace', 'api_key' => self::KEY, 'from_email' => 'route@example.com', 'from_name' => 'Route' ),
			new CredentialCipher( base64_encode( str_repeat( 'q', 32 ) ) )
		);
		$this->settings->configure( 'select', $default );
		( new \Scalyn\MailRelay\Mail\RoutingRepository() )->save( true, array( 'unknown/default' => array( $route ) ), '', $this->settings );
		return $route;
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_user_can'] = array();
		$GLOBALS['_test_wp_filters'] = array();
	}

	private function selected_smtp(): BridgeSmtpProvider {
		$this->settings->save(
			array(
				'provider' => array( 'active' => 'smtp' ),
				'smtp' => array( 'host' => 'smtp.example.test', 'from_email' => 'smtp@example.com', 'from_name' => 'SMTP sender' ),
			)
		);
		$provider = new BridgeSmtpProvider();
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$this->bridge = new WordPressMailBridge( $this->settings, new MailDispatcher( $registry, $this->settings ) );
		$this->bridge->register();
		return $provider;
	}

	public function test_real_smtp_adapter_preserves_configured_sender_name(): void {
		$this->selected_smtp();
		$mailer = new \PHPMailer\PHPMailer\PHPMailer();
		$provider = new class( $mailer ) extends \Scalyn\MailRelay\Providers\Smtp\SmtpProvider {
			public function __construct( private \PHPMailer\PHPMailer\PHPMailer $mailer ) {}
			protected function create_mailer(): \PHPMailer\PHPMailer\PHPMailer { return $this->mailer; }
		};
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$bridge = new WordPressMailBridge( $this->settings, new MailDispatcher( $registry, $this->settings ) );
		foreach ( array( array(), array( 'From: SMTP sender <smtp@example.com>' ) ) as $headers ) {
			$this->assertTrue( $bridge->maybe_send( null, $this->atts( array( 'headers' => $headers ) ) ) );
			$this->assertSame( 'SMTP sender', $mailer->FromName );
			$this->assertSame( 'smtp@example.com', $mailer->From );
		}
		$this->assertSame( 2, $mailer->send_calls );
		$this->assertSame( 2, $mailer->connect_calls );
	}

	public function test_default_smtp_publishes_native_success_mail_data(): void {
		$provider = $this->selected_smtp();
		$events = array();
		$GLOBALS['_test_wp_actions']['wp_mail_succeeded'] = static function( $data ) use ( &$events ): void { $events[] = $data; };
		$failures = 0;
		$GLOBALS['_test_wp_actions']['wp_mail_failed'] = static function() use ( &$failures ): void { ++$failures; };
		$atts = $this->atts( array( 'private_config' => 'synthetic-private-not-mail-data' ) );
		$this->assertTrue( $this->bridge->maybe_send( null, $atts ) );
		$this->assertCount( 1, $provider->requests );
		$this->assertSame( 0, $failures );
		$this->assertSame( array( array( 'to' => array( 'recipient@example.com' ), 'subject' => 'Subject', 'message' => 'Body', 'headers' => array(), 'attachments' => array() ) ), $events );
	}

	public function test_default_smtp_preparation_and_transport_failures_publish_one_safe_native_error(): void {
		$provider = $this->selected_smtp();
		$events = array();
		$GLOBALS['_test_wp_actions']['wp_mail_failed'] = static function( $error ) use ( &$events ): void { $events[] = $error; };
		$successes = 0;
		$GLOBALS['_test_wp_actions']['wp_mail_succeeded'] = static function() use ( &$successes ): void { ++$successes; };
		foreach ( array( 'preparation', 'transport', 'exception' ) as $failure ) {
			$events = array();
			$provider->success = false;
			$provider->throws = 'exception' === $failure;
			$atts = $this->atts( 'preparation' === $failure ? array( 'headers' => array( "From: injected\r\nBcc: hidden@example.com" ) ) : array() );
			$this->assertFalse( $this->bridge->maybe_send( null, $atts ) );
			$this->assertCount( 1, $events );
			$this->assertInstanceOf( WP_Error::class, $events[0] );
			$this->assertSame( 'wp_mail_failed', $events[0]->get_error_code() );
			$this->assertSame( 'WordPress SMTP mail could not be accepted. Check mail diagnostics before retrying.', $events[0]->get_error_message() );
			$this->assertNull( $events[0]->get_error_data() );
		}
		$this->assertCount( 2, $provider->requests );
		$this->assertSame( 0, $successes );
	}

	public function test_native_success_observer_throw_preserves_acceptance_and_one_attempt(): void {
		$provider = $this->selected_smtp();
		$successes = 0;
		$failures = 0;
		$GLOBALS['_test_wp_actions']['wp_mail_succeeded'] = static function() use ( &$successes ): void { ++$successes; throw new \Error( 'Synthetic private observer failure' ); };
		$GLOBALS['_test_wp_actions']['wp_mail_failed'] = static function() use ( &$failures ): void { ++$failures; };
		$this->assertTrue( apply_filters( 'pre_wp_mail', null, $this->atts() ) );
		$this->assertCount( 1, $provider->requests );
		$this->assertSame( 1, $successes );
		$this->assertSame( 0, $failures );
	}

	public function test_native_failure_observer_throw_preserves_false_without_retry(): void {
		$provider = $this->selected_smtp();
		$provider->success = false;
		$failures = 0;
		$GLOBALS['_test_wp_actions']['wp_mail_failed'] = static function() use ( &$failures ): void { ++$failures; throw new \Error( 'Synthetic private observer failure' ); };
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->assertSame( 1, $failures );
		$this->assertCount( 1, $provider->requests );
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts( array( 'embeds' => array( 'unsupported' ) ) ) ) );
		$this->assertSame( 2, $failures );
		$this->assertCount( 1, $provider->requests );
	}

	public function test_prior_non_null_results_do_not_publish_native_events_or_send(): void {
		$provider = $this->selected_smtp();
		$native_events = 0;
		foreach ( array( 'wp_mail_succeeded', 'wp_mail_failed' ) as $hook ) {
			$GLOBALS['_test_wp_actions'][$hook] = static function() use ( &$native_events ): void { ++$native_events; };
		}
		foreach ( array( true, false, 'prior', 0 ) as $prior ) {
			$this->assertSame( $prior, $this->bridge->maybe_send( $prior, $this->atts() ) );
		}
		$this->assertSame( array(), $provider->requests );
		$this->assertSame( 0, $native_events );
	}

	public function test_api_and_advanced_routing_do_not_gain_native_outcome_hooks(): void {
		$native_events = 0;
		foreach ( array( 'wp_mail_succeeded', 'wp_mail_failed' ) as $hook ) {
			$GLOBALS['_test_wp_actions'][$hook] = static function() use ( &$native_events ): void { ++$native_events; };
		}
		$this->settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->routed_sender();
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->assertCount( 2, $this->provider->requests );
		$this->assertSame( 0, $native_events );
	}

	public function test_selected_smtp_advanced_and_recovery_ownership_do_not_publish_native_hooks(): void {
		$provider = $this->selected_smtp();
		$native_events = 0;
		foreach ( array( 'wp_mail_succeeded', 'wp_mail_failed' ) as $hook ) {
			$GLOBALS['_test_wp_actions'][$hook] = static function() use ( &$native_events ): void { ++$native_events; };
		}
		$GLOBALS['_test_current_user_can'] = array( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS => true, 'manage_options' => true );
		$this->settings->configure( 'import' );
		$routes = new \Scalyn\MailRelay\Mail\RoutingRepository();
		$routes->save( true, array(), '', $this->settings );
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->assertCount( 1, $provider->requests );
		$routes->save( false, array(), $routes->policy()['token'], $this->settings );
		$queue = new class extends \Scalyn\MailRelay\Database\RecoveryQueueRepository {
			public int $owned = 0;
			public function enqueue( string $logical, string $payload, int $now ): void { ++$this->owned; }
		};
		$GLOBALS['_test_wp_options'][\Scalyn\MailRelay\Recovery\RecoveryPolicyRepository::OPTION] = array( 'enabled' => true );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$dispatcher = new MailDispatcher( $registry, $this->settings );
		$payload = new \Scalyn\MailRelay\Recovery\RecoveryPayload( new CredentialCipher( base64_encode( str_repeat( 'q', 32 ) ) ) );
		$recovery = new \Scalyn\MailRelay\Recovery\RecoveryService( $queue, new \Scalyn\MailRelay\Recovery\RecoveryPolicyRepository(), $payload, $dispatcher, $this->settings );
		$bridge = new WordPressMailBridge( $this->settings, $dispatcher, $recovery );
		$this->assertTrue( $bridge->maybe_send( null, $this->atts() ) );
		$this->assertSame( 1, $queue->owned );
		$this->assertCount( 1, $provider->requests );
		$this->assertSame( 0, $native_events );
	}

	public function test_selected_smtp_sends_synchronously_without_routing_or_recovery(): void {
		$provider = $this->selected_smtp();
		$this->assertFalse( ( new \Scalyn\MailRelay\Mail\RoutingRepository() )->policy()['enabled'] );
		$this->assertFalse( ( new \Scalyn\MailRelay\Recovery\RecoveryPolicyRepository() )->policy()['enabled'] );
		$this->assertTrue( apply_filters( 'pre_wp_mail', null, $this->atts() ) );
		$this->assertCount( 1, $provider->requests );
		$request = $provider->requests[0];
		$this->assertSame( 'smtp@example.com', $request['message']->from );
		$this->assertSame( array( 'recipient@example.com' ), $request['message']->to );
		$this->assertSame( 'Subject', $request['message']->subject );
		$this->assertSame( 'Body', $request['message']->body );
		$this->assertSame( 'smtp.example.test', $request['config']['host'] );
		$this->assertSame( 'SMTP sender', $request['config']['from_name'] );
	}

	public function test_selected_smtp_preserves_earlier_boolean_short_circuits(): void {
		$provider = $this->selected_smtp();
		foreach ( array( true, false ) as $prior ) {
			$this->assertSame( $prior, apply_filters( 'pre_wp_mail', $prior, $this->atts() ) );
		}
		$this->assertSame( array(), $provider->requests );
	}

	public function test_selected_smtp_unsupported_shapes_fail_closed_before_transport(): void {
		$provider = $this->selected_smtp();
		foreach ( array(
			array( 'headers' => array( 'From: other@example.com' ) ),
			array( 'headers' => array( 'From: smtp@example.com', 'From: smtp@example.com' ) ),
			array( 'headers' => array( "Reply-To: safe@example.com\r\nBcc: hidden@example.com" ) ),
			array( 'headers' => array( 'Content-Type: multipart/mixed' ) ),
			array( 'embeds' => array( 'image.png' ) ),
			array( 'attachments' => array( array( 'unsupported' ) ) ),
		) as $changes ) {
			// Only null permits WordPress core to fall through to its native transport.
			$this->assertFalse( apply_filters( 'pre_wp_mail', null, $this->atts( $changes ) ) );
		}
		$this->assertSame( array(), $provider->requests );
	}

	public function test_selected_smtp_send_failure_prevents_native_fallback(): void {
		$provider = $this->selected_smtp();
		$provider->success = false;
		$this->assertFalse( apply_filters( 'pre_wp_mail', null, $this->atts() ) );
		$this->assertCount( 1, $provider->requests );
	}

	public function test_selected_smtp_transport_exception_prevents_native_fallback(): void {
		$provider = $this->selected_smtp();
		$provider->throws = true;
		$this->assertFalse( apply_filters( 'pre_wp_mail', null, $this->atts() ) );
		$this->assertCount( 1, $provider->requests );
	}

	public function test_selected_smtp_missing_registered_transport_prevents_native_fallback(): void {
		$provider = $this->selected_smtp();
		$bridge = new WordPressMailBridge( $this->settings, new MailDispatcher( new ProviderRegistry(), $this->settings ) );
		$this->assertFalse( $bridge->maybe_send( null, $this->atts() ) );
		$this->assertSame( array(), $provider->requests );
	}

	public function test_selected_smtp_preserves_matching_configured_sender(): void {
		$provider = $this->selected_smtp();
		$this->assertTrue( apply_filters( 'pre_wp_mail', null, $this->atts( array( 'headers' => array( 'From: SMTP sender <smtp@example.com>' ) ) ) ) );
		$this->assertCount( 1, $provider->requests );
		$this->assertSame( 'smtp@example.com', $provider->requests[0]['message']->from );
	}

	public function test_route_sender_is_validated_and_preserved_in_provider_payload(): void {
		$this->routed_sender();
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'From: Route <route@example.com>' ) ) ) ) );
		$this->assertCount( 1, $this->provider->requests );
		$payload = json_decode( $this->provider->requests[0]['body'], true );
		$this->assertSame( 'route@example.com', $payload['from']['email'] );
		$this->assertSame( 'Route', $payload['from']['name'] );
		$this->assertSame( 'sender@example.com', $this->settings->get_sendgrid_settings()['from_email'] );
	}

	public function test_default_sender_is_not_silently_replaced_by_route_sender(): void {
		$this->routed_sender();
		$failures = array();
		$GLOBALS['_test_wp_actions'][HookNames::MAIL_FAILED] = static function( $result, $message ) use ( &$failures ): void {
			$failures[] = array( $result, $message );
		};
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'From: sender@example.com' ) ) ) ) );
		$this->assertSame( array(), $this->provider->requests );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'config', $failures[0][0]->failure_category );
		$this->assertSame( '', $failures[0][1]->from );
		$this->assertSame( array(), $failures[0][1]->to );
		foreach ( array( 'sender@example.com', 'Subject', 'Body', self::KEY ) as $private ) {
			$this->assertStringNotContainsString( $private, serialize( $failures ) );
		}
	}

	public function test_no_from_header_uses_route_sender(): void {
		$this->routed_sender();
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts() ) );
		$payload = json_decode( $this->provider->requests[0]['body'], true );
		$this->assertSame( 'route@example.com', $payload['from']['email'] );
	}

	public function test_smtp_default_does_not_override_api_route_sender(): void {
		$this->routed_sender();
		$this->settings->configure( 'create', 'smtp', 'SMTP default' );
		$items = $this->settings->get_configurations();
		$this->settings->configure( 'select', $items[2]['id'] );
		$this->settings->save( array( 'smtp' => array( 'host' => 'smtp.example.test', 'from_email' => 'smtp@example.com' ) ) );
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'From: route@example.com' ) ) ) ) );
		$this->assertCount( 1, $this->provider->requests );
		$payload = json_decode( $this->provider->requests[0]['body'], true );
		$this->assertSame( 'route@example.com', $payload['from']['email'] );
		$this->assertSame( 'smtp', $this->settings->get_active_provider_id() );
	}

	public function test_archived_route_fails_closed_without_default_fallback(): void {
		$route = $this->routed_sender();
		$this->settings->configure( 'archive', $route );
		$this->assertFalse( $this->bridge->maybe_send( null, $this->atts() ) );
		$this->assertSame( array(), $this->provider->requests );
	}

	public function test_synchronous_send_keeps_route_pinned_before_content_type_filters(): void {
		$this->routed_sender();
		add_filter( 'wp_mail_content_type', function( $type ) {
			$routes = new \Scalyn\MailRelay\Mail\RoutingRepository();
			$routes->save( true, array(), $routes->policy()['token'], $this->settings );
			return $type;
		} );
		$this->assertTrue( $this->bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'From: route@example.com' ) ) ) ) );
		$this->assertCount( 1, $this->provider->requests );
		$payload = json_decode( $this->provider->requests[0]['body'], true );
		$this->assertSame( 'route@example.com', $payload['from']['email'] );
	}

	public function test_recovery_keeps_the_route_validated_before_content_type_filters(): void {
		$native_events = 0;
		foreach ( array( 'wp_mail_succeeded', 'wp_mail_failed' ) as $hook ) {
			$GLOBALS['_test_wp_actions'][$hook] = static function() use ( &$native_events ): void { ++$native_events; };
		}
		$route = $this->routed_sender();
		$payloads = new \Scalyn\MailRelay\Recovery\RecoveryPayload( new CredentialCipher( base64_encode( str_repeat( 'q', 32 ) ) ) );
		$queue = new class extends \Scalyn\MailRelay\Database\RecoveryQueueRepository {
			public array $owned = array();
			public function enqueue( string $logical, string $payload, int $now ): void {
				$this->owned = array( 'logical' => $logical, 'payload' => $payload );
			}
		};
		$GLOBALS['_test_wp_options'][\Scalyn\MailRelay\Recovery\RecoveryPolicyRepository::OPTION] = array( 'enabled' => true );
		$registry = new ProviderRegistry();
		$registry->register( $this->provider );
		$dispatcher = new MailDispatcher( $registry, $this->settings );
		$recovery = new \Scalyn\MailRelay\Recovery\RecoveryService( $queue, new \Scalyn\MailRelay\Recovery\RecoveryPolicyRepository(), $payloads, $dispatcher, $this->settings );
		$bridge = new WordPressMailBridge( $this->settings, $dispatcher, $recovery );
		add_filter( 'wp_mail_content_type', function( $type ) {
			$routes = new \Scalyn\MailRelay\Mail\RoutingRepository();
			$routes->save( true, array(), $routes->policy()['token'], $this->settings );
			return $type;
		} );
		$this->assertTrue( $bridge->maybe_send( null, $this->atts( array( 'headers' => array( 'From: route@example.com' ) ) ) ) );
		$this->assertSame( array(), $this->provider->requests );
		$data = $payloads->open( $queue->owned['payload'], $queue->owned['logical'] );
		$this->assertSame( $route, $data['plan'][0]['id'] );
		$this->assertSame( 'route@example.com', $data['from'] );
		$this->assertSame( $data['from'], $data['plan'][0]['from'] );
		$this->assertSame( 0, $native_events );
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
