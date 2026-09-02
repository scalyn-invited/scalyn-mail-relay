<?php
/**
 * DMARC policy diagnostic check.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether DiagnosticContext::$domain publishes a single, well-formed
 * DMARC policy record at "_dmarc.<domain>".
 *
 * Evidence-based only: this check never claims deliverability, only reports
 * what DNS actually returns. A DNS lookup failure is reported as 'unknown',
 * never as 'fail' — an inability to check is not evidence of a problem.
 *
 * Ownership: Yaj / Diagnostics.
 */
final class DmarcCheck implements DiagnosticCheckInterface {

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
		return 'dmarc_policy';
	}

	/**
	 * Returns the category this check belongs to.
	 */
	public function get_category(): string {
		return 'dns';
	}

	/**
	 * Executes the DMARC check and returns a normalized result.
	 *
	 * @param DiagnosticContext $context The execution context for this check run.
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult {
		$domain = trim( $context->domain );

		if ( ! self::is_valid_domain( $domain ) ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'DMARC could not be checked because no valid sending domain is configured.'
			);
		}

		$dmarc_domain = '_dmarc.' . $domain;
		$records      = ( $this->lookup_txt_records )( $dmarc_domain );

		if ( false === $records ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'DMARC lookup for "%s" failed; the DNS query could not be completed.', $domain )
			);
		}

		$dmarc_values = array();
		foreach ( $records as $record ) {
			$txt = (string) ( $record['txt'] ?? '' );
			if ( 0 === stripos( $txt, 'v=dmarc1' ) ) {
				$dmarc_values[] = $txt;
			}
		}

		if ( array() === $dmarc_values ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'No DMARC record found for "%s".', $domain ),
				impact: 'Without a DMARC policy, receiving servers have no domain-level instruction on how to handle mail that fails SPF/DKIM checks, and you receive no visibility into spoofing attempts.',
				recommended_action: 'Publish a TXT record at "_dmarc.' . $domain . '" starting with "v=DMARC1".',
				score: 0
			);
		}

		if ( count( $dmarc_values ) > 1 ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'Multiple DMARC records found for "%s"; only one is permitted.', $domain ),
				evidence: implode( "\n", $dmarc_values ),
				impact: 'RFC 7489 requires exactly one DMARC record; receiving servers may ignore DMARC evaluation entirely when multiple records are present.',
				recommended_action: 'Remove the extra "_dmarc" TXT records, leaving exactly one.',
				score: 0,
				raw: array( 'records' => $dmarc_values )
			);
		}

		$dmarc  = $dmarc_values[0];
		$policy = self::extract_policy( $dmarc );

		if ( null === $policy ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DMARC record for "%s" is missing a valid "p=" policy tag.', $domain ),
				evidence: $dmarc,
				impact: 'A DMARC record without a recognized policy tag may be ignored by receiving servers.',
				recommended_action: 'Add a "p=" tag with a value of "none", "quarantine" or "reject".',
				score: 15,
				raw: array( 'record' => $dmarc )
			);
		}

		if ( 'none' === $policy ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DMARC record for "%s" is in monitor-only mode ("p=none").', $domain ),
				evidence: $dmarc,
				impact: 'Monitor-only mode provides reporting visibility but does not instruct receivers to quarantine or reject spoofed mail.',
				recommended_action: 'Once SPF/DKIM alignment is confirmed via DMARC reports, consider moving to "p=quarantine" or "p=reject".',
				score: 15,
				raw: array( 'record' => $dmarc )
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'A valid, enforcing DMARC record ("p=%s") was found for "%s".', $policy, $domain ),
			evidence: $dmarc,
			score: 25,
			raw: array( 'record' => $dmarc )
		);
	}

	/**
	 * Extracts the "p=" policy value from a DMARC record, if present and recognized.
	 *
	 * @param string $dmarc The DMARC record text to inspect.
	 * @return string|null One of 'none', 'quarantine', 'reject', or null if absent/unrecognized.
	 */
	private static function extract_policy( string $dmarc ): ?string {
		if ( 1 !== preg_match( '/(?:^|;)\s*p=(none|quarantine|reject)\s*(?:;|$)/i', $dmarc, $matches ) ) {
			return null;
		}

		return strtolower( $matches[1] );
	}
}
