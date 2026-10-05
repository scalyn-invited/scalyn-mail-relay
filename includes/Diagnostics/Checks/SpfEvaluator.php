<?php
/**
 * Bounded, offline-testable SPF record evaluation against RFC 7208 limits.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Walks include/redirect chains through an injected TXT lookup and counts the
 * DNS-querying terms that RFC 7208 section 4.6.4 limits to ten, plus void
 * lookups (limit two). It never evaluates a sending IP: the result describes the
 * published policy only. Unresolvable parts are reported as incomplete, never
 * as passing.
 */
final class SpfEvaluator {

	/** RFC 7208 DNS-querying term limit. */
	public const LOOKUP_LIMIT = 10;

	/** RFC 7208 void lookup limit. */
	public const VOID_LIMIT = 2;

	/** Hard bound on recursion work, independent of the published limit. */
	private const MAX_RECORDS = 25;

	/**
	 * Lookup state.
	 *
	 * @var array
	 */
	private array $state = array();

	/**
	 * Creates the evaluator.
	 *
	 * @param \Closure(string): (array|false) $lookup TXT lookup, false on resolver failure.
	 */
	public function __construct( private readonly \Closure $lookup ) {}

	/**
	 * Evaluates one already-selected SPF record.
	 *
	 * @param string $domain Domain that published the record.
	 * @param string $record The single v=spf1 record.
	 * @return array lookups, void_lookups, includes, errors, warnings, incomplete, macros, all.
	 */
	public function evaluate( string $domain, string $record ): array {
		$this->state = array(
			'lookups'      => 0,
			'void_lookups' => 0,
			'includes'     => array(),
			'errors'       => array(),
			'warnings'     => array(),
			'incomplete'   => array(),
			'macros'       => false,
			'all'          => null,
			'visited'      => array( strtolower( $domain ) => true ),
			'records'      => 1,
		);
		$this->walk( $record, true );
		if ( $this->state['lookups'] > self::LOOKUP_LIMIT ) {
			$this->state['errors'][] = sprintf( 'The SPF policy needs %d DNS lookups; receivers stop at %d and return a permanent error.', $this->state['lookups'], self::LOOKUP_LIMIT );
		}
		if ( $this->state['void_lookups'] > self::VOID_LIMIT ) {
			$this->state['errors'][] = sprintf( 'The SPF policy has %d void lookups; receivers may return a permanent error after %d.', $this->state['void_lookups'], self::VOID_LIMIT );
		}
		$result = $this->state;
		unset( $result['visited'], $result['records'] );
		$result['errors']   = array_values( array_unique( $result['errors'] ) );
		$result['warnings'] = array_values( array_unique( $result['warnings'] ) );
		return $result;
	}

	/**
	 * Evaluates the terms of one record.
	 *
	 * @param string $record SPF record text.
	 * @param bool   $root Whether this is the top-level record.
	 */
	private function walk( string $record, bool $root ): void {
		$terms    = preg_split( '/\s+/', trim( $record ) );
		$redirect = null;
		$has_all  = false;
		array_shift( $terms );
		foreach ( $terms as $term ) {
			if ( '' === $term ) {
				continue;
			}
			if ( preg_match( '/^([a-z][a-z0-9_.-]*)=(.*)$/iD', $term, $modifier ) ) {
				if ( 'redirect' === strtolower( $modifier[1] ) ) {
					if ( null !== $redirect ) {
						$this->state['errors'][] = 'The SPF record contains more than one redirect modifier.';
					}
					$redirect = $modifier[2];
				}
				// exp= and unknown modifiers are ignored by receivers.
				continue;
			}
			if ( ! preg_match( '/^([+\-~?]?)(all|include|a|mx|ptr|ip4|ip6|exists)(?:([:\/])(.*))?$/iD', $term, $parts ) ) {
				$this->state['errors'][] = sprintf( 'The SPF term "%s" is not valid syntax.', substr( $term, 0, 80 ) );
				continue;
			}
			$qualifier = '' === $parts[1] ? '+' : $parts[1];
			$mechanism = strtolower( $parts[2] );
			$value     = isset( $parts[4] ) && ':' === $parts[3] ? $parts[4] : '';
			switch ( $mechanism ) {
				case 'all':
					// RFC 7208 section 5.1: all takes neither a domain nor a CIDR suffix.
					if ( isset( $parts[3] ) ) {
						$this->state['errors'][] = 'The SPF all mechanism is not valid syntax: parameters are not permitted.';
						break;
					}
					$has_all = true;
					if ( $root ) {
						$this->state['all'] = $qualifier;
						if ( '+' === $qualifier ) {
							$this->state['errors'][] = 'The SPF record ends with "+all", which authorizes every server on the internet to send as this domain.';
						} elseif ( '?' === $qualifier ) {
							$this->state['warnings'][] = 'The SPF record ends with "?all" (neutral), which gives receivers no instruction for unauthorized senders.';
						}
					}
					break;
				case 'include':
					++$this->state['lookups'];
					if ( '' === $value ) {
						$this->state['errors'][] = 'An SPF include mechanism has no domain.';
						break;
					}
					$this->follow( $value, 'include', false );
					break;
				case 'ptr':
					++$this->state['lookups'];
					$this->state['warnings'][] = 'The SPF record uses the deprecated "ptr" mechanism, which is slow and unreliable; some receivers ignore it.';
					break;
				case 'a':
				case 'mx':
				case 'exists':
					++$this->state['lookups'];
					if ( 'exists' === $mechanism && '' === $value ) {
						$this->state['errors'][] = 'An SPF exists mechanism has no domain.';
					}
					if ( str_contains( $value, '%' ) ) {
						$this->state['macros'] = true;
					}
					break;
				default:
					if ( ! $this->valid_network( $mechanism, (string) ( $parts[4] ?? '' ), (string) ( $parts[3] ?? '' ) ) ) {
						$this->state['errors'][] = sprintf( 'The SPF term "%s" is not a valid network address.', substr( $term, 0, 80 ) );
					}
			}
			if ( $this->state['lookups'] > self::LOOKUP_LIMIT * 2 ) {
				return;
			}
		}
		// RFC 7208 section 6.1: redirect is ignored when the record contains "all".
		if ( null !== $redirect && ! $has_all ) {
			++$this->state['lookups'];
			$this->follow( $redirect, 'redirect', $root );
		}
	}

	/**
	 * Resolves an include or redirect target.
	 *
	 * @param string $target Domain specification.
	 * @param string $kind include or redirect.
	 * @param bool   $root Whether the target replaces the top-level policy.
	 */
	private function follow( string $target, string $kind, bool $root ): void {
		$target = strtolower( rtrim( $target, '.' ) );
		if ( str_contains( $target, '%' ) ) {
			// Macros depend on the message and sender; they cannot be expanded offline.
			$this->state['macros'] = true;
			return;
		}
		if ( ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/D', $target ) ) {
			$this->state['errors'][] = sprintf( 'The SPF %s target is not a valid domain.', $kind );
			return;
		}
		$this->state['includes'][] = $target;
		if ( isset( $this->state['visited'][ $target ] ) ) {
			// A cycle never terminates; receivers exceed the lookup limit and fail permanently.
			$this->state['errors'][] = sprintf( 'The SPF %s chain loops back to "%s".', $kind, $target );
			return;
		}
		if ( ++$this->state['records'] > self::MAX_RECORDS ) {
			return;
		}
		$records = ( $this->lookup )( $target );
		if ( false === $records ) {
			$this->state['incomplete'][] = $target;
			return;
		}
		$spf = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$txt = is_array( $record ) ? (string) ( $record['txt'] ?? '' ) : '';
			if ( preg_match( '/^v=spf1(?:\s|$)/i', $txt ) ) {
				$spf[] = $txt;
			}
		}
		if ( array() === $spf ) {
			if ( array() === $records ) {
				// Void lookup: the name returned no records at all.
				++$this->state['void_lookups'];
			}
			$this->state['errors'][] = sprintf( 'The SPF %s "%s" has no SPF record; receivers treat this as a permanent error.', $kind, $target );
			return;
		}
		if ( count( $spf ) > 1 ) {
			$this->state['errors'][] = sprintf( 'The SPF %s "%s" publishes more than one SPF record.', $kind, $target );
			return;
		}
		// Only the current chain counts as a loop; repeated includes in separate branches are legal.
		$this->state['visited'][ $target ] = true;
		// A redirect replaces the policy, so its "all" acts as the domain's own.
		$this->walk( $spf[0], $root );
		unset( $this->state['visited'][ $target ] );
	}

	/**
	 * Validates ip4/ip6 mechanism values.
	 *
	 * @param string $mechanism ip4 or ip6.
	 * @param string $value Address with optional prefix.
	 * @param string $separator Separator captured after the mechanism name.
	 */
	private function valid_network( string $mechanism, string $value, string $separator ): bool {
		if ( ':' !== $separator || ! preg_match( '/^([^\/]+)(?:\/(\d{1,3}))?$/D', $value, $parts ) ) {
			return false;
		}
		$flag = 'ip4' === $mechanism ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
		$max  = 'ip4' === $mechanism ? 32 : 128;
		return false !== filter_var( $parts[1], FILTER_VALIDATE_IP, $flag ) && ( ! isset( $parts[2] ) || (int) $parts[2] <= $max );
	}
}
