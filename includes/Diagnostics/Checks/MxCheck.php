<?php
/**
 * MX record diagnostic check.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether DiagnosticContext::$domain has MX records.
 *
 * MX presence/absence only affects the domain's ability to receive mail; it
 * has no bearing on outbound send capability and must never be presented as
 * a deliverability signal. A DNS lookup failure is reported as 'unknown',
 * never as 'fail' or 'warn' — an inability to check is not evidence of a
 * problem.
 *
 * Ownership: Yaj / Diagnostics.
 */
final class MxCheck implements DiagnosticCheckInterface {

	use DomainValidation;

	/**
	 * MX record lookup function. Defaults to a thin wrapper around
	 * dns_get_record(); injectable so tests never perform real DNS queries.
	 *
	 * @var \Closure(string): (array|false)
	 */
	private \Closure $lookup_mx_records;

	/**
	 * Constructs the check, optionally overriding the MX lookup for tests.
	 *
	 * @param \Closure(string): (array|false) $lookup_mx_records Optional MX lookup override for tests.
	 */
	public function __construct( ?\Closure $lookup_mx_records = null ) {
		$this->lookup_mx_records = $lookup_mx_records ?? static function ( string $domain ): array|false {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() emits an E_WARNING on resolver failure; the false return value below is the only signal the caller needs.
			return @dns_get_record( $domain, DNS_MX );
		};
	}

	/**
	 * Returns the unique machine-readable identifier for this check.
	 */
	public function get_id(): string {
		return 'mx_record';
	}

	/**
	 * Returns the category this check belongs to.
	 */
	public function get_category(): string {
		return 'dns';
	}

	/**
	 * Executes the MX check and returns a normalized result.
	 *
	 * @param DiagnosticContext $context The execution context for this check run.
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult {
		$domain = trim( $context->domain );

		if ( ! self::is_valid_domain( $domain ) ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'MX records could not be checked because no valid sending domain is configured.'
			);
		}

		$records = ( $this->lookup_mx_records )( $domain );

		if ( false === $records ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'MX lookup for "%s" failed; the DNS query could not be completed.', $domain )
			);
		}

		$hosts = array();
		foreach ( $records as $record ) {
			$host = (string) ( $record['target'] ?? '' );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		if ( array() === $hosts ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'No MX records found for "%s".', $domain ),
				impact: 'This domain cannot receive incoming mail. This does not affect the ability to send mail through your configured provider.',
				recommended_action: 'Add MX records if this domain is expected to receive mail, such as replies or DMARC aggregate reports.',
				score: 15
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( '%d MX record(s) found for "%s".', count( $hosts ), $domain ),
			evidence: implode( "\n", $hosts ),
			score: 25,
			raw: array( 'records' => $hosts )
		);
	}
}
