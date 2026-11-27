<?php
/**
 * Unit tests for GmailProvider.
 *
 * Uses a stub subclass to intercept HTTP calls so no network requests are made.
 *
 * @package ScalynMailRelay
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Providers\Gmail\GmailProvider;
use Scalyn\MailRelay\Mail\MailMessage;

/** Stub that intercepts HTTP calls and returns controlled responses. */
class StubGmailProvider extends GmailProvider {
	/** @var array[] Recorded calls: [url, args]. */
	public array $calls = array();
	/** @var mixed Response to return (array) or Throwable to throw. */
	public mixed $response = array( 'response' => array( 'code' => 200 ), 'body' => '{"id":"1234567890abcdef"}' );

	protected function http( string $url, array $args ): mixed {
		$this->calls[] = array( $url, $args );
		if ( $this->response instanceof \Throwable ) {
			throw $this->response;
		}
		return $this->response;
	}
}

final class GmailProviderTest extends TestCase {

	private function config(): array {
		return array(
			'access_token' => 'ya29.synthetic_access_token_for_unit_test_only',
			'from_email'   => 'sender@example.com',
			'from_name'    => 'Test Sender',
		);
	}

	private function message( array $changes = array() ): MailMessage {
		return new MailMessage( ...array_replace(
			array(
				'uuid'         => '12345678-1234-4234-8234-123456789abc',
				'from'         => 'sender@example.com',
				'to'           => array( 'recipient@example.com' ),
				'subject'      => 'Hello World',
				'body'         => '<p>Test body</p>',
				'content_type' => 'text/html',
				'headers'      => array(),
				'attachments'  => array(),
			),
			$changes
		) );
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_provider_identity(): void {
		$p = new GmailProvider();
		$this->assertSame( 'gmail', $p->get_id() );
		$this->assertStringContainsString( 'Gmail', $p->get_label() );
		$this->assertContains( 'html', $p->get_capabilities() );
	}

	// -------------------------------------------------------------------------
	// validate_config
	// -------------------------------------------------------------------------

	public function test_valid_config_passes(): void {
		$this->assertTrue( ( new GmailProvider() )->validate_config( $this->config() )->valid );
	}

	public function test_missing_access_token_fails(): void {
		$r = ( new GmailProvider() )->validate_config( array_merge( $this->config(), array( 'access_token' => '' ) ) );
		$this->assertFalse( $r->valid );
		$this->assertArrayHasKey( 'access_token', $r->errors );
	}

	public function test_invalid_from_email_fails(): void {
		$r = ( new GmailProvider() )->validate_config( array_merge( $this->config(), array( 'from_email' => 'not-an-email' ) ) );
		$this->assertFalse( $r->valid );
		$this->assertArrayHasKey( 'from_email', $r->errors );
	}

	public function test_header_injection_in_from_name_fails(): void {
		$r = ( new GmailProvider() )->validate_config( array_merge( $this->config(), array( 'from_name' => "Name\r\nX-Injected: bad" ) ) );
		$this->assertFalse( $r->valid );
		$this->assertArrayHasKey( 'from_name', $r->errors );
	}

	// -------------------------------------------------------------------------
	// test_connection
	// -------------------------------------------------------------------------

	public function test_connection_success_with_valid_token(): void {
		$p              = new StubGmailProvider();
		$p->response    = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"aud":"client_id","scope":"https://www.googleapis.com/auth/gmail.send","expires_in":3600}',
		);
		$r = $p->test_connection( $this->config() );
		$this->assertTrue( $r->success );
		$this->assertStringContainsString( 'No email was sent', $r->message );
		$this->assertCount( 1, $p->calls );
		// Token appears only as query param in the URL, not in auth header.
		$this->assertStringContainsString( 'tokeninfo', $p->calls[0][0] );
		$this->assertStringNotContainsString( 'Authorization', serialize( $p->calls[0][1] ) );
	}

	public function test_connection_fails_when_scope_missing(): void {
		$p              = new StubGmailProvider();
		$p->response    = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"aud":"client_id","scope":"openid email","expires_in":3600}',
		);
		$r = $p->test_connection( $this->config() );
		$this->assertFalse( $r->success );
		$this->assertStringContainsString( 'scope', $r->message );
	}

	public function test_connection_fails_when_token_invalid(): void {
		$p           = new StubGmailProvider();
		$p->response = array( 'response' => array( 'code' => 400 ), 'body' => '{"error":"invalid_token"}' );
		$r = $p->test_connection( $this->config() );
		$this->assertFalse( $r->success );
	}

	public function test_connection_fails_without_access_token(): void {
		$r = ( new GmailProvider() )->test_connection( array( 'from_email' => 'x@example.com', 'from_name' => '' ) );
		$this->assertFalse( $r->success );
	}

	// -------------------------------------------------------------------------
	// send — acceptance
	// -------------------------------------------------------------------------

	public function test_successful_send_returns_message_id(): void {
		$p = new StubGmailProvider();
		$r = $p->send( $this->message(), $this->config() );
		$this->assertTrue( $r->success );
		$this->assertSame( 'gmail', $r->provider );
		$this->assertSame( '1234567890abcdef', $r->provider_message_id );
		$this->assertStringContainsString( 'delivery is unconfirmed', $r->response_message );
		$this->assertCount( 1, $p->calls );
	}

	public function test_send_uses_post_with_bearer_auth(): void {
		$p = new StubGmailProvider();
		$p->send( $this->message(), $this->config() );
		[ $url, $args ] = $p->calls[0];
		$this->assertStringContainsString( 'gmail.googleapis.com', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 'Bearer ' . $this->config()['access_token'], $args['headers']['Authorization'] );
	}

	public function test_access_token_not_in_send_result(): void {
		$p = new StubGmailProvider();
		$r = $p->send( $this->message(), $this->config() );
		$serialized = serialize( $r );
		$this->assertStringNotContainsString( $this->config()['access_token'], $serialized );
	}

	public function test_send_builds_base64url_raw_message(): void {
		$p = new StubGmailProvider();
		$p->send( $this->message(), $this->config() );
		[ , $args ] = $p->calls[0];
		$body = json_decode( $args['body'], true );
		$this->assertArrayHasKey( 'raw', $body );
		// base64url must not contain standard base64 special chars.
		$this->assertStringNotContainsString( '+', $body['raw'] );
		$this->assertStringNotContainsString( '/', $body['raw'] );
		$this->assertStringNotContainsString( '=', $body['raw'] );
		// Decoded RFC 2822 must contain the headers.
		$decoded = base64_decode( strtr( $body['raw'], '-_', '+/' ) );
		$this->assertStringContainsString( 'From:', $decoded );
		$this->assertStringContainsString( 'To: recipient@example.com', $decoded );
		$this->assertStringContainsString( 'MIME-Version:', $decoded );
		$this->assertStringContainsString( 'X-Scalyn-UUID:', $decoded );
	}

	public function test_html_content_type_sent_in_rfc2822_headers(): void {
		$p = new StubGmailProvider();
		$p->send( $this->message(), $this->config() );
		[ , $args ] = $p->calls[0];
		$body    = json_decode( $args['body'], true );
		$decoded = base64_decode( strtr( $body['raw'], '-_', '+/' ) );
		$this->assertStringContainsString( 'text/html', $decoded );
	}

	public function test_plain_text_content_type_set_correctly(): void {
		$p = new StubGmailProvider();
		$p->send( $this->message( array( 'content_type' => 'text/plain', 'body' => 'Hello world' ) ), $this->config() );
		[ , $args ] = $p->calls[0];
		$body    = json_decode( $args['body'], true );
		$decoded = base64_decode( strtr( $body['raw'], '-_', '+/' ) );
		$this->assertStringContainsString( 'text/plain', $decoded );
	}

	// -------------------------------------------------------------------------
	// send — failures
	// -------------------------------------------------------------------------

	public function test_send_returns_auth_failure_on_401(): void {
		$p           = new StubGmailProvider();
		$p->response = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"Unauthorized"}' );
		$r           = $p->send( $this->message(), $this->config() );
		$this->assertFalse( $r->success );
		$this->assertSame( 'auth', $r->failure_category );
		$this->assertFalse( $r->retryable );
	}

	public function test_send_returns_rejection_on_400(): void {
		$p           = new StubGmailProvider();
		$p->response = array( 'response' => array( 'code' => 400 ), 'body' => '{"error":{"code":400,"message":"Bad request"}}' );
		$r           = $p->send( $this->message(), $this->config() );
		$this->assertFalse( $r->success );
		$this->assertStringNotContainsString( 'Bad request', $r->response_message );
	}

	public function test_network_failure_returns_unconfirmed(): void {
		$p           = new StubGmailProvider();
		$p->response = new \RuntimeException( 'connection timed out' );
		$r           = $p->send( $this->message(), $this->config() );
		$this->assertFalse( $r->success );
		$this->assertTrue( $r->acceptance_unconfirmed );
		$this->assertStringNotContainsString( 'timed out', $r->response_message ?? '' );
	}

	public function test_incomplete_config_does_not_reach_http(): void {
		$p = new StubGmailProvider();
		$r = $p->send( $this->message(), array( 'access_token' => '', 'from_email' => 'x@example.com', 'from_name' => '' ) );
		$this->assertFalse( $r->success );
		$this->assertCount( 0, $p->calls );
	}

	#[DataProvider( 'invalid_messages' )]
	public function test_invalid_content_never_reaches_http( array $changes ): void {
		$p = new StubGmailProvider();
		$r = $p->send( $this->message( $changes ), $this->config() );
		$this->assertFalse( $r->success );
		$this->assertCount( 0, $p->calls );
	}

	public static function invalid_messages(): array {
		return array(
			'empty_to'           => array( array( 'to' => array() ) ),
			'invalid_recipient'  => array( array( 'to' => array( 'not-an-email' ) ) ),
			'empty_body'         => array( array( 'body' => '' ) ),
			'subject_crlf'       => array( array( 'subject' => "Subject\r\nX-Inject: bad" ) ),
			'unsupported_content' => array( array( 'content_type' => 'text/xml' ) ),
		);
	}
}
