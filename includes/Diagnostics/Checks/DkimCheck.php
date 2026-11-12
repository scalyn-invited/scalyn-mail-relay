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

		$key_info = self::analyze_key( $dkim, $key );
		$raw      = array(
			'record'   => $dkim,
			'key_type' => $key_info['type'],
			'key_bits' => $key_info['bits'],
			'testing'  => $key_info['testing'],
		);

		if ( null !== $key_info['fail'] ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'DKIM key for selector "%s" on "%s" is unusable: %s', $selector, $domain, $key_info['fail'] ),
				evidence: $dkim,
				impact: 'Receivers reject or ignore signatures made with weak or unparseable keys, so DKIM cannot authenticate or align your mail.',
				recommended_action: 'Generate a new 2048-bit RSA key with your provider, publish it under a new selector, and update the selector here.',
				raw: $raw
			);
		}

		if ( $key_info['warnings'] ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'DKIM record for selector "%s" on "%s" has limitations: %s', $selector, $domain, $key_info['warnings'][0] ),
				evidence: $dkim . "\n" . implode( "\n", $key_info['warnings'] ),
				impact: 'Receivers may treat signatures as weaker or, in testing mode, as if the message were unsigned.',
				recommended_action: 'Use a 2048-bit RSA key, remove the testing flag (t=y) once signing is confirmed, and allow sha256 hashing.',
				raw: $raw
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'A DKIM record with a usable %s public key was found for selector "%s" on "%s". Message signatures have not been verified.', null !== $key_info['bits'] ? $key_info['bits'] . '-bit ' . strtoupper( $key_info['type'] ) : strtoupper( $key_info['type'] ), $selector, $domain ),
			impact: 'DNS publication alone does not prove the key is usable, that the provider signs outgoing messages, or that a receiving server will verify their signatures.',
			recommended_action: 'Verify provider-side DKIM signing and the actual message signing domain and selector. Entering a selector here does not enable signing; use receiver authentication results to confirm DKIM passes.',
			evidence: $dkim,
			raw: $raw
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

	/**
	 * Inspects key type, RSA modulus length, testing flag and hash restrictions.
	 * An unparseable key is a failure; an unavailable parser is reported as an
	 * unknown key length, never as a strong key.
	 *
	 * @param string $dkim Full record text.
	 * @param string $key Non-empty p= value.
	 * @return array type, bits, testing, fail and warnings.
	 */
	private static function analyze_key( string $dkim, string $key ): array {
		$tags = array();
		foreach ( explode( ';', $dkim ) as $part ) {
			if ( preg_match( '/^\s*([a-z]+)\s*=\s*(.*?)\s*$/iD', $part, $match ) && ! isset( $tags[ strtolower( $match[1] ) ] ) ) {
				$tags[ strtolower( $match[1] ) ] = $match[2];
			}
		}
		$type   = strtolower( $tags['k'] ?? 'rsa' );
		$flags  = array_map( 'trim', explode( ':', strtolower( $tags['t'] ?? '' ) ) );
		$result = array(
			'type'     => $type,
			'bits'     => null,
			'testing'  => in_array( 'y', $flags, true ),
			'fail'     => null,
			'warnings' => array(),
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Decoding a public DNS key for length analysis only.
		$bytes = base64_decode( preg_replace( '/\s+/', '', $key ), true );
		if ( false === $bytes || '' === $bytes ) {
			$result['fail'] = 'the public key is not valid base64.';
			return $result;
		}
		if ( 'ed25519' === $type ) {
			if ( 32 !== strlen( $bytes ) ) {
				$result['fail'] = 'the Ed25519 public key has the wrong length.';
			} else {
				$result['bits']       = 256;
				$result['warnings'][] = 'Ed25519 keys are not verified by every receiver; publish an RSA selector as well.';
			}
		} elseif ( 'rsa' === $type ) {
			$result['bits'] = self::rsa_bits( $bytes );
			if ( false === $result['bits'] ) {
				$result['bits'] = null;
				$result['fail'] = 'the RSA public key could not be parsed.';
			} elseif ( null !== $result['bits'] && $result['bits'] < 1024 ) {
				$result['fail'] = sprintf( 'the RSA key is only %d bits; receivers reject keys under 1024 bits.', $result['bits'] );
			} elseif ( null !== $result['bits'] && $result['bits'] < 2048 ) {
				$result['warnings'][] = sprintf( 'the RSA key is %d bits; 2048 bits is the current recommendation.', $result['bits'] );
			} elseif ( null === $result['bits'] ) {
				$result['warnings'][] = 'the RSA key length could not be determined on this server.';
			}
		} else {
			$result['fail'] = sprintf( 'the key type "%s" is not supported by receivers.', substr( $type, 0, 20 ) );
		}
		if ( $result['testing'] ) {
			$result['warnings'][] = 'the record is in testing mode (t=y), so receivers may treat signature failures as unsigned mail.';
		}
		if ( isset( $tags['h'] ) && ! in_array( 'sha256', array_map( 'trim', explode( ':', strtolower( $tags['h'] ) ) ), true ) ) {
			$result['warnings'][] = 'the record restricts hashing to algorithms other than sha256.';
		}
		return $result;
	}

	/**
	 * Returns the RSA modulus length of a DER SubjectPublicKeyInfo or RSAPublicKey.
	 *
	 * @param string $der Decoded key bytes.
	 * @return int|false|null Bits; false when malformed; null when OpenSSL is unavailable.
	 */
	private static function rsa_bits( string $der ): int|false|null {
		if ( ! function_exists( 'openssl_pkey_get_public' ) ) {
			return null;
		}
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- PEM wrapping of a public key.
		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			// Some providers publish a bare PKCS#1 RSAPublicKey.
			$pem = "-----BEGIN RSA PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END RSA PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- PEM wrapping of a public key.
			$key = openssl_pkey_get_public( $pem );
		}
		if ( false === $key ) {
			while ( openssl_error_string() ) {
				// Drain OpenSSL's error queue so later TLS checks are not polluted.
				continue;
			}
			return false;
		}
		$details = openssl_pkey_get_details( $key );
		return is_array( $details ) && OPENSSL_KEYTYPE_RSA === ( $details['type'] ?? null ) && is_int( $details['bits'] ?? null ) ? $details['bits'] : false;
	}
}
