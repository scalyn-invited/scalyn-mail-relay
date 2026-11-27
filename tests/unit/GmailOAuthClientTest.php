<?php
/**
 * Unit tests for GmailOAuthClient.
 *
 * Network calls are intercepted via namespace-scoped function stubs so that no
 * real HTTP traffic is made. All token values are synthetic test-only strings.
 *
 * @package ScalynMailRelay
 */

/**
 * Namespace-scoped stubs for the OAuthClient HTTP layer.
 *
 * PHP function lookup checks the current namespace before the global namespace,
 * so these stubs shadow the real wp_safe_remote_post / wp_remote_retrieve_* only
 * for calls made inside OAuthClient.
 */
namespace Scalyn\MailRelay\Providers\Gmail {

	if ( ! function_exists( 'Scalyn\MailRelay\Providers\Gmail\wp_safe_remote_post' ) ) {
		function wp_safe_remote_post( string $url, array $args ): mixed {
			$GLOBALS['_test_gmail_oauth_http_calls'][] = array( 'url' => $url, 'args' => $args );
			$r = $GLOBALS['_test_gmail_oauth_http_response'] ?? null;
			if ( $r instanceof \Throwable ) {
				throw $r;
			}
			return $r ?? array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"access_token":"ya29.synthetic_access","refresh_token":"1//synthetic_refresh","expires_in":3600}',
			);
		}
	}

	if ( ! function_exists( 'Scalyn\MailRelay\Providers\Gmail\wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( mixed $response ): int {
			return (int) ( $response['response']['code'] ?? 0 );
		}
	}

	if ( ! function_exists( 'Scalyn\MailRelay\Providers\Gmail\wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( mixed $response ): string {
			return (string) ( $response['body'] ?? '' );
		}
	}

	if ( ! function_exists( 'Scalyn\MailRelay\Providers\Gmail\is_wp_error' ) ) {
		function is_wp_error( mixed $value ): bool {
			return $value instanceof \WP_Error;
		}
	}
}

namespace {

	use PHPUnit\Framework\TestCase;
	use Scalyn\MailRelay\Providers\Gmail\OAuthClient;

	final class GmailOAuthClientTest extends TestCase {

		private OAuthClient $client;

		protected function setUp(): void {
			$this->client                               = new OAuthClient();
			$GLOBALS['_test_gmail_oauth_http_calls']    = array();
			$GLOBALS['_test_gmail_oauth_http_response'] = null;
		}

		// -------------------------------------------------------------------------
		// generate_pkce
		// -------------------------------------------------------------------------

		public function test_pkce_returns_verifier_and_challenge_keys(): void {
			$pkce = $this->client->generate_pkce();
			$this->assertArrayHasKey( 'verifier', $pkce );
			$this->assertArrayHasKey( 'challenge', $pkce );
		}

		public function test_pkce_verifier_is_base64url_without_padding(): void {
			$pkce = $this->client->generate_pkce();
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-_]+$/', $pkce['verifier'] );
			$this->assertStringNotContainsString( '+', $pkce['verifier'] );
			$this->assertStringNotContainsString( '/', $pkce['verifier'] );
			$this->assertStringNotContainsString( '=', $pkce['verifier'] );
		}

		public function test_pkce_challenge_is_s256_of_verifier(): void {
			$pkce     = $this->client->generate_pkce();
			$expected = rtrim( strtr( base64_encode( hash( 'sha256', $pkce['verifier'], true ) ), '+/', '-_' ), '=' );
			$this->assertSame( $expected, $pkce['challenge'] );
		}

		public function test_pkce_challenge_differs_from_verifier(): void {
			$pkce = $this->client->generate_pkce();
			$this->assertNotSame( $pkce['verifier'], $pkce['challenge'] );
		}

		public function test_pkce_generates_unique_values_each_call(): void {
			$a = $this->client->generate_pkce();
			$b = $this->client->generate_pkce();
			$this->assertNotSame( $a['verifier'], $b['verifier'] );
			$this->assertNotSame( $a['challenge'], $b['challenge'] );
		}

		// -------------------------------------------------------------------------
		// generate_state
		// -------------------------------------------------------------------------

		public function test_state_is_64_lowercase_hex_chars(): void {
			$state = $this->client->generate_state();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $state );
		}

		public function test_state_is_unique_per_call(): void {
			$this->assertNotSame( $this->client->generate_state(), $this->client->generate_state() );
		}

		// -------------------------------------------------------------------------
		// authorization_url
		// -------------------------------------------------------------------------

		public function test_authorization_url_points_to_google_accounts(): void {
			$url = $this->client->authorization_url( 'client_id', 'https://example.com/cb', 'state_val', 'challenge_val' );
			$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth', $url );
		}

		public function test_authorization_url_contains_expected_query_params(): void {
			$url = $this->client->authorization_url( 'my_client_id', 'https://example.com/cb', 'my_state', 'my_challenge' );
			parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );

			$this->assertSame( 'my_client_id', $params['client_id'] );
			$this->assertSame( 'code', $params['response_type'] );
			$this->assertSame( 'my_state', $params['state'] );
			$this->assertSame( 'my_challenge', $params['code_challenge'] );
			$this->assertSame( 'S256', $params['code_challenge_method'] );
			$this->assertSame( 'offline', $params['access_type'] );
			$this->assertSame( 'consent', $params['prompt'] );
			$this->assertStringContainsString( 'gmail.send', $params['scope'] );
		}

		public function test_authorization_url_does_not_include_client_secret(): void {
			$url = $this->client->authorization_url( 'client_id', 'https://example.com/cb', 'state', 'challenge' );
			$this->assertStringNotContainsString( 'secret', $url );
		}

		// -------------------------------------------------------------------------
		// exchange_code — acceptance
		// -------------------------------------------------------------------------

		public function test_exchange_code_returns_access_and_refresh_tokens(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array(
					'access_token'  => 'ya29.synthetic_exchange_access',
					'refresh_token' => 'synthetic_exchange_refresh',
					'expires_in'    => 3600,
				) ),
			);
			$result = $this->client->exchange_code( 'cid', 'csecret', 'auth_code', 'https://example.com/cb', 'verifier' );
			$this->assertSame( 'ya29.synthetic_exchange_access', $result['access_token'] );
			$this->assertSame( 'synthetic_exchange_refresh', $result['refresh_token'] );
			$this->assertSame( 3600, $result['expires_in'] );
		}

		public function test_exchange_code_posts_to_google_token_endpoint(): void {
			$this->client->exchange_code( 'cid', 'csecret', 'code', 'https://example.com/cb', 'verifier' );
			$this->assertCount( 1, $GLOBALS['_test_gmail_oauth_http_calls'] );
			$this->assertStringContainsString( 'oauth2.googleapis.com/token', $GLOBALS['_test_gmail_oauth_http_calls'][0]['url'] );
		}

		public function test_exchange_code_body_contains_authorization_code_grant(): void {
			$this->client->exchange_code( 'cid', 'csecret', 'auth_code', 'https://example.com/cb', 'pkce_verifier' );
			$body = $GLOBALS['_test_gmail_oauth_http_calls'][0]['args']['body'];
			parse_str( $body, $params );
			$this->assertSame( 'authorization_code', $params['grant_type'] );
			$this->assertSame( 'auth_code', $params['code'] );
			$this->assertSame( 'pkce_verifier', $params['code_verifier'] );
		}

		// -------------------------------------------------------------------------
		// exchange_code — failures
		// -------------------------------------------------------------------------

		public function test_exchange_code_throws_on_network_exception(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = new \RuntimeException( 'connection refused' );
			$this->expectException( \RuntimeException::class );
			$this->client->exchange_code( 'cid', 'csecret', 'code', 'https://example.com/cb', 'v' );
		}

		public function test_exchange_code_throws_on_non_200_response(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 400 ),
				'body'     => json_encode( array( 'error' => 'invalid_grant' ) ),
			);
			$this->expectException( \RuntimeException::class );
			$this->client->exchange_code( 'cid', 'csecret', 'code', 'https://example.com/cb', 'v' );
		}

		public function test_exchange_code_does_not_include_credentials_in_error_message(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"error":"invalid_grant"}',
			);
			try {
				$this->client->exchange_code( 'cid', 'super_secret_value', 'code', 'https://example.com/cb', 'v' );
				$this->fail( 'Expected RuntimeException' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringNotContainsString( 'super_secret_value', $e->getMessage() );
			}
		}

		public function test_exchange_code_throws_when_access_token_absent(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'refresh_token' => 'rt', 'expires_in' => 3600 ) ),
			);
			$this->expectException( \RuntimeException::class );
			$this->client->exchange_code( 'cid', 'csecret', 'code', 'https://example.com/cb', 'v' );
		}

		public function test_exchange_code_throws_when_refresh_token_absent(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'access_token' => 'ya29.access', 'expires_in' => 3600 ) ),
			);
			$this->expectException( \RuntimeException::class );
			$this->client->exchange_code( 'cid', 'csecret', 'code', 'https://example.com/cb', 'v' );
		}

		public function test_exchange_code_throws_when_expires_in_absent(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'access_token' => 'ya29.access', 'refresh_token' => 'rt' ) ),
			);
			$this->expectException( \RuntimeException::class );
			$this->client->exchange_code( 'cid', 'csecret', 'code', 'https://example.com/cb', 'v' );
		}

		// -------------------------------------------------------------------------
		// refresh_access_token — acceptance
		// -------------------------------------------------------------------------

		public function test_refresh_returns_new_access_token(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'access_token' => 'ya29.refreshed', 'expires_in' => 3600 ) ),
			);
			$result = $this->client->refresh_access_token( 'cid', 'csecret', 'old_refresh' );
			$this->assertSame( 'ya29.refreshed', $result['access_token'] );
			$this->assertSame( 3600, $result['expires_in'] );
		}

		public function test_refresh_does_not_require_new_refresh_token_in_response(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'access_token' => 'ya29.refreshed', 'expires_in' => 3600 ) ),
			);
			// Should NOT throw even though refresh_token is absent (not required on refresh grant).
			$result = $this->client->refresh_access_token( 'cid', 'csecret', 'old_refresh' );
			$this->assertSame( 'ya29.refreshed', $result['access_token'] );
		}

		public function test_refresh_posts_refresh_token_grant_type(): void {
			$this->client->refresh_access_token( 'cid', 'csecret', 'stored_refresh' );
			$body = $GLOBALS['_test_gmail_oauth_http_calls'][0]['args']['body'];
			parse_str( $body, $params );
			$this->assertSame( 'refresh_token', $params['grant_type'] );
			$this->assertSame( 'stored_refresh', $params['refresh_token'] );
		}

		// -------------------------------------------------------------------------
		// refresh_access_token — failures
		// -------------------------------------------------------------------------

		public function test_refresh_throws_when_token_revoked(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 400 ),
				'body'     => json_encode( array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) ),
			);
			$this->expectException( \RuntimeException::class );
			$this->client->refresh_access_token( 'cid', 'csecret', 'revoked_refresh' );
		}

		public function test_refresh_throws_on_network_failure(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = new \RuntimeException( 'connection timed out' );
			$this->expectException( \RuntimeException::class );
			$this->client->refresh_access_token( 'cid', 'csecret', 'any_refresh' );
		}

		public function test_refresh_error_message_does_not_expose_credentials(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"error":"invalid_client"}',
			);
			try {
				$this->client->refresh_access_token( 'my_client_id', 'my_secret_value', 'rt' );
				$this->fail( 'Expected RuntimeException' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringNotContainsString( 'my_secret_value', $e->getMessage() );
			}
		}

		// -------------------------------------------------------------------------
		// revoke
		// -------------------------------------------------------------------------

		public function test_revoke_completes_without_throwing_on_network_error(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = new \RuntimeException( 'network error' );
			// Best-effort — must not propagate.
			$this->expectNotToPerformAssertions();
			$this->client->revoke( 'ya29.some_token' );
		}

		public function test_revoke_calls_google_revoke_endpoint(): void {
			$this->client->revoke( 'ya29.token_to_revoke' );
			$this->assertCount( 1, $GLOBALS['_test_gmail_oauth_http_calls'] );
			$this->assertStringContainsString( 'revoke', $GLOBALS['_test_gmail_oauth_http_calls'][0]['url'] );
		}

		public function test_revoke_does_not_throw_on_non_200_response(): void {
			$GLOBALS['_test_gmail_oauth_http_response'] = array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"error":"invalid_token"}',
			);
			$this->expectNotToPerformAssertions();
			$this->client->revoke( 'ya29.already_expired' );
		}
	}
}
