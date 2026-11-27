<?php
/**
 * Unit tests for AdminMenu::handle_providers_load() — Gmail OAuth callback handling.
 *
 * Tests the guard/validation paths that run before Plugin::instance() is ever
 * reached: method gate, parameter presence, capability check, error-param branch,
 * single-use state token, state mismatch, and missing authorization code.
 *
 * The token-exchange success path (first-time flow) is also covered here using
 * a booted plugin container with a known-key cipher injected into the container.
 *
 * @package ScalynMailRelay
 */

/**
 * Namespace-scoped HTTP stubs for OAuthClient used by the first-time callback tests.
 *
 * Declared with function_exists guards so the stubs coexist when the full suite
 * runs GmailSettingsTest (which declares the same stubs) in the same process.
 * Both files share the same $GLOBALS keys so either test file can configure responses.
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
use Scalyn\MailRelay\Admin\AdminMenu;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;

final class GmailOAuthCallbackTest extends TestCase {

	private const USER_ID = 1;

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = array( Capabilities::MANAGE_MAIL => true );
		$GLOBALS['_test_wp_transients']    = array();
		$GLOBALS['_test_wp_redirect']      = null;
		$GLOBALS['_test_current_user_id']  = self::USER_ID;
		$_SERVER['REQUEST_METHOD']         = 'GET';
		$_GET                              = array();
	}

	protected function tearDown(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array();
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
		unset( $GLOBALS['wpdb'], $GLOBALS['_test_gmail_oauth_http_calls'], $GLOBALS['_test_gmail_oauth_http_response'] );
	}

	// -------------------------------------------------------------------------
	// Method gate — returns silently on POST
	// -------------------------------------------------------------------------

	public function test_does_nothing_on_post(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['code']              = 'auth_code';
		$_GET['state']             = 'state_val';

		( new AdminMenu() )->handle_providers_load();

		$this->assertNull( $GLOBALS['_test_wp_redirect'] );
	}

	// -------------------------------------------------------------------------
	// Parameter presence gate
	// -------------------------------------------------------------------------

	public function test_does_nothing_without_code_or_error_param(): void {
		$_GET['state'] = 'some_state';
		// 'code' and 'error' absent.

		( new AdminMenu() )->handle_providers_load();

		$this->assertNull( $GLOBALS['_test_wp_redirect'] );
	}

	public function test_does_nothing_without_state_param(): void {
		$_GET['code'] = 'auth_code';
		// 'state' absent.

		( new AdminMenu() )->handle_providers_load();

		$this->assertNull( $GLOBALS['_test_wp_redirect'] );
	}

	// -------------------------------------------------------------------------
	// Capability gate
	// -------------------------------------------------------------------------

	public function test_does_nothing_without_manage_mail_capability(): void {
		$GLOBALS['_test_current_user_can'] = array();
		$_GET['code']                      = 'auth_code';
		$_GET['state']                     = 'state_val';

		( new AdminMenu() )->handle_providers_load();

		$this->assertNull( $GLOBALS['_test_wp_redirect'] );
	}

	// -------------------------------------------------------------------------
	// Error param — access_denied
	// -------------------------------------------------------------------------

	public function test_error_access_denied_sets_denied_message(): void {
		$_GET['error'] = 'access_denied';
		$_GET['state'] = 'state_val';

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException after access_denied.' );
		} catch ( WpRedirectException $e ) {
			$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
			$this->assertIsArray( $result );
			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( 'denied', strtolower( $result['message'] ) );
		}
	}

	public function test_error_access_denied_redirects_to_providers_page(): void {
		$_GET['error'] = 'access_denied';
		$_GET['state'] = 'state_val';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$this->assertStringContainsString( 'scalyn-mail-relay-providers', $GLOBALS['_test_wp_redirect'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Error param — generic server error
	// -------------------------------------------------------------------------

	public function test_generic_error_param_sets_failure_result(): void {
		$_GET['error'] = 'server_error';
		$_GET['state'] = 'state_val';

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException after server_error.' );
		} catch ( WpRedirectException $e ) {
			$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
			$this->assertIsArray( $result );
			$this->assertFalse( $result['success'] );
		}
	}

	public function test_generic_error_message_differs_from_access_denied_message(): void {
		$_GET['error'] = 'access_denied';
		$_GET['state'] = 'state_val';
		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}
		$denied_message = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ]['message'] ?? '';

		$GLOBALS['_test_wp_transients'] = array();
		$_GET['error'] = 'server_error';
		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}
		$error_message = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ]['message'] ?? '';

		$this->assertNotSame( $denied_message, $error_message );
	}

	// -------------------------------------------------------------------------
	// State transient is single-use
	// -------------------------------------------------------------------------

	public function test_state_transient_deleted_after_error_param(): void {
		$key                                       = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ]   = array( 'state' => 's', 'verifier' => 'v' );
		$_GET['error']                             = 'access_denied';
		$_GET['state']                             = 's';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$this->assertArrayNotHasKey( $key, $GLOBALS['_test_wp_transients'] );
	}

	// -------------------------------------------------------------------------
	// State validation — missing transient (expired / never started)
	// -------------------------------------------------------------------------

	public function test_missing_state_transient_redirects_with_invalid_state_message(): void {
		// $GLOBALS['_test_wp_transients'] is empty — no flow was initiated.
		$_GET['code']  = 'some_code';
		$_GET['state'] = 'some_state';

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException after missing transient.' );
		} catch ( WpRedirectException $e ) {
			$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
			$this->assertIsArray( $result );
			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( 'state', strtolower( $result['message'] ) );
		}
	}

	public function test_missing_transient_still_redirects_to_providers_page(): void {
		$_GET['code']  = 'some_code';
		$_GET['state'] = 'some_state';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$this->assertStringContainsString( 'scalyn-mail-relay-providers', $GLOBALS['_test_wp_redirect'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// State validation — state mismatch (tampered / CSRF attempt)
	// -------------------------------------------------------------------------

	public function test_state_mismatch_redirects_with_failure_result(): void {
		$key                                       = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ]   = array( 'state' => 'correct_state', 'verifier' => 'pkce_v' );
		$_GET['code']                              = 'auth_code';
		$_GET['state']                             = 'tampered_state'; // Differs from stored.

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException after state mismatch.' );
		} catch ( WpRedirectException $e ) {
			$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
			$this->assertIsArray( $result );
			$this->assertFalse( $result['success'] );
		}
	}

	public function test_state_transient_deleted_on_mismatch(): void {
		$key                                       = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ]   = array( 'state' => 'stored_state', 'verifier' => 'v' );
		$_GET['code']                              = 'auth_code';
		$_GET['state']                             = 'different_state';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$this->assertArrayNotHasKey( $key, $GLOBALS['_test_wp_transients'] );
	}

	// -------------------------------------------------------------------------
	// Missing authorization code (state validates, code absent)
	// -------------------------------------------------------------------------

	public function test_empty_code_after_valid_state_sets_failure_result(): void {
		$key                                       = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ]   = array( 'state' => 'valid_state', 'verifier' => 'pkce_v' );
		$_GET['code']                              = ''; // isset() returns true, value is empty.
		$_GET['state']                             = 'valid_state';

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException after empty code.' );
		} catch ( WpRedirectException $e ) {
			$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
			$this->assertIsArray( $result );
			$this->assertFalse( $result['success'] );
		}
	}

	public function test_empty_code_still_redirects_to_providers_page(): void {
		$key                                       = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ]   = array( 'state' => 'valid_state', 'verifier' => 'pkce_v' );
		$_GET['code']                              = '';
		$_GET['state']                             = 'valid_state';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$this->assertStringContainsString( 'scalyn-mail-relay-providers', $GLOBALS['_test_wp_redirect'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Result transient is keyed by current user ID
	// -------------------------------------------------------------------------

	public function test_result_transient_uses_current_user_id(): void {
		$GLOBALS['_test_current_user_id'] = 42;
		$_GET['error']                    = 'access_denied';
		$_GET['state']                    = 'state_val';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$this->assertArrayHasKey( 'scalyn_gmail_oauth_result_42', $GLOBALS['_test_wp_transients'] );
		$this->assertArrayNotHasKey( 'scalyn_gmail_oauth_result_1', $GLOBALS['_test_wp_transients'] );
	}

	// -------------------------------------------------------------------------
	// Security — result message must not expose received state or code values
	// -------------------------------------------------------------------------

	public function test_state_mismatch_message_does_not_contain_received_state(): void {
		$key                                       = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ]   = array( 'state' => 'stored_state', 'verifier' => 'v' );
		$_GET['code']                              = 'sensitive_auth_code';
		$_GET['state']                             = 'attacker_controlled_state';

		try {
			( new AdminMenu() )->handle_providers_load();
		} catch ( WpRedirectException $e ) {}

		$message = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ]['message'] ?? '';
		$this->assertStringNotContainsString( 'attacker_controlled_state', $message );
		$this->assertStringNotContainsString( 'sensitive_auth_code', $message );
	}

	// -------------------------------------------------------------------------
	// First-time OAuth callback — no tokens stored yet (the fixed bug path)
	// -------------------------------------------------------------------------

	/**
	 * Boots a fresh plugin singleton and injects a known-key cipher + pre-seeded settings.
	 *
	 * @return array{cipher: CredentialCipher, settings: SettingsRepository}
	 */
	private function boot_with_gmail_client( bool $include_secret = true ): array {
		$GLOBALS['_test_wp_options']               = array();
		$GLOBALS['_test_wp_added_actions']         = array();
		$GLOBALS['_test_wp_actions']               = array();
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_wp_filters']               = array();
		$GLOBALS['wpdb']                           = new WpdbStub();

		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );

		$cipher = new CredentialCipher( base64_encode( str_repeat( 'k', 32 ) ) );

		// Pre-seed the options store with Gmail client credentials but no tokens.
		$secret_cipher = $include_secret ? $cipher->encrypt( 'GOCSPx-synthetic_test_secret_ok', 'gmail-secret' ) : '';
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'gmail' => array(
				'client_id'      => '1234567890-syntheticabc.apps.googleusercontent.com',
				'secret_cipher'  => $secret_cipher,
				'access_cipher'  => '',  // No tokens yet — first-time flow.
				'refresh_cipher' => '',
				'expires_at'     => 0,
				'from_email'     => '',
				'from_name'      => '',
			),
		);

		Plugin::instance()->boot();

		// Override container resolved cache (boot() eagerly resolves CredentialCipher and
		// SettingsRepository via the WordPressMailBridge chain, so set() alone is not enough —
		// we must also replace the already-cached instances in Container::$resolved).
		$container = Plugin::instance()->container();
		$settings  = new SettingsRepository( $cipher );
		$resolved  = ( new \ReflectionProperty( \Scalyn\MailRelay\Core\Container::class, 'resolved' ) )->getValue( $container );
		$resolved[ CredentialCipher::class ]   = $cipher;
		$resolved[ SettingsRepository::class ] = $settings;
		( new \ReflectionProperty( \Scalyn\MailRelay\Core\Container::class, 'resolved' ) )->setValue( $container, $resolved );

		return array( 'cipher' => $cipher, 'settings' => $settings );
	}

	/**
	 * Sets up the OAuth state transient and $_GET parameters for a successful callback.
	 */
	private function set_valid_oauth_state( string $state = 'valid_state', string $code = 'auth_code_123', string $verifier = 'pkce_verifier' ): void {
		$key                                     = 'scalyn_gmail_oauth_' . self::USER_ID;
		$GLOBALS['_test_wp_transients'][ $key ] = array( 'state' => $state, 'verifier' => $verifier );
		$_GET['code']                            = $code;
		$_GET['state']                           = $state;
	}

	public function test_first_time_callback_success(): void {
		$this->boot_with_gmail_client();
		$this->set_valid_oauth_state();

		// Stub OAuthClient HTTP call to return synthetic tokens.
		$GLOBALS['_test_gmail_oauth_http_calls']    = array();
		$GLOBALS['_test_gmail_oauth_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'access_token'  => 'ya29.synthetic_access',
				'refresh_token' => '1//synthetic_refresh',
				'expires_in'    => 3600,
			) ),
		);

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException after successful OAuth callback.' );
		} catch ( WpRedirectException $e ) {}

		// The result transient must report success.
		$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'], 'Expected success=true on first-time callback.' );

		// exchange_code must have been called (one HTTP request to Google token endpoint).
		$this->assertCount( 1, $GLOBALS['_test_gmail_oauth_http_calls'] );

		// Active provider must not have been changed by the callback.
		$saved = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] ?? array();
		$this->assertNotSame( 'gmail', $saved['provider']['active'] ?? '' );
	}

	public function test_first_time_callback_missing_secret(): void {
		// Boot without storing a secret_cipher.
		$this->boot_with_gmail_client( false );
		$this->set_valid_oauth_state();

		$GLOBALS['_test_gmail_oauth_http_calls']    = array();
		$GLOBALS['_test_gmail_oauth_http_response'] = null;

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException when secret is missing.' );
		} catch ( WpRedirectException $e ) {}

		$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'], 'Expected success=false when no secret is saved.' );

		// exchange_code must NOT have been called.
		$this->assertCount( 0, $GLOBALS['_test_gmail_oauth_http_calls'] );
	}

	public function test_first_time_callback_unreadable_secret(): void {
		// Boot with client credentials in place.
		$this->boot_with_gmail_client();
		$this->set_valid_oauth_state();

		// Corrupt the secret_cipher so decryption throws.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]['gmail']['secret_cipher'] = 'v1:AAAAAAAAAAAAAAAAAAAAAA==';

		// Re-create the SettingsRepository from the now-corrupted options and replace in resolved cache.
		$cipher   = new CredentialCipher( base64_encode( str_repeat( 'k', 32 ) ) );
		$settings = new SettingsRepository( $cipher );
		$container = Plugin::instance()->container();
		$resolved  = ( new \ReflectionProperty( \Scalyn\MailRelay\Core\Container::class, 'resolved' ) )->getValue( $container );
		$resolved[ SettingsRepository::class ] = $settings;
		( new \ReflectionProperty( \Scalyn\MailRelay\Core\Container::class, 'resolved' ) )->setValue( $container, $resolved );

		$GLOBALS['_test_gmail_oauth_http_calls']    = array();
		$GLOBALS['_test_gmail_oauth_http_response'] = null;

		try {
			( new AdminMenu() )->handle_providers_load();
			$this->fail( 'Expected WpRedirectException when secret cannot be decrypted.' );
		} catch ( WpRedirectException $e ) {}

		$result = $GLOBALS['_test_wp_transients'][ 'scalyn_gmail_oauth_result_' . self::USER_ID ] ?? null;
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'], 'Expected success=false when secret cipher is unreadable.' );

		// exchange_code must NOT have been called.
		$this->assertCount( 0, $GLOBALS['_test_gmail_oauth_http_calls'] );
	}

} // end class GmailOAuthCallbackTest

} // end namespace {}
