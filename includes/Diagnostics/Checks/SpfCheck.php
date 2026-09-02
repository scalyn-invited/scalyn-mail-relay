<?php
/**
 * SPF record diagnostic check.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether DiagnosticContext::$domain has a single, well-formed SPF
 * TXT record.
 *
 * Evidence-based only: this check never claims deliverability, only reports
 * what DNS actually returns. A DNS lookup failure is reported as 'unknown',
 * never as 'fail' — an inability to check is not evidence of a problem.
 *
 * Ownership: Yaj / Diagnostics.
 */
final class SpfCheck implements DiagnosticCheckInterface {

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
		return 'spf_record';
	}

	/**
	 * Returns the category this check belongs to.
	 */
	public function get_category(): string {
		return 'dns';
	}

	/**
	 * Executes the SPF check and returns a normalized result.
	 *
	 * @param DiagnosticContext $context The execution context for this check run.
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult {
		$domain = trim( $context->domain );

		if ( ! self::is_valid_domain( $domain ) ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'SPF could not be checked because no valid sending domain is configured.'
			);
		}

		$records = ( $this->lookup_txt_records )( $domain );

		if ( false === $records ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'SPF lookup for "%s" failed; the DNS query could not be completed.', $domain )
			);
		}

		$spf_values = array();
		foreach ( $records as $record ) {
			$txt = (string) ( $record['txt'] ?? '' );
			if ( 0 === stripos( $txt, 'v=spf1' ) ) {
				$spf_values[] = $txt;
			}
		}

		if ( array() === $spf_values ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'No SPF record found for "%s".', $domain ),
				impact: 'Receiving mail servers cannot verify that your provider is authorized to send on behalf of this domain, increasing the risk of messages being marked as spam or rejected.',
				recommended_action: 'Add a TXT record starting with "v=spf1" authorizing your sending provider.',
				score: 0
			);
		}

		if ( count( $spf_values ) > 1 ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'Multiple SPF records found for "%s"; only one is permitted.', $domain ),
				evidence: implode( "\n", $spf_values ),
				impact: 'RFC 7208 treats multiple SPF records as a permanent error, which can cause receiving servers to fail SPF evaluation entirely.',
				recommended_action: 'Combine all authorized senders into a single SPF TXT record.',
				score: 0,
				raw: array( 'records' => $spf_values )
			);
		}

		$spf = $spf_values[0];

		if ( ! self::has_terminal_mechanism( $spf ) ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'SPF record for "%s" is missing a terminal mechanism.', $domain ),
				evidence: $spf,
				impact: 'Without an "all" mechanism or a "redirect", SPF evaluation may not behave as intended for senders not explicitly listed.',
				recommended_action: 'End the SPF record with "~all", "-all" or a "redirect=" modifier.',
				score: 15,
				raw: array( 'record' => $spf )
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'A valid SPF record was found for "%s".', $domain ),
			evidence: $spf,
			score: 25,
			raw: array( 'record' => $spf )
		);
	}

	/**
	 * Returns whether the SPF record ends with a recognized terminal mechanism
	 * (an "all" qualifier) or delegates evaluation via a "redirect=" modifier.
	 *
	 * @param string $spf The SPF record text to inspect.
	 */
	private static function has_terminal_mechanism( string $spf ): bool {
		return 1 === preg_match( '/(?:[~\-?+]all\b|redirect=)/i', $spf );
	}
}
