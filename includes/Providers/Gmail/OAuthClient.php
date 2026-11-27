<?php
/**
 * Google OAuth 2.0 client with PKCE for Gmail API access.
 *
 * Handles authorization URL generation, authorization-code exchange,
 * access-token refresh, and best-effort token revocation.
 * No tokens are stored here; callers must encrypt and persist them.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Gmail;

defined( 'ABSPATH' ) || exit;

/**
 * Thin OAuth 2.0 client for the Gmail send scope only.
 *
 * All token values are marked sensitive and must never appear in logs,
 * exception messages, or serialized output.
 *
 * @security Tokens must not be returned or logged outside the return value.
 */
final class OAuthClient {

	private const AUTH_URI   = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URI  = 'https://oauth2.googleapis.com/token';
	private const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';
	private const SCOPE      = 'https://www.googleapis.com/auth/gmail.send';

	/**
	 * Generates a PKCE code-verifier and its corresponding S256 challenge.
	 *
	 * @return array{verifier: string, challenge: string}
	 * @throws \RuntimeException When the random generator or hash function is unavailable.
	 */
	public function generate_pkce(): array {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array(
			'verifier'  => $verifier,
			'challenge' => $challenge,
		);
	}

	/**
	 * Generates a cryptographically random state token for CSRF protection.
	 *
	 * @return string Hex-encoded 32-byte random string.
	 * @throws \RuntimeException When the random generator is unavailable.
	 */
	public function generate_state(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Builds the Google authorization URL with PKCE parameters.
	 *
	 * Access type is offline so that a refresh token is returned on first
	 * authorization. prompt=consent forces the consent screen to appear even
	 * for previously authorized users, ensuring a fresh refresh token.
	 *
	 * @param string $client_id    OAuth2 client identifier.
	 * @param string $redirect_uri Registered redirect URI (must match Google Cloud Console).
	 * @param string $state        CSRF state token (single-use, stored in transient).
	 * @param string $challenge    PKCE S256 code challenge.
	 * @return string Full authorization URL.
	 */
	public function authorization_url(
		string $client_id,
		string $redirect_uri,
		string $state,
		string $challenge
	): string {
		return self::AUTH_URI . '?' . http_build_query(
			array(
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'response_type'         => 'code',
				'scope'                 => self::SCOPE,
				'state'                 => $state,
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Exchanges an authorization code for access and refresh tokens.
	 *
	 * @param string $client_id     OAuth2 client identifier.
	 * @param string $client_secret OAuth2 client secret.
	 * @param string $code          Authorization code from Google redirect.
	 * @param string $redirect_uri  Must exactly match the URI used in the authorization request.
	 * @param string $verifier      PKCE code verifier corresponding to the challenge.
	 * @return array{access_token: string, refresh_token: string, expires_in: int}
	 * @throws \RuntimeException On network failure or rejected grant.
	 */
	public function exchange_code(
		string $client_id,
		#[\SensitiveParameter] string $client_secret,
		string $code,
		string $redirect_uri,
		#[\SensitiveParameter] string $verifier
	): array {
		$response = $this->post_token(
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $redirect_uri,
				'code_verifier' => $verifier,
			)
		);
		$this->require_tokens( $response, true );
		return $response;
	}

	/**
	 * Refreshes an expired access token using the stored refresh token.
	 *
	 * @param string $client_id     OAuth2 client identifier.
	 * @param string $client_secret OAuth2 client secret.
	 * @param string $refresh_token Long-lived refresh token.
	 * @return array{access_token: string, expires_in: int}
	 * @throws \RuntimeException On network failure, revoked token, or invalid credentials.
	 */
	public function refresh_access_token(
		string $client_id,
		#[\SensitiveParameter] string $client_secret,
		#[\SensitiveParameter] string $refresh_token
	): array {
		$response = $this->post_token(
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
				'grant_type'    => 'refresh_token',
			)
		);
		$this->require_tokens( $response, false );
		return $response;
	}

	/**
	 * Best-effort token revocation. Failures are silently ignored.
	 *
	 * @param string $token Access or refresh token to revoke.
	 */
	public function revoke( #[\SensitiveParameter] string $token ): void {
		try {
			wp_safe_remote_post(
				self::REVOKE_URI . '?' . http_build_query( array( 'token' => $token ), '', '&', PHP_QUERY_RFC3986 ),
				array(
					'timeout'            => 10,
					'redirection'        => 0,
					'sslverify'          => true,
					'reject_unsafe_urls' => true,
				)
			);
		} catch ( \Throwable $error ) {
			// Best-effort only. Callers must remove local token storage regardless.
			unset( $error );
		}
	}

	/**
	 * Posts a form-encoded token request to Google's token endpoint.
	 *
	 * @param array $body Form fields (never logged).
	 * @return array Decoded JSON response.
	 * @throws \RuntimeException On network failure or non-200 response.
	 */
	private function post_token( #[\SensitiveParameter] array $body ): array {
		try {
			$response = wp_safe_remote_post(
				self::TOKEN_URI,
				array(
					'timeout'            => 15,
					'redirection'        => 0,
					'sslverify'          => true,
					'reject_unsafe_urls' => true,
					'limit_response_size' => 65536,
					'headers'            => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'               => http_build_query( $body, '', '&', PHP_QUERY_RFC3986 ),
				)
			);
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Token request failed. Check network connectivity.' );
		}
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Token request failed. Check network connectivity.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = is_string( $raw ) ? json_decode( $raw, true, 4 ) : null;
		if ( 200 !== $code || ! is_array( $data ) ) {
			// Do not include $data in the message — it may contain error_description with hints about credentials.
			throw new \RuntimeException( 'Google rejected the token request. Check the client ID, secret, and redirect URI.' );
		}
		return $data;
	}

	/**
	 * Asserts that a token response contains the required fields.
	 *
	 * @param array $response Decoded token response.
	 * @param bool  $require_refresh Whether a refresh token is required.
	 * @throws \RuntimeException When required fields are absent.
	 */
	private function require_tokens( array $response, bool $require_refresh ): void {
		if ( ! is_string( $response['access_token'] ?? null ) || '' === $response['access_token'] ) {
			throw new \RuntimeException( 'No access token in response. Authorization may have been denied.' );
		}
		if ( $require_refresh && ( ! is_string( $response['refresh_token'] ?? null ) || '' === $response['refresh_token'] ) ) {
			throw new \RuntimeException( 'No refresh token returned. Ensure access_type=offline and prompt=consent were set.' );
		}
		if ( ! is_int( $response['expires_in'] ?? null ) && ! is_numeric( $response['expires_in'] ?? null ) ) {
			throw new \RuntimeException( 'Token response missing expiry. Response format may have changed.' );
		}
	}
}
