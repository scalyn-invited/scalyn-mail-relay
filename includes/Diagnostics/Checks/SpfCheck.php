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
				recommended_action: 'Add a TXT record starting with "v=spf1" authorizing your sending provider.'
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
				raw: array( 'records' => $spf_values )
			);
		}

		$spf        = $spf_values[0];
		$evaluation = ( new SpfEvaluator( $this->lookup_txt_records ) )->evaluate( $domain, $spf );
		$raw        = array(
			'record'     => $spf,
			'evaluation' => array(
				'lookups'      => $evaluation['lookups'],
				'lookup_limit' => SpfEvaluator::LOOKUP_LIMIT,
				'void_lookups' => $evaluation['void_lookups'],
				'includes'     => array_slice( $evaluation['includes'], 0, 25 ),
				'macros'       => $evaluation['macros'],
				'incomplete'   => array_slice( $evaluation['incomplete'], 0, 25 ),
			),
		);
		$chain      = $evaluation['includes'] ? "\nIncludes/redirects: " . implode( ', ', array_slice( $evaluation['includes'], 0, 25 ) ) : '';

		if ( $evaluation['errors'] ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'The SPF record for "%s" would fail evaluation: %s', $domain, $evaluation['errors'][0] ),
				evidence: $spf . $chain . "\n" . implode( "\n", $evaluation['errors'] ),
				impact: 'Receivers return an SPF permanent error or authorize unintended senders, so SPF cannot help authenticate your mail and DMARC must rely on DKIM alone.',
				recommended_action: 'Fix the listed SPF problems: remove unused includes, flatten nested includes or replace them with ip4/ip6 ranges to stay within 10 DNS lookups, and end the record with "~all" or "-all".',
				raw: $raw
			);
		}

		if ( $evaluation['incomplete'] ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'The SPF record for "%s" was found, but %d included polic%s could not be resolved, so evaluation is incomplete.', $domain, count( $evaluation['incomplete'] ), 1 === count( $evaluation['incomplete'] ) ? 'y' : 'ies' ),
				evidence: $spf . $chain,
				impact: 'An incomplete evaluation is not evidence of a working or broken SPF policy.',
				recommended_action: 'Run diagnostics again later. If the include keeps failing, confirm the included domain with its provider.',
				raw: $raw
			);
		}

		if ( ! self::has_terminal_mechanism( $spf ) && null === $evaluation['all'] ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'SPF record for "%s" is missing a terminal mechanism.', $domain ),
				evidence: $spf,
				impact: 'Without an "all" mechanism or a "redirect", SPF evaluation may not behave as intended for senders not explicitly listed.',
				recommended_action: 'End the SPF record with "~all", "-all" or a "redirect=" modifier.',
				raw: $raw
			);
		}

		$warnings = $evaluation['warnings'];
		if ( $evaluation['macros'] ) {
			$warnings[] = 'The SPF policy uses macros that depend on each message, so the lookup count is a lower bound.';
		}
		if ( $warnings ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'The SPF record for "%s" is valid but weak: %s', $domain, $warnings[0] ),
				evidence: $spf . $chain . "\n" . implode( "\n", $warnings ),
				impact: 'A weak SPF policy gives receivers little protection against spoofing and can reduce trust in your domain.',
				recommended_action: 'Replace "?all" with "~all" or "-all", and remove deprecated "ptr" mechanisms.',
				raw: $raw
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'The SPF record for "%s" is within the published-policy limits (%d of %d DNS lookups). Sending-IP authorization has not been evaluated.', $domain, $evaluation['lookups'], SpfEvaluator::LOOKUP_LIMIT ),
			impact: 'This evaluates the published policy, not an SPF authentication result. It does not test the actual outbound IP or envelope-sender (Return-Path) domain your provider uses.',
			recommended_action: 'Confirm the envelope-sender domain your provider uses. Many API providers use their own bounce domain unless you configure a custom Return-Path, in which case DMARC relies on DKIM alignment.',
			evidence: $spf . $chain,
			raw: $raw
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
