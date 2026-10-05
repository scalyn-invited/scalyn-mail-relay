<?php
/**
 * Offline Postmark webhook authentication boundary.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Postmark;

defined( 'ABSPATH' ) || exit;

/** No registration, persistence, forwarded-header trust or retained credentials. */
final class WebhookAuthenticator {

	/**
	 * Verifies dedicated Basic credentials and an explicitly trusted network boundary.
	 * HTTPS and peer IP must come from server configuration, never payload headers.
	 *
	 * @param string   $authorization Single Authorization header; duplicates must be rejected by ingress.
	 * @param string   $username Configured source username.
	 * @param string   $password Configured high-entropy source password, not an API token.
	 * @param bool     $https Whether the trusted server boundary established HTTPS.
	 * @param string   $peer_ip Actual peer, or an explicitly trusted proxy's resolved peer.
	 * @param string[] $allowed_ips Maintained exact IP addresses; empty fails closed.
	 * @return bool Authentication only, not proof of event freshness or delivery.
	 */
	public function verify( #[\SensitiveParameter] string $authorization, #[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password, bool $https, string $peer_ip, array $allowed_ips ): bool {
		if ( ! $https || strlen( $authorization ) > 2048 || ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $username ) || ! preg_match( '/^[A-Za-z0-9_-]{32,128}$/D', $password ) || count( $allowed_ips ) > 256 || array() === $allowed_ips ) {
			return false;
		}
		$peer    = filter_var( $peer_ip, FILTER_VALIDATE_IP ) ? inet_pton( $peer_ip ) : false;
		$allowed = false;
		foreach ( $allowed_ips as $ip ) {
			if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return false;
			}
			$allowed = ( false !== $peer && inet_pton( $ip ) === $peer ) || $allowed;
		}
		if ( ! $allowed || ! preg_match( '/^Basic ([A-Za-z0-9+\/]+={0,2})$/iD', $authorization, $parts ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Strict, bounded HTTP Basic decoding, not executable content.
		$decoded = base64_decode( $parts[1], true );
		if ( ! is_string( $decoded ) || ! str_contains( $decoded, ':' ) ) {
			return false;
		}
		list( $actual_user, $actual_password ) = explode( ':', $decoded, 2 );
		// Both comparisons run, with fixed-length digests; no credentials are retained.
		$user_matches     = hash_equals( hash( 'sha256', $username ), hash( 'sha256', $actual_user ) );
		$password_matches = hash_equals( hash( 'sha256', $password ), hash( 'sha256', $actual_password ) );
		return $user_matches && $password_matches;
	}
}
