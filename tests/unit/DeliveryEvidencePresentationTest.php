<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;
use Scalyn\MailRelay\Database\DeliveryEventRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Database\WebhookRateLimitRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
use Scalyn\MailRelay\Rest\PostmarkWebhookEndpoint;

final class PresentationServerStub extends PostmarkProvider {
	public array $paths=[];
	public mixed $server=[ 'response'=>[ 'code'=>200 ], 'body'=>'{"ID":4242,"DeliveryType":"Live","ApiTokens":["private-returned-token"]}' ];
	protected function http( string $path, array $args ): mixed { $this->paths[]=$path; return $this->server; }
}

/** Provider helpers, receiver pre-storage paths and evidence rendering. */
final class DeliveryEvidencePresentationTest extends TestCase {
	private const MSG='550e8400-e29b-41d4-a716-446655440000';
	private const SOURCE='bbbbbbbb-2222-4222-8222-222222222222';
	private CredentialCipher $cipher;

	protected function setUp(): void {
		foreach ( [ '_test_wp_options', '_test_registered_rest_routes' ] as $key ) { $GLOBALS[ $key ]=[]; }
		$GLOBALS['_test_current_user_can']=[ Capabilities::MANAGE_MAIL=>true ];
		$GLOBALS['wpdb']=new WpdbStub();
		$this->cipher=new CredentialCipher( base64_encode( str_repeat( 'a', 32 ) ) );
		$_SERVER['REMOTE_ADDR']='192.0.2.10';
		$GLOBALS['_test_is_ssl']=true;
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR'], $GLOBALS['_test_is_ssl'] );
		$GLOBALS['_test_current_user_can']=[];
	}

	public function test_recipient_parsing_matches_send_rules_and_deduplicates(): void {
		$message=new MailMessage( self::MSG, 'sender@example.com', [ 'Alice <Alice@Example.com>', 'bob@example.com' ], 'S', 'B', 'text/plain', [ 'CC: bob@example.com, carol@example.com', 'Bcc: hidden@example.com', 'X-Priority: 1' ] );
		$this->assertSame( [ 'Alice@Example.com', 'bob@example.com', 'carol@example.com', 'hidden@example.com' ], ( new PostmarkProvider() )->recipient_addresses( $message ) );
		foreach ( [ [ 'bad address' ], array_fill( 0, 51, 'a@example.com' ) ] as $to ) {
			try { ( new PostmarkProvider() )->recipient_addresses( new MailMessage( self::MSG, 'sender@example.com', $to, 'S', 'B' ) ); $this->fail( 'Must reject' ); }
			catch ( InvalidArgumentException $error ) { $this->assertStringNotContainsString( 'bad address', $error->getMessage() ); }
		}
	}

	public function test_live_server_id_is_read_only_and_requires_live_server(): void {
		$provider=new PresentationServerStub();
		$config=[ 'api_key'=>'synthetic-server-token-0123', 'from_email'=>'sender@example.com', 'from_name'=>'' ];
		$this->assertSame( 4242, $provider->live_server_id( $config ) );
		$provider->server=[ 'response'=>[ 'code'=>200 ], 'body'=>'{"ID":4242,"DeliveryType":"Sandbox"}' ];
		$this->assertNull( $provider->live_server_id( $config ) );
		$this->assertNull( $provider->live_server_id( [ 'api_key'=>'POSTMARK_API_TEST' ] + $config ) );
		$this->assertSame( [ '/server', '/server' ], $provider->paths, 'Never posts /email' );
	}

	private function endpoint(): PostmarkWebhookEndpoint {
		$sources=new PostmarkWebhookSettings( $this->cipher, new DeliveryKeyRepository( $this->cipher ), new SettingsRepository( $this->cipher ) );
		return new PostmarkWebhookEndpoint( $sources, new SettingsRepository( $this->cipher ), new WebhookRateLimitRepository(), new DeliveryAttemptRepository(), new DeliveryKeyRepository( $this->cipher ), new DeliveryEventRepository() );
	}
	private function source( bool $enabled, string $credentials='' ): void {
		$GLOBALS['_test_wp_options'][ PostmarkWebhookSettings::OPTION ]=[ 'id'=>self::SOURCE, 'server_id'=>4242, 'stream'=>'outbound', 'allowed_ips'=>[ '192.0.2.10' ], 'enabled'=>$enabled, 'credentials'=>'' !== $credentials ? $credentials : $this->cipher->encrypt( wp_json_encode( [ 'username'=>'synthetic_username', 'password'=>'synthetic_password_01234567890123456789' ] ), 'postmark-webhook' ) ];
	}
	private function request( string $source=self::SOURCE ): WP_REST_Request {
		$request=new WP_REST_Request();
		$request->set_param( 'source', $source );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'synthetic_username:synthetic_password_01234567890123456789' ) );
		$request->set_body( '{"RecordType":"Delivery"}' );
		return $request;
	}

	public function test_route_is_registered_only_for_an_existing_source(): void {
		$this->endpoint()->register();
		$this->assertSame( [], $GLOBALS['_test_registered_rest_routes'] );
		$this->source( false );
		$this->endpoint()->register();
		$route=$GLOBALS['_test_registered_rest_routes'][0];
		$this->assertStringStartsWith( '/webhooks/postmark/', $route['route'] );
		$this->assertSame( 'POST', $route['args']['methods'] );
	}

	public function test_unknown_source_unreadable_credentials_and_disabled_source_never_store(): void {
		$this->assertSame( 401, $this->endpoint()->handle( $this->request() )->get_status() );
		$this->source( true );
		$this->assertSame( 401, $this->endpoint()->handle( $this->request( '99999999-9999-4999-8999-999999999999' ) )->get_status() );
		$this->source( true, 'corrupted-envelope' );
		$this->assertSame( 503, $this->endpoint()->handle( $this->request() )->get_status() );
		$this->source( false );
		$response=$this->endpoint()->handle( $this->request() );
		$this->assertSame( 200, $response->get_status(), 'Authenticated disabled source is acknowledged without storage' );
		$this->assertNull( $response->get_data() );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$GLOBALS['_test_is_ssl']=false;
		$this->assertSame( 401, $this->endpoint()->handle( $this->request() )->get_status() );
	}

	private function render_detail( ?array $delivery, array $timeline=[] ): string {
		$message_uuid=self::MSG;
		$uuid_error=false;
		$log=[ 'status'=>'accepted', 'provider'=>'postmark', 'created_at'=>'2026-10-03 00:00:00', 'sent_at'=>'2026-10-03 00:00:01' ];
		ob_start();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/logs-detail.php';
		return (string) ob_get_clean();
	}

	public function test_detail_shows_separate_escaped_evidence_and_keeps_transport_status(): void {
		$html=$this->render_detail( [ 'state'=>'mixed', 'label'=>'Mixed evidence', 'explanation'=>'Delivery confirmed for 1 of 2 recipients; bounce reported for 1. <script>x</script>', 'expected'=>2, 'latest_at'=>'2026-10-03 01:02:03.123456' ] );
		$this->assertStringContainsString( 'Delivery evidence', $html );
		$this->assertStringContainsString( 'scalyn-badge--warning', $html );
		$this->assertStringContainsString( 'Delivery confirmed for 1 of 2 recipients', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( 'Latest report received: 2026-10-03 01:02:03 UTC', $html );
		$this->assertStringContainsString( 'scalyn-badge--accepted', $html, 'Transport status unchanged' );
		$this->assertStringNotContainsString( 'Delivery evidence', $this->render_detail( null ) );
	}

	public function test_delivery_timeline_entry_shows_provider_time_without_private_event_data(): void {
		$html=$this->render_detail( null, [ [ 'event_type'=>'delivery_evidence', 'event_status'=>'', 'event_label'=>'Bounce reported', 'event_message'=>'Postmark reported a bounce for one recipient.', 'created_at'=>'2026-10-03 09:00:00', 'event_data'=>wp_json_encode( [ 'kind'=>'bounce', 'occurred_at'=>'2026-10-03T00:59:00.000000Z', 'reason_code'=>'hard_bounce', 'secret'=>'private-token' ] ) ] ] );
		$this->assertStringContainsString( 'Provider report', $html );
		$this->assertStringContainsString( 'scalyn-badge--warning', $html );
		$this->assertStringContainsString( 'Provider event time: 2026-10-03 00:59:00 UTC', $html );
		$this->assertStringNotContainsString( 'private-token', $html );
		$this->assertStringNotContainsString( 'hard_bounce', $html );
	}
}
