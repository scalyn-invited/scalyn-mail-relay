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
		$domain = strtolower( trim( $context->domain ) );

		if ( ! self::is_valid_domain( $domain ) ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'DMARC could not be checked because no valid sending domain is configured.'
			);
		}

		// DMARCbis tree walk: the sending domain first, then parent domains above the TLD.
		$labels    = explode( '.', $domain );
		$candidate = null;
		$found     = array();
		$levels    = min( count( $labels ) - 1, 8 );
		for ( $i = 0; $i < $levels; $i++ ) {
			$candidate = implode( '.', array_slice( $labels, $i ) );
			$records   = ( $this->lookup_txt_records )( '_dmarc.' . $candidate );
			if ( false === $records ) {
				return new DiagnosticResult(
					status: 'unknown',
					severity: 'low',
					message: sprintf( 'DMARC lookup for "%s" failed; the DNS query could not be completed.', $candidate )
				);
			}
			$found = array();
			foreach ( $records as $record ) {
				$txt = (string) ( $record['txt'] ?? '' );
				if ( 0 === stripos( $txt, 'v=dmarc1' ) ) {
					$found[] = $txt;
				}
			}
			if ( array() !== $found ) {
				break;
			}
		}

		if ( array() === $found ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'No DMARC record found for "%s".', $domain ),
				impact: 'Without a DMARC policy, receiving servers have no domain-level instruction on how to handle mail that fails SPF/DKIM checks, and you receive no visibility into spoofing attempts.',
				recommended_action: 'Publish a TXT record at "_dmarc.' . $domain . '" starting with "v=DMARC1".'
			);
		}

		if ( count( $found ) > 1 ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'Multiple DMARC records found for "%s"; only one is permitted.', $candidate ),
				evidence: implode( "\n", $found ),
				impact: 'RFC 7489 requires exactly one DMARC record; receiving servers may ignore DMARC evaluation entirely when multiple records are present.',
				recommended_action: 'Remove the extra "_dmarc" TXT records, leaving exactly one.',
				raw: array( 'records' => $found )
			);
		}

		$dmarc     = $found[0];
		$inherited = $candidate !== $domain;
		$tags      = self::parse_tags( $dmarc );
		$policy    = self::extract_policy( $dmarc );
		$evidence  = $inherited ? $dmarc . "\nInherited from: _dmarc." . $candidate : $dmarc;
		$analysis  = self::analyze( $tags, $inherited, $context->settings );
		$raw       = array(
			'record'      => $dmarc,
			'policy_from' => $candidate,
			'inherited'   => $inherited,
			'alignment'   => $analysis['alignment'],
		);

		if ( null === $policy ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DMARC record for "%s" is missing a valid "p=" policy tag.', $candidate ),
				evidence: $evidence,
				impact: 'A DMARC record without a recognized policy tag may be ignored by receiving servers.',
				recommended_action: 'Add a "p=" tag with a value of "none", "quarantine" or "reject".',
				raw: $raw
			);
		}

		// A subdomain inherits the organizational record's sp= (or p= when sp= is absent).
		$effective          = $inherited && in_array( $tags['sp'] ?? '', array( 'none', 'quarantine', 'reject' ), true ) ? $tags['sp'] : $policy;
		$raw['policy']      = $effective;
		$raw['percentage']  = $analysis['pct'];
		$inherited_sentence = $inherited ? sprintf( ' The policy is inherited from "%s".', $candidate ) : '';

		if ( 'none' === $effective ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DMARC record for "%s" is in monitor-only mode ("p=none").', $domain ) . $inherited_sentence,
				evidence: $evidence,
				impact: 'Monitor-only mode provides reporting visibility but does not instruct receivers to quarantine or reject spoofed mail.',
				recommended_action: 'Once SPF/DKIM alignment is confirmed via DMARC reports, consider moving to "p=quarantine" or "p=reject".' . $analysis['advice'],
				raw: $raw
			);
		}

		if ( $analysis['problems'] ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DMARC record for "%s" enforces "%s" with limitations: %s', $domain, $effective, $analysis['problems'][0] ) . $inherited_sentence,
				evidence: $evidence . "\n" . implode( "\n", $analysis['problems'] ),
				impact: 'Limited or invalid DMARC settings reduce protection or cause receivers to ignore parts of the policy.',
				recommended_action: 'Correct the listed tags. Raise pct= to 100 once DMARC reports confirm legitimate mail aligns.' . $analysis['advice'],
				raw: $raw
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'A DMARC record with an enforcing policy ("p=%s") was found for "%s". Message authentication and alignment have not been verified.', $effective, $domain ) . $inherited_sentence,
			impact: 'An enforcing policy does not prove legitimate messages pass SPF or DKIM alignment. It may cause unauthenticated messages to be rejected.',
			recommended_action: 'Confirm SPF or DKIM authentication and alignment for actual messages using receiver results or DMARC reports. Publishing this policy alone does not authenticate email.' . $analysis['advice'],
			evidence: $evidence,
			raw: $raw
		);
	}

	/**
	 * Parses DMARC tags into a lowercase map; later duplicates are ignored.
	 *
	 * @param string $dmarc Record text.
	 * @return array<string, string>
	 */
	private static function parse_tags( string $dmarc ): array {
		$tags = array();
		foreach ( explode( ';', $dmarc ) as $part ) {
			if ( preg_match( '/^\s*([a-z]+)\s*=\s*(.*?)\s*$/iD', $part, $match ) ) {
				$name = strtolower( $match[1] );
				if ( ! isset( $tags[ $name ] ) ) {
					$tags[ $name ] = in_array( $name, array( 'rua', 'ruf' ), true ) ? $match[2] : strtolower( $match[2] );
				}
			}
		}
		return $tags;
	}

	/**
	 * Validates enforcement tags and describes configuration-based alignment.
	 *
	 * Alignment here is a configuration assessment only. It never states that
	 * messages align; that requires receiver results or DMARC aggregate reports.
	 *
	 * @param array<string, string> $tags Parsed tags.
	 * @param bool                  $inherited Whether the record belongs to a parent domain.
	 * @param array<string, mixed>  $settings Credential-free context settings.
	 * @return array problems, advice, pct and alignment.
	 */
	private static function analyze( array $tags, bool $inherited, array $settings ): array {
		$problems = array();
		$pct      = 100;
		if ( isset( $tags['pct'] ) ) {
			if ( ! preg_match( '/^\d{1,3}$/D', $tags['pct'] ) || (int) $tags['pct'] > 100 ) {
				$problems[] = 'the pct= value is invalid; receivers treat it as 100.';
			} else {
				$pct = (int) $tags['pct'];
				if ( $pct < 100 ) {
					$problems[] = sprintf( 'pct=%d applies the policy to only %d%% of failing mail.', $pct, $pct );
				}
			}
		}
		foreach ( array( 'adkim', 'aspf' ) as $mode ) {
			if ( isset( $tags[ $mode ] ) && ! in_array( $tags[ $mode ], array( 'r', 's' ), true ) ) {
				$problems[] = sprintf( 'the %s= value must be "r" or "s".', $mode );
			}
		}
		if ( isset( $tags['sp'] ) && ! in_array( $tags['sp'], array( 'none', 'quarantine', 'reject' ), true ) ) {
			$problems[] = 'the sp= value is not a recognized policy.';
		}
		$dkim_mode = 's' === ( $tags['adkim'] ?? 'r' ) ? 'strict' : 'relaxed';
		$spf_mode  = 's' === ( $tags['aspf'] ?? 'r' ) ? 'strict' : 'relaxed';
		$selector  = is_string( $settings['dkim_selector'] ?? null ) && '' !== $settings['dkim_selector'];
		// A selector configured under the From domain means a provider signing with it uses d=<From domain>, which aligns in either mode.
		$dkim   = $selector ? 'possible_with_configured_selector' : 'not_assessed';
		$advice = $selector
			? ' DKIM alignment is possible if your provider signs with the configured selector on this domain; confirm with message headers.'
			: ' Configure your provider\'s DKIM selector in Diagnostics so DKIM alignment can be assessed.';
		if ( empty( $tags['rua'] ) ) {
			$advice .= ' Add an rua= reporting address to receive aggregate reports that confirm real alignment.';
		}
		return array(
			'problems'  => $problems,
			'advice'    => $advice,
			'pct'       => $pct,
			'alignment' => array(
				'dkim_mode' => $dkim_mode,
				'spf_mode'  => $spf_mode,
				'dkim'      => $dkim,
				// The envelope-sender domain is chosen by the provider and is not observed here.
				'spf'       => 'not_assessed',
				'reporting' => ! empty( $tags['rua'] ),
				'subdomain' => $inherited,
			),
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
