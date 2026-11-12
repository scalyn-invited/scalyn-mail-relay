<?php
/**
 * Shared domain input validation for DNS-based diagnostic checks.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Validates that a string is a syntactically well-formed DNS hostname before
 * it is ever passed to a DNS resolver function.
 *
 * This is the SSRF/unrestricted-probing guard for DNS diagnostic checks: only
 * a domain matching this shape is queried. IP literals, wildcards, control
 * characters and other malformed input are rejected before any lookup.
 */
trait DomainValidation {

	/**
	 * Returns whether the given string is a syntactically valid DNS hostname.
	 *
	 * @param string $domain Candidate domain, already trimmed by the caller.
	 */
	private static function is_valid_domain( string $domain ): bool {
		if ( '' === $domain || strlen( $domain ) > 253 ) {
			return false;
		}

		return 1 === preg_match(
			'/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*\.[A-Za-z]{2,63}$/',
			$domain
		);
	}
}
