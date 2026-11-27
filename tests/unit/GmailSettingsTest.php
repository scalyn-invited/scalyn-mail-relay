<?php
/**
 * Unit tests for SettingsRepository Gmail methods.
 *
 * Tests persistence, encryption, projections (get_gmail_settings),
 * credential security, verification invalidation, and the silent
 * access-token refresh path in get_gmail_config().
 *
 * @package ScalynMailRelay
 */

/**
 * Namespace-scoped HTTP stubs for OAuthClient used in the token-refresh test.
 *
 * These are only declared if GmailOAuthClientTest has not already declared them
 * in the same process (e.g. when running the full test suite together).
 * The stubs share the same globals so both test files can configure responses.
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
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;

final class GmailSettingsTest extends TestCase {

	private CredentialCipher $cipher;

	// Synthetic-only credentials that never interact with real Google APIs.
	private const CLIENT_ID     = '1234567890-syntheticabc.apps.googleusercontent.com';
	private const CLIENT_SECRET = 'GOCSPx-synthetic_test_secret_ok';
	private const ACCESS_TOKEN  = 'ya29.synthetic_access_token_for_unit_test';
	private const REFRESH_TOKEN = 'synthetic_refresh_token_for_unit_test';

	protected function setUp(): void {
		$this->cipher = new CredentialCipher( base64_encode( str_repeat( 'g', 32 ) ) );
		$GLOBALS['_test_wp_options']             = array();
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_wp_actions']             = array();
		$GLOBALS['_test_wp_added_actions']       = array();
		$GLOBALS['_test_current_user_can']       = array( Capabilities::MANAGE_SETTINGS => true );
	}

	protected function tearDown(): void {
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_wp_actions']               = array();
	}

	// -------------------------------------------------------------------------
	// get_gmail_settings — defaults
	// -------------------------------------------------------------------------

	public function test_fresh_gmail_settings_returns_empty_defaults(): void {
		$s = ( new SettingsRepository() )->get_gmail_settings();
		$this->assertSame( '', $s['client_id'] );
		$this->assertFalse( $s['has_secret'] );
		$this->assertFalse( $s['connected'] );
		$this->assertSame( 0, $s['expires_at'] );
		$this->assertSame( '', $s['from_email'] );
		$this->assertSame( '', $s['from_name'] );
	}

	// -------------------------------------------------------------------------
	// save_gmail_client
	// -------------------------------------------------------------------------

	public function test_save_gmail_client_stores_encrypted_secret(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$s = $settings->get_gmail_settings();
		$this->assertSame( self::CLIENT_ID, $s['client_id'] );
		$this->assertTrue( $s['has_secret'] );
	}

	public function test_save_gmail_client_clears_existing_tokens(): void {
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$this->assertTrue( $settings->get_gmail_settings()['connected'] );
		// Saving new client credentials resets the token grant.
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$this->assertFalse( $settings->get_gmail_settings()['connected'] );
		$this->assertSame( 0, $settings->get_gmail_settings()['expires_at'] );
	}

	public function test_save_gmail_client_invalidates_verification_when_active(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save( array( 'provider' => array( 'active' => 'gmail', 'verified' => true, 'verified_at' => 'old', 'test_email_accepted_at' => 'old' ) ) );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$this->assertFalse( $settings->is_provider_verified() );
		$this->assertFalse( $settings->has_accepted_test_email() );
	}

	public function test_save_gmail_client_does_not_invalidate_verification_for_other_provider(): void {
		// Seed verified sendgrid state directly — save() resets verified when switching active provider.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'sendgrid', 'verified' => true, 'verified_at' => 'old' ),
		);
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$this->assertTrue( $settings->is_provider_verified() );
	}

	public function test_client_secret_never_in_settings_projection(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$projection = $settings->get_gmail_settings();
		$this->assertStringNotContainsString( self::CLIENT_SECRET, json_encode( $projection ) );
		$this->assertArrayNotHasKey( 'client_secret', $projection );
	}

	public function test_client_secret_encrypted_in_storage(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$raw = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]['gmail'];
		$this->assertStringNotContainsString( self::CLIENT_SECRET, json_encode( $raw ) );
		// But it must be retrievable via decrypt.
		$this->assertSame( self::CLIENT_SECRET, $this->cipher->decrypt( $raw['secret_cipher'], 'gmail-secret' ) );
	}

	public function test_save_gmail_client_audit_event_fires(): void {
		$events = array();
		$GLOBALS['_test_wp_actions'][ HookNames::AUDIT_EVENT ] = static function ( $event ) use ( &$events ): void {
			$events[] = $event;
		};
		( new SettingsRepository( $this->cipher ) )->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$this->assertCount( 1, $events );
		$this->assertContains( 'gmail.client_id', $events[0]->changed_fields );
		$this->assertContains( 'gmail.client_secret', $events[0]->changed_fields );
	}

	public function test_audit_payload_does_not_expose_client_secret(): void {
		$events = array();
		$GLOBALS['_test_wp_actions'][ HookNames::AUDIT_EVENT ] = static function ( $event ) use ( &$events ): void {
			$events[] = $event;
		};
		( new SettingsRepository( $this->cipher ) )->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$this->assertStringNotContainsString( self::CLIENT_SECRET, json_encode( $events ) );
	}

	#[DataProvider( 'invalid_client_ids' )]
	public function test_invalid_client_id_format_throws( string $bad_id ): void {
		$this->expectException( \InvalidArgumentException::class );
		( new SettingsRepository() )->save_gmail_client( $bad_id, self::CLIENT_SECRET, $this->cipher );
	}

	public static function invalid_client_ids(): array {
		return array(
			'empty'                  => array( '' ),
			'no_googleusercontent'   => array( '1234567890.apps.example.com' ),
			'plain_string'           => array( 'not-a-client-id' ),
			'short_prefix'           => array( '12.apps.googleusercontent.com' ),
		);
	}

	#[DataProvider( 'invalid_client_secrets' )]
	public function test_invalid_client_secret_format_throws( string $bad_secret ): void {
		$this->expectException( \InvalidArgumentException::class );
		( new SettingsRepository() )->save_gmail_client( self::CLIENT_ID, $bad_secret, $this->cipher );
	}

	public static function invalid_client_secrets(): array {
		return array(
			'too_short'    => array( 'short' ),
			'with_spaces'  => array( 'secret with spaces' ),
			'too_long'     => array( str_repeat( 'a', 201 ) ),
		);
	}

	// -------------------------------------------------------------------------
	// save_gmail_tokens
	// -------------------------------------------------------------------------

	public function test_save_gmail_tokens_marks_connected(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$expiry = time() + 3600;
		$settings->save_gmail_tokens( self::CLIENT_SECRET, self::ACCESS_TOKEN, self::REFRESH_TOKEN, $expiry, $this->cipher );
		$s = $settings->get_gmail_settings();
		$this->assertTrue( $s['connected'] );
		$this->assertSame( $expiry, $s['expires_at'] );
	}

	public function test_save_gmail_tokens_without_client_throws(): void {
		$this->expectException( \RuntimeException::class );
		( new SettingsRepository( $this->cipher ) )->save_gmail_tokens(
			self::CLIENT_SECRET,
			self::ACCESS_TOKEN,
			self::REFRESH_TOKEN,
			time() + 3600,
			$this->cipher
		);
	}

	public function test_tokens_encrypted_in_storage(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$settings->save_gmail_tokens( self::CLIENT_SECRET, self::ACCESS_TOKEN, self::REFRESH_TOKEN, time() + 3600, $this->cipher );
		$raw = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]['gmail'];
		$this->assertStringNotContainsString( self::ACCESS_TOKEN, json_encode( $raw ) );
		$this->assertStringNotContainsString( self::REFRESH_TOKEN, json_encode( $raw ) );
		// But decryptable with the right context.
		$this->assertSame( self::ACCESS_TOKEN, $this->cipher->decrypt( $raw['access_cipher'], 'gmail-access' ) );
		$this->assertSame( self::REFRESH_TOKEN, $this->cipher->decrypt( $raw['refresh_cipher'], 'gmail-refresh' ) );
	}

	public function test_tokens_never_in_gmail_settings_projection(): void {
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$projection = $settings->get_gmail_settings();
		$serialized = json_encode( $projection );
		$this->assertStringNotContainsString( self::ACCESS_TOKEN, $serialized );
		$this->assertStringNotContainsString( self::REFRESH_TOKEN, $serialized );
	}

	public function test_save_gmail_tokens_invalidates_verification_when_active(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save( array( 'provider' => array( 'active' => 'gmail', 'verified' => true, 'verified_at' => 'old', 'test_email_accepted_at' => 'old' ) ) );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		// Re-load to pick up fresh state before saving tokens.
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_tokens( self::CLIENT_SECRET, self::ACCESS_TOKEN, self::REFRESH_TOKEN, time() + 3600, $this->cipher );
		$this->assertFalse( $settings->is_provider_verified() );
	}

	// -------------------------------------------------------------------------
	// save_gmail_sender
	// -------------------------------------------------------------------------

	public function test_save_gmail_sender_persists_email_and_name(): void {
		$settings = new SettingsRepository();
		$settings->save_gmail_sender( 'sender@example.com', 'Test Sender' );
		$s = $settings->get_gmail_settings();
		$this->assertSame( 'sender@example.com', $s['from_email'] );
		$this->assertSame( 'Test Sender', $s['from_name'] );
	}

	public function test_save_gmail_sender_accepts_empty_name(): void {
		$settings = new SettingsRepository();
		$settings->save_gmail_sender( 'sender@example.com', '' );
		$this->assertSame( '', $settings->get_gmail_settings()['from_name'] );
	}

	public function test_save_gmail_sender_invalidates_verification_when_active(): void {
		$settings = new SettingsRepository();
		$settings->save( array( 'provider' => array( 'active' => 'gmail', 'verified' => true, 'verified_at' => 'old', 'test_email_accepted_at' => 'old' ) ) );
		$settings->save_gmail_sender( 'newsender@example.com', '' );
		$this->assertFalse( $settings->is_provider_verified() );
	}

	#[DataProvider( 'invalid_sender_inputs' )]
	public function test_invalid_sender_throws( string $email, string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		( new SettingsRepository() )->save_gmail_sender( $email, $name );
	}

	public static function invalid_sender_inputs(): array {
		return array(
			'empty_email'           => array( '', '' ),
			'malformed_email'       => array( 'not-an-email', '' ),
			'crlf_in_name'          => array( 'x@example.com', "Name\r\nX-Inject: bad" ),
			'name_too_long'         => array( 'x@example.com', str_repeat( 'a', 201 ) ),
		);
	}

	// -------------------------------------------------------------------------
	// disconnect_gmail
	// -------------------------------------------------------------------------

	public function test_disconnect_clears_tokens_and_keeps_client(): void {
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$this->assertTrue( $settings->get_gmail_settings()['connected'] );
		$settings->disconnect_gmail();
		$s = $settings->get_gmail_settings();
		$this->assertFalse( $s['connected'] );
		$this->assertSame( self::CLIENT_ID, $s['client_id'] );
		$this->assertTrue( $s['has_secret'] );
		$this->assertSame( 0, $s['expires_at'] );
	}

	public function test_disconnect_invalidates_verification_when_active(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save( array( 'provider' => array( 'active' => 'gmail', 'verified' => true, 'verified_at' => 'old', 'test_email_accepted_at' => 'old' ) ) );
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		// Need a fresh repo that picks up the new provider state.
		$settings = new SettingsRepository( $this->cipher );
		$settings->save_gmail_tokens( self::CLIENT_SECRET, self::ACCESS_TOKEN, self::REFRESH_TOKEN, time() + 3600, $this->cipher );
		// mark_provider_verified manually.
		$settings->save( array( 'provider' => array( 'active' => 'gmail', 'verified' => true, 'verified_at' => 'recent' ) ) );
		$settings = new SettingsRepository( $this->cipher );
		$settings->disconnect_gmail();
		$this->assertFalse( $settings->is_provider_verified() );
	}

	public function test_disconnect_audit_event_fires(): void {
		$events = array();
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$GLOBALS['_test_wp_actions'][ HookNames::AUDIT_EVENT ] = static function ( $event ) use ( &$events ): void {
			$events[] = $event;
		};
		$settings->disconnect_gmail();
		$this->assertCount( 1, $events );
		$this->assertContains( 'gmail.tokens', $events[0]->changed_fields );
	}

	// -------------------------------------------------------------------------
	// get_gmail_config — credential decryption
	// -------------------------------------------------------------------------

	public function test_get_gmail_config_decrypts_all_credentials(): void {
		$settings = new SettingsRepository( $this->cipher );
		$expiry   = time() + 7200;
		$this->seed_tokens( $settings, $expiry );
		$config = $settings->get_gmail_config();
		$this->assertSame( self::ACCESS_TOKEN, $config['access_token'] );
		$this->assertSame( self::REFRESH_TOKEN, $config['refresh_token'] );
		$this->assertSame( self::CLIENT_ID, $config['client_id'] );
		$this->assertSame( 'sender@example.com', $config['from_email'] );
	}

	public function test_get_gmail_config_throws_without_credentials(): void {
		$this->expectException( \RuntimeException::class );
		( new SettingsRepository( $this->cipher ) )->get_gmail_config();
	}

	public function test_get_gmail_config_throws_after_disconnect(): void {
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$settings->disconnect_gmail();
		$this->expectException( \RuntimeException::class );
		$settings->get_gmail_config();
	}

	public function test_get_provider_config_routes_to_gmail_config(): void {
		$settings = new SettingsRepository( $this->cipher );
		$settings->save( array( 'provider' => array( 'active' => 'gmail' ) ) );
		$this->seed_tokens( $settings );
		$config = $settings->get_provider_config( 'gmail' );
		$this->assertSame( self::ACCESS_TOKEN, $config['access_token'] );
	}

	// -------------------------------------------------------------------------
	// Cipher context separation
	// -------------------------------------------------------------------------

	public function test_provider_bound_contexts_cannot_be_swapped(): void {
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$raw = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]['gmail'];
		// access_cipher must NOT decrypt with the secret or refresh context.
		$this->expectException( \RuntimeException::class );
		$this->cipher->decrypt( $raw['access_cipher'], 'gmail-secret' );
	}

	public function test_refresh_cipher_cannot_decrypt_as_access(): void {
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings );
		$raw = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]['gmail'];
		$this->expectException( \RuntimeException::class );
		$this->cipher->decrypt( $raw['refresh_cipher'], 'gmail-access' );
	}

	// -------------------------------------------------------------------------
	// Failed write
	// -------------------------------------------------------------------------

	public function test_failed_write_returns_false_and_leaves_state_unchanged(): void {
		$settings = new SettingsRepository( $this->cipher );
		$GLOBALS['_test_wp_option_write_failures'] = array( SettingsRepository::OPTION_KEY => true );
		$result = $settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$this->assertFalse( $result );
		$this->assertArrayNotHasKey( SettingsRepository::OPTION_KEY, $GLOBALS['_test_wp_options'] );
	}

	// -------------------------------------------------------------------------
	// get_gmail_config — expired-token silent refresh
	// -------------------------------------------------------------------------

	/**
	 * When the stored access token is expired, get_gmail_config() must silently
	 * call OAuthClient::refresh_access_token() and return the new token.
	 *
	 * The namespace-scoped wp_safe_remote_post stub intercepts the HTTP POST to
	 * Google's token endpoint. The stub returns a refreshed access token so no
	 * real network traffic is generated.
	 */
	public function test_get_gmail_config_uses_refreshed_token_on_expiry(): void {
		$GLOBALS['_test_gmail_oauth_http_calls']    = array();
		$GLOBALS['_test_gmail_oauth_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'access_token' => 'ya29.refreshed_access_token',
				'expires_in'   => 3600,
			) ),
		);

		// Seed credentials with an already-expired access token (1 second in the past).
		$settings = new SettingsRepository( $this->cipher );
		$this->seed_tokens( $settings, time() - 1 );

		$config = $settings->get_gmail_config();

		// The returned access token must be the refreshed one, not the original.
		$this->assertSame( 'ya29.refreshed_access_token', $config['access_token'] );

		// Exactly one HTTP call must have been made to the token endpoint.
		$this->assertCount( 1, $GLOBALS['_test_gmail_oauth_http_calls'] );
		$this->assertStringContainsString(
			'oauth2.googleapis.com/token',
			$GLOBALS['_test_gmail_oauth_http_calls'][0]['url']
		);

		// The new token must be persisted in storage.
		$raw = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]['gmail'];
		$stored_access = $this->cipher->decrypt( $raw['access_cipher'], 'gmail-access' );
		$this->assertSame( 'ya29.refreshed_access_token', $stored_access );

		// Clean up globals.
		$GLOBALS['_test_gmail_oauth_http_calls']    = array();
		$GLOBALS['_test_gmail_oauth_http_response'] = null;
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/** Seeds the repository with a full set of Gmail credentials plus a sender. */
	private function seed_tokens( SettingsRepository $settings, int $expires_at = 0 ): void {
		$settings->save_gmail_client( self::CLIENT_ID, self::CLIENT_SECRET, $this->cipher );
		$settings->save_gmail_tokens(
			self::CLIENT_SECRET,
			self::ACCESS_TOKEN,
			self::REFRESH_TOKEN,
			$expires_at > 0 ? $expires_at : time() + 7200,
			$this->cipher
		);
		$settings->save_gmail_sender( 'sender@example.com', 'Test Sender' );
	}
}

} // namespace
