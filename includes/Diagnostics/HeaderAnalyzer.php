<?php
/**
 * Transient analysis of pasted message headers from a received test message.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy boundary: input is processed in memory and never stored, logged or
 * echoed. Output contains only domains, receiver verdicts, the receiving
 * server's authserv-id and hop counts. No local parts, display names,
 * subjects, message IDs, IP addresses or body content leave this class.
 *
 * Evidence scope: one message, as judged by one receiver at one time. It is
 * not a delivery guarantee and is not added to scores or history.
 */
final class HeaderAnalyzer {

	/** Maximum accepted input bytes. */
	public const MAX_BYTES = 65536;

	/** Authentication result vocabulary (RFC 8601). */
	private const RESULTS = array( 'pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror', 'policy', 'hardfail', 'bestguesspass' );

	/**
	 * Analyzes a pasted header block.
	 *
	 * @param string $input Raw pasted headers (a pasted body is ignored).
	 * @return array status, findings and summary; plain text only.
	 * @throws \InvalidArgumentException When the input is too large or not a header block.
	 */
	public function analyze( #[\SensitiveParameter] string $input ): array {
		if ( strlen( $input ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'The pasted headers are too large.' );
		}
		$headers = $this->parse( $input );
		if ( count( $headers ) < 2 ) {
			throw new \InvalidArgumentException( 'No message headers were found.' );
		}
		$from       = $this->domain_of( $this->first( $headers, 'from' ) );
		$ar         = $this->first( $headers, 'authentication-results' );
		$signatures = array();
		foreach ( $this->all( $headers, 'dkim-signature' ) as $signature ) {
			$tags = $this->tags( $signature );
			if ( isset( $tags['d'] ) && $this->valid_domain( $tags['d'] ) ) {
				$signatures[] = array(
					'd' => strtolower( $tags['d'] ),
					's' => isset( $tags['s'] ) && preg_match( '/^[A-Za-z0-9._-]{1,63}$/D', $tags['s'] ) ? $tags['s'] : '',
				);
			}
		}
		$received = $this->all( $headers, 'received' );
		$tls      = (bool) array_filter( $received, static fn( string $hop ): bool => (bool) preg_match( '/\b(ESMTPS|ESMTPSA|TLS\s*1\.[0-3]|version=TLS)/i', $hop ) );
		$summary  = array(
			'from_domain'  => $from,
			'receiver'     => '',
			'hops'         => count( $received ),
			'tls_observed' => $tls,
			'signatures'   => array_slice( $signatures, 0, 5 ),
		);
		$findings = array();
		if ( '' === $from ) {
			$findings[] = $this->finding( 'From domain', 'unknown', 'The From header is missing or has no valid domain, so alignment cannot be assessed.' );
		}
		if ( null === $ar ) {
			$findings[] = $this->finding( 'Receiver verdict', 'unknown', 'No Authentication-Results header was found. Paste the headers from the copy in the recipient mailbox (for example "Show original" in Gmail), not from your sent folder.' );
			return array(
				'status'   => 'unknown',
				'findings' => $findings,
				'summary'  => $summary,
			);
		}
		$parsed              = $this->parse_results( $ar );
		$summary['receiver'] = $parsed['authserv_id'];

		// SPF: receiver result and envelope-sender alignment with the From domain.
		$spf         = $parsed['spf'][0] ?? null;
		$spf_aligned = 'unknown';
		if ( null === $spf ) {
			$findings[] = $this->finding( 'SPF', 'unknown', 'The receiver did not report an SPF result.' );
		} else {
			$mailfrom    = $this->domain_of( $spf['props']['smtp.mailfrom'] ?? ( $spf['props']['smtp.helo'] ?? '' ) );
			$spf_aligned = $this->alignment( $mailfrom, $from );
			$findings[]  = $this->finding(
				'SPF',
				'pass' === $spf['result'] ? ( 'no' === $spf_aligned ? 'warn' : 'pass' ) : ( in_array( $spf['result'], array( 'fail', 'softfail', 'permerror', 'hardfail' ), true ) ? 'fail' : 'unknown' ),
				sprintf(
					'Receiver result: %s for envelope domain "%s". Alignment with From domain "%s": %s.',
					$spf['result'],
					'' !== $mailfrom ? $mailfrom : 'not reported',
					'' !== $from ? $from : 'unknown',
					$this->alignment_text( $spf_aligned )
				) . ( 'no' === $spf_aligned && 'pass' === $spf['result'] ? ' SPF passed for the provider\'s bounce domain, so it cannot satisfy DMARC on its own.' : '' )
			);
		}

		// DKIM: any passing signature whose d= aligns with the From domain.
		$dkim_aligned = 'unknown';
		if ( array() === $parsed['dkim'] ) {
			$findings[] = $this->finding( 'DKIM', 'unknown', 'The receiver did not report a DKIM result.' );
		} else {
			$parts = array();
			$best  = 'unknown';
			foreach ( array_slice( $parsed['dkim'], 0, 5 ) as $dkim ) {
				if ( 'unknown' === $best && in_array( $dkim['result'], array( 'fail', 'permerror', 'policy' ), true ) ) {
					$best = 'fail';
				}
				$domain  = strtolower( (string) ( $dkim['props']['header.d'] ?? $this->domain_of( $dkim['props']['header.i'] ?? '' ) ) );
				$domain  = $this->valid_domain( $domain ) ? $domain : '';
				$aligned = $this->alignment( $domain, $from );
				$parts[] = sprintf( '%s for "%s" (alignment: %s)', $dkim['result'], '' !== $domain ? $domain : 'unknown domain', $this->alignment_text( $aligned ) );
				if ( 'pass' === $dkim['result'] ) {
					if ( in_array( $aligned, array( 'strict', 'relaxed' ), true ) ) {
						$dkim_aligned = 'yes';
						$best         = 'pass';
					} elseif ( 'possible' === $aligned && 'pass' !== $best ) {
						$dkim_aligned = 'possible';
						$best         = 'warn';
					} elseif ( 'pass' !== $best ) {
						$dkim_aligned = 'yes' === $dkim_aligned ? 'yes' : 'no';
						$best         = 'warn';
					}
				}
			}
			$findings[] = $this->finding(
				'DKIM',
				$best,
				'Receiver results: ' . implode( '; ', $parts ) . '.' . ( 'warn' === $best ? ' A signature passed, but not for a domain aligned with the From domain; configure DKIM signing with your own domain at your provider.' : '' )
			);
		}

		// DMARC: our computation from aligned passes, compared with the receiver's verdict.
		$computed = 'yes' === $dkim_aligned || ( null !== $spf && 'pass' === $spf['result'] && in_array( $spf_aligned, array( 'strict', 'relaxed' ), true ) );
		$receiver = $parsed['dmarc'][0]['result'] ?? null;
		if ( null !== $receiver ) {
			$status     = 'pass' === $receiver ? 'pass' : ( in_array( $receiver, array( 'fail', 'permerror' ), true ) ? 'fail' : 'unknown' );
			$findings[] = $this->finding(
				'DMARC',
				$status,
				sprintf( 'Receiver verdict: %s.', $receiver ) . ( 'pass' === $receiver && ! $computed ? ' The receiver passed DMARC using organizational-domain rules this tool approximates; trust the receiver verdict.' : '' )
			);
		} else {
			$findings[] = $this->finding(
				'DMARC',
				'unknown',
				$computed ? 'The receiver reported no DMARC verdict. An SPF or DKIM pass with a matching or related domain was observed, but the applicable DMARC policy and alignment mode are not known; DMARC is not assessed.' : 'The receiver reported no DMARC verdict, and no aligned pass was observed.'
			);
		}
		$findings[] = $this->finding( 'Transport', $tls ? 'pass' : 'unknown', $tls ? sprintf( 'TLS was observed on the delivery path (%d %s recorded).', count( $received ), 1 === count( $received ) ? 'hop' : 'hops' ) : sprintf( 'TLS was not identifiable in %d Received %s. This is not proof that TLS was absent.', count( $received ), 1 === count( $received ) ? 'header' : 'headers' ) );

		// The DMARC outcome is the message-level verdict; an unaligned SPF pass alone does not lower it.
		$dmarc_status = '';
		foreach ( $findings as $finding ) {
			if ( 'DMARC' === $finding['label'] ) {
				$dmarc_status = $finding['status'];
			}
		}
		$overall = $dmarc_status;
		if ( 'pass' === $overall && in_array( 'fail', array_column( $findings, 'status' ), true ) ) {
			$overall = 'warn';
		}
		return array(
			'status'   => $overall,
			'findings' => $findings,
			'summary'  => $summary,
		);
	}

	/**
	 * Unfolds header lines into name/value pairs; stops at the body separator.
	 *
	 * @param string $input Raw text.
	 * @return array<int, array{0:string,1:string}>
	 */
	private function parse( string $input ): array {
		$lines   = preg_split( '/\r\n|\r|\n/', ltrim( $input ) );
		$headers = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				break;
			}
			if ( preg_match( '/^[ \t]/', $line ) && $headers ) {
				$headers[ count( $headers ) - 1 ][1] .= ' ' . trim( $line );
			} elseif ( preg_match( '/^([!-9;-~]{1,76}):[ \t]*(.*)$/D', $line, $match ) ) {
				$headers[] = array( strtolower( $match[1] ), trim( $match[2] ) );
			}
			if ( count( $headers ) > 500 ) {
				break;
			}
		}
		return $headers;
	}

	/**
	 * First value of a header, or null.
	 *
	 * @param array  $headers Parsed headers.
	 * @param string $name Lowercase name.
	 */
	private function first( array $headers, string $name ): ?string {
		foreach ( $headers as $header ) {
			if ( $name === $header[0] ) {
				return $header[1];
			}
		}
		return null;
	}

	/**
	 * All values of a header in order.
	 *
	 * @param array  $headers Parsed headers.
	 * @param string $name Lowercase name.
	 * @return string[]
	 */
	private function all( array $headers, string $name ): array {
		return array_values( array_map( static fn( array $h ): string => $h[1], array_filter( $headers, static fn( array $h ): bool => $name === $h[0] ) ) );
	}

	/**
	 * Parses tag=value; lists such as DKIM-Signature.
	 *
	 * @param string $value Header value.
	 * @return array<string, string>
	 */
	private function tags( string $value ): array {
		$tags = array();
		foreach ( explode( ';', $value ) as $part ) {
			if ( preg_match( '/^\s*([a-z]+)\s*=\s*(.*?)\s*$/iD', $part, $match ) ) {
				$tags[ strtolower( $match[1] ) ] = preg_replace( '/\s+/', '', $match[2] );
			}
		}
		return $tags;
	}

	/**
	 * Parses an Authentication-Results value into method results and properties.
	 *
	 * @param string $value Header value.
	 * @return array authserv_id plus spf, dkim and dmarc result lists.
	 */
	private function parse_results( string $value ): array {
		// Comments can contain addresses and IPs; remove them before parsing.
		$value  = preg_replace( '/\([^()]*\)/', ' ', $value );
		$parts  = explode( ';', $value );
		$server = strtolower( trim( (string) preg_replace( '/\s.*$/s', '', trim( (string) array_shift( $parts ) ) ) ) );
		$out    = array(
			'authserv_id' => $this->valid_domain( $server ) ? $server : '',
			'spf'         => array(),
			'dkim'        => array(),
			'dmarc'       => array(),
		);
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^\s*(spf|dkim|dmarc)\s*=\s*([a-z]+)\b(.*)$/is', $part, $match ) ) {
				continue;
			}
			$result = strtolower( $match[2] );
			if ( ! in_array( $result, self::RESULTS, true ) ) {
				continue;
			}
			preg_match_all( '/\b([a-z]+\.[a-z-]+)\s*=\s*("[^"]*"|[^\s;]+)/i', $match[3], $props, PREG_SET_ORDER );
			$properties = array();
			foreach ( $props as $prop ) {
				$properties[ strtolower( $prop[1] ) ] = trim( $prop[2], '"' );
			}
			$out[ strtolower( $match[1] ) ][] = array(
				'result' => $result,
				'props'  => $properties,
			);
		}
		return $out;
	}

	/**
	 * Returns only the lowercase domain of an address or domain value.
	 *
	 * @param string|null $value Header value, address or domain.
	 */
	private function domain_of( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( preg_match( '/<([^<>]*)>\s*$/', $value, $match ) ) {
			$value = $match[1];
		}
		$value  = trim( $value, " \t\"'" );
		$at     = strrpos( $value, '@' );
		$domain = strtolower( rtrim( false === $at ? $value : substr( $value, $at + 1 ), '.>' ) );
		return $this->valid_domain( $domain ) ? $domain : '';
	}

	/**
	 * Validates a hostname-style domain.
	 *
	 * @param string $domain Candidate.
	 */
	private function valid_domain( string $domain ): bool {
		return (bool) preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/iD', $domain );
	}

	/**
	 * Identifier alignment without a public suffix list.
	 *
	 * @param string $domain Authenticated domain.
	 * @param string $from From domain.
	 * @return string strict, relaxed, possible, no or unknown.
	 */
	private function alignment( string $domain, string $from ): string {
		if ( '' === $domain || '' === $from ) {
			return 'unknown';
		}
		if ( $domain === $from ) {
			return 'strict';
		}
		if ( str_ends_with( $from, '.' . $domain ) || str_ends_with( $domain, '.' . $from ) ) {
			return 'relaxed';
		}
		$a = explode( '.', $domain );
		$b = explode( '.', $from );
		// Siblings under a shared parent may share an organizational domain; that needs the public suffix list.
		return count( $a ) > 2 && count( $b ) > 2 && array_slice( $a, -2 ) === array_slice( $b, -2 ) ? 'possible' : 'no';
	}

	/**
	 * Human-readable alignment.
	 *
	 * @param string $alignment Alignment code.
	 */
	private function alignment_text( string $alignment ): string {
		return array(
			'strict'   => 'aligned (exact domain)',
			'relaxed'  => 'aligned (relaxed, subdomain)',
			'possible' => 'possibly aligned (shared parent domain; depends on the organizational domain)',
			'no'       => 'not aligned',
			'unknown'  => 'not assessable',
		)[ $alignment ] ?? 'not assessable';
	}

	/**
	 * Builds a finding.
	 *
	 * @param string $label Mechanism.
	 * @param string $status pass, warn, fail or unknown.
	 * @param string $text Explanation.
	 */
	private function finding( string $label, string $status, string $text ): array {
		return array(
			'label'  => $label,
			'status' => $status,
			'text'   => $text,
		);
	}
}
