<?php
/**
 * DKIM record diagnostic check.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether a DKIM public key record exists at "<selector>._domainkey.<domain>".
 *
 * DKIM verification requires a selector, and selectors are provider-specific
 * and cannot be discovered from DNS alone. This check never guesses one: it
 * only looks up a selector supplied explicitly via
 * DiagnosticContext::$settings['dkim_selector']. When no selector is
 * supplied, the result is 'unknown' — an inability to check responsibly is
 * not evidence of a problem, and is not treated as one.
 *
 * A DNS lookup failure is likewise reported as 'unknown', never 'fail'.
 *
 * Ownership: Yaj / Diagnostics.
 */
final class DkimCheck implements DiagnosticCheckInterface {

	use DomainValidation;

	/**
	 * TXT record lookup function. Defaults to a thin wrapper around
	 * dns_get_record(); injectable so tests never perform real DNS queries.
	 *
	 * @var \Closure(string): (array|false)
	 */
	private \Closure $lookup_txt_records;

	/**
	 * Constructs the check, optionally overriding the TXT lookup for tests.
	 *
	 * @param \Closure(string): (array|false) $lookup_txt_records Optional TXT lookup override for tests.
	 */
	public function __construct( ?\Closure $lookup_txt_records = null ) {
		$this->lookup_txt_records = $lookup_txt_records ?? static function ( string $domain ): array|false {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() emits an E_WARNING on resolver failure; the false return value below is the only signal the caller needs.
			return @dns_get_record( $domain, DNS_TXT );
		};
	}

	/**
	 * Returns the unique machine-readable identifier for this check.
	 */
	public function get_id(): string {
		return 'dkim_record';
	}

	/**
	 * Returns the category this check belongs to.
	 */
	public function get_category(): string {
		return 'dns';
	}

	/**
	 * Executes the DKIM check and returns a normalized result.
	 *
	 * @param DiagnosticContext $context The execution context for this check run.
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult {
		$domain = trim( $context->domain );

		if ( ! self::is_valid_domain( $domain ) ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'DKIM could not be checked because no valid sending domain is configured.'
			);
		}

		$selector = self::extract_selector( $context->settings );

		if ( null === $selector ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'DKIM could not be checked for "%s" because no selector is configured.', $domain ),
				impact: 'DKIM selectors are provider-specific and cannot be reliably guessed; without one, this check cannot verify DKIM from DNS alone.',
				recommended_action: 'Provide the DKIM selector used by your mail provider so this check can look up the correct DNS record.'
			);
		}

		$selector_domain = $selector . '._domainkey.' . $domain;
		$records         = ( $this->lookup_txt_records )( $selector_domain );

		if ( false === $records ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'DKIM lookup for selector "%s" on "%s" failed; the DNS query could not be completed.', $selector, $domain )
			);
		}

		if ( array() === $records ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'No DKIM record found for selector "%s" on "%s".', $selector, $domain ),
				impact: 'Without a published DKIM key for this selector, receiving servers cannot verify that messages signed with it genuinely originated from an authorized sender.',
				recommended_action: sprintf( 'Publish a TXT record at "%s" containing your DKIM public key.', $selector_domain )
			);
		}

		if ( count( $records ) > 1 ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'Multiple DKIM records found for selector "%s" on "%s".', $selector, $domain ),
				impact: 'DKIM verifiers treat multiple TXT records at the same selector name as undefined behavior, which can cause valid signatures to fail verification.',
				recommended_action: 'Remove the extra TXT records for this selector, leaving exactly one.'
			);
		}

		$dkim = (string) ( $records[0]['txt'] ?? '' );
		$key  = self::extract_public_key( $dkim );

		if ( null === $key ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DKIM record for selector "%s" on "%s" is missing a "p=" public key tag.', $selector, $domain ),
				evidence: $dkim,
				impact: 'A DKIM record without a recognized public key tag will not be usable for signature verification.',
				recommended_action: 'Ensure the record includes a "p=" tag with your DKIM public key.',
				raw: array( 'record' => $dkim )
			);
		}

		if ( '' === $key ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'DKIM key for selector "%s" on "%s" has been revoked (empty "p=" value).', $selector, $domain ),
				evidence: $dkim,
				impact: 'An empty "p=" tag is the standard mechanism for revoking a DKIM key; mail signed with this selector will fail verification.',
				recommended_action: 'Publish a valid public key, or configure your provider to sign with a different, active selector.',
				raw: array( 'record' => $dkim )
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'A valid DKIM record was found for selector "%s" on "%s".', $selector, $domain ),
			evidence: $dkim,
			raw: array( 'record' => $dkim )
		);
	}

	/**
	 * Returns an explicitly configured DKIM selector from context settings,
	 * or null when absent, empty, or not a syntactically safe selector label.
	 *
	 * Never derives, defaults, or guesses a selector — only reads one that was
	 * explicitly supplied.
	 *
	 * @param array<string, mixed> $settings DiagnosticContext::$settings.
	 */
	private static function extract_selector( array $settings ): ?string {
		$selector = $settings['dkim_selector'] ?? null;

		if ( ! is_string( $selector ) ) {
			return null;
		}

		$selector = trim( $selector );

		if ( '' === $selector || strlen( $selector ) > 63 ) {
			return null;
		}

		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9])?$/', $selector )
			? $selector
			: null;
	}

	/**
	 * Extracts the "p=" public key value from a DKIM record.
	 *
	 * Returns an empty string when the tag is present but explicitly empty
	 * (RFC 6376 key revocation), or null when the tag is absent entirely.
	 *
	 * @param string $dkim The DKIM record text to inspect.
	 */
	private static function extract_public_key( string $dkim ): ?string {
		if ( 1 !== preg_match( '/(?:^|;)\s*p=([^;]*)/i', $dkim, $matches ) ) {
			return null;
		}

		return trim( $matches[1] );
	}
}
