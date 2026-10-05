<?php
/**
 * Forward-confirmed reverse DNS for a known SMTP server.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Runs only where the sending infrastructure is known: the configured SMTP
 * host's public addresses. API providers and private relays operate outbound
 * servers that this plugin cannot observe, so they are reported as not assessed.
 *
 * The configured host is the submission server. Its reverse DNS matters for
 * recipient acceptance only when that server delivers directly to recipients;
 * results say so explicitly. This check is in its own "infrastructure"
 * category and is not part of the configuration score.
 */
final class ReverseDnsCheck implements DiagnosticCheckInterface {

	/** Maximum addresses examined per host. */
	private const MAX_ADDRESSES = 4;

	/**
	 * Host to address lookup.
	 *
	 * @var \Closure(string): (array|false)
	 */
	private \Closure $resolve;

	/**
	 * Address to PTR name lookup.
	 *
	 * @var \Closure(string): (array|false)
	 */
	private \Closure $reverse;

	/**
	 * Constructs the check with injectable resolvers for offline tests.
	 *
	 * @param \Closure|null $resolve Host to IP list, false on resolver failure.
	 * @param \Closure|null $reverse IP to PTR hostnames, false on resolver failure.
	 */
	public function __construct( ?\Closure $resolve = null, ?\Closure $reverse = null ) {
		$this->resolve = $resolve ?? static function ( string $host ): array|false {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Resolver failure is signalled by the false return.
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( false === $records ) {
				return false;
			}
			$ips = array();
			foreach ( $records as $record ) {
				$ips[] = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
			}
			return array_values( array_filter( $ips ) );
		};
		$this->reverse = $reverse ?? static function ( string $ip ): array|false {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return false;
			}
			$name = 4 === strlen( $packed )
				? implode( '.', array_reverse( explode( '.', $ip ) ) ) . '.in-addr.arpa'
				: implode( '.', array_reverse( str_split( bin2hex( $packed ) ) ) ) . '.ip6.arpa';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Resolver failure is signalled by the false return.
			$records = @dns_get_record( $name, DNS_PTR );
			if ( false === $records ) {
				return false;
			}
			return array_values( array_filter( array_map( static fn( array $record ): string => (string) ( $record['target'] ?? '' ), $records ) ) );
		};
	}

	/** Returns the unique machine-readable identifier for this check. */
	public function get_id(): string {
		return 'reverse_dns';
	}

	/** Excluded from the configuration score until a scoring policy includes it. */
	public function get_category(): string {
		return 'infrastructure';
	}

	/**
	 * Evaluates forward-confirmed reverse DNS for the configured SMTP host.
	 *
	 * @param DiagnosticContext $context Credential-free context.
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult {
		$host = strtolower( trim( (string) ( $context->settings['host'] ?? '' ) ) );
		if ( '' === $host ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'Reverse DNS was not assessed because no SMTP host is configured. API providers operate their own outbound servers, which this plugin cannot observe.',
				impact: 'Not assessed is not a pass. The provider is responsible for its sending infrastructure.'
			);
		}
		if ( 'localhost' === $host ) {
			$addresses = array( '127.0.0.1' );
		} elseif ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$addresses = array( $host );
		} elseif ( ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $host ) ) {
			return new DiagnosticResult( status: 'unknown', severity: 'low', message: 'Reverse DNS was not assessed because the SMTP host is not a valid hostname.' );
		} else {
			$addresses = ( $this->resolve )( $host );
			if ( false === $addresses || array() === $addresses ) {
				return new DiagnosticResult( status: 'unknown', severity: 'low', message: sprintf( 'Reverse DNS was not assessed because "%s" could not be resolved.', $host ) );
			}
		}
		$public = array_values(
			array_filter(
				array_unique( $addresses ),
				static fn( $ip ): bool => is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
			)
		);
		if ( array() === $public ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'Reverse DNS was not assessed because "%s" is a private or local relay; the public sending address is not known.', $host ),
				impact: 'The server that finally delivers to recipients is outside this plugin\'s view.'
			);
		}
		$lines      = array();
		$confirmed  = 0;
		$problems   = 0;
		$incomplete = 0;
		$details    = array();
		foreach ( array_slice( $public, 0, self::MAX_ADDRESSES ) as $ip ) {
			$names = ( $this->reverse )( $ip );
			if ( false === $names ) {
				++$incomplete;
				$lines[]   = $ip . ' → lookup failed';
				$details[] = array(
					'ip'    => $ip,
					'state' => 'unknown',
				);
				continue;
			}
			if ( array() === $names ) {
				++$problems;
				$lines[]   = $ip . ' → no PTR record';
				$details[] = array(
					'ip'    => $ip,
					'state' => 'missing',
				);
				continue;
			}
			$match          = null;
			$forward_failed = false;
			foreach ( array_slice( $names, 0, 3 ) as $name ) {
				$name    = strtolower( rtrim( $name, '.' ) );
				$forward = ( $this->resolve )( $name );
				if ( false === $forward ) {
					$forward_failed = true;
					continue;
				}
				// Compare binary forms so equivalent IPv6 spellings match.
				$packed = inet_pton( $ip );
				if ( is_array( $forward ) && in_array( $packed, array_map( static fn( $candidate ) => is_string( $candidate ) && false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ? inet_pton( $candidate ) : false, $forward ), true ) ) {
					$match = $name;
					break;
				}
			}
			if ( null !== $match ) {
				++$confirmed;
				$lines[]   = $ip . ' → ' . $match . ' (forward-confirmed)';
				$details[] = array(
					'ip'    => $ip,
					'state' => 'confirmed',
					'ptr'   => $match,
				);
			} elseif ( $forward_failed ) {
				++$incomplete;
				$lines[]   = $ip . ' → forward lookup failed; confirmation unavailable';
				$details[] = array(
					'ip'    => $ip,
					'state' => 'unknown',
				);
			} else {
				++$problems;
				$first     = strtolower( rtrim( $names[0], '.' ) );
				$lines[]   = $ip . ' → ' . $first . ' (does not resolve back)';
				$details[] = array(
					'ip'    => $ip,
					'state' => 'unconfirmed',
					'ptr'   => $first,
				);
			}
		}
		$raw      = array(
			'host'      => $host,
			'addresses' => $details,
		);
		$evidence = implode( "\n", $lines );
		$scope    = 'This applies to the submission server. It affects recipient acceptance only if this server delivers mail directly to recipients rather than relaying through a provider.';
		if ( $problems > 0 ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( '%d of %d checked address%s for "%s" %s forward-confirmed reverse DNS.', $problems, count( $details ), 1 === count( $details ) ? '' : 'es', $host, 1 === $problems ? 'lacks' : 'lack' ),
				evidence: $evidence,
				impact: 'Many receivers reject or penalize mail from servers without matching reverse DNS. ' . $scope,
				recommended_action: 'Ask the server\'s hosting provider to set a PTR record for each address that resolves back to the same address, ideally a name in your domain.',
				raw: $raw
			);
		}
		if ( $incomplete > 0 ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'Reverse DNS for "%s" could not be fully checked.', $host ),
				evidence: $evidence,
				impact: 'An incomplete check is not evidence of a working or broken configuration.',
				recommended_action: 'Run diagnostics again later.',
				raw: $raw
			);
		}
		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'All %d checked address%s for "%s" have forward-confirmed reverse DNS.', $confirmed, 1 === $confirmed ? '' : 'es', $host ),
			evidence: $evidence,
			impact: $scope,
			recommended_action: 'No action needed for the submission server. If a provider relays your mail, its outbound servers are its responsibility.',
			raw: $raw
		);
	}
}
