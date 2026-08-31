<?php
/**
 * SMTP reachability and TLS diagnostic check.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Mail;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports on reachability, STARTTLS/TLS negotiation and certificate health
 * for the configured SMTP host, without ever authenticating.
 *
 * DiagnosticContext::$settings must never contain credentials, so this check
 * cannot and does not attempt a real login. "Authentication stage" evidence
 * is limited to whether the server advertises AUTH support in its EHLO
 * response — never a login attempt.
 *
 * A connection/DNS/timeout failure that leaves no real evidence is reported
 * as 'unknown', never 'fail' — an inability to check is not evidence of a
 * problem. An explicit rejection (connection refused, TLS handshake failure,
 * expired/mismatched certificate) is evidence and is reported as 'fail' or
 * 'warn' accordingly.
 *
 * Ownership: Saturn / Mail-Providers.
 */
final class SmtpTlsCheck implements DiagnosticCheckInterface {

	/**
	 * Number of days before expiry at which a valid certificate starts
	 * producing a 'warn' instead of a 'pass'.
	 */
	private const CERT_EXPIRY_WARNING_DAYS = 14;

	/**
	 * URL-scheme prefixes that are never valid in an SMTP hostname/IP field.
	 * Mirrors SmtpProvider::FORBIDDEN_HOST_SCHEMES.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_HOST_SCHEMES = array(
		'http://',
		'https://',
		'smtp://',
		'smtps://',
		'ftp://',
		'ftps://',
	);

	/**
	 * Performs the actual reachability/STARTTLS/TLS probe. Injectable so
	 * tests never open a real socket.
	 *
	 * @var \Closure(string, int, string): (array|false)
	 */
	private \Closure $probe;

	/**
	 * Constructs the check, optionally overriding the probe for tests.
	 *
	 * @param \Closure(string, int, string): (array|false) $probe Optional probe override for tests.
	 *                                                             Receives (host, port, encryption) and returns
	 *                                                             either false (probe could not be attempted) or
	 *                                                             an associative array with keys: reachable (bool),
	 *                                                             error (?string: 'timeout'|'refused'|'dns'|'other'),
	 *                                                             starttls_offered (bool), auth_offered (bool),
	 *                                                             tls_negotiated (bool), protocol (?string),
	 *                                                             cipher (?string), cert (?array{subject_cn: string,
	 *                                                             issuer_cn: string, expired: bool,
	 *                                                             expires_in_days: int, hostname_match: bool}).
	 */
	public function __construct( ?\Closure $probe = null ) {
		$this->probe = $probe ?? static function ( string $host, int $port, string $encryption ): array|false {
			return self::default_probe( $host, $port, $encryption );
		};
	}

	/**
	 * Returns the unique machine-readable identifier for this check.
	 */
	public function get_id(): string {
		return 'smtp_tls';
	}

	/**
	 * Returns the category this check belongs to.
	 */
	public function get_category(): string {
		return 'smtp';
	}

	/**
	 * Executes the SMTP/TLS check and returns a normalized result.
	 *
	 * @param DiagnosticContext $context The execution context for this check run.
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult {
		$settings   = $context->settings;
		$host       = trim( (string) ( $settings['host'] ?? '' ) );
		$port       = (int) ( $settings['port'] ?? 0 );
		$encryption = (string) ( $settings['encryption'] ?? 'tls' );

		if ( ! self::is_valid_host( $host ) || $port < 1 || $port > 65535 ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: 'SMTP/TLS could not be checked because no valid SMTP host and port are configured.'
			);
		}

		$probe = ( $this->probe )( $host, $port, $encryption );

		if ( false === $probe ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'The SMTP/TLS probe for "%s:%d" could not be completed.', $host, $port )
			);
		}

		if ( ! (bool) ( $probe['reachable'] ?? false ) ) {
			return $this->connection_failure_result( $host, $port, (string) ( $probe['error'] ?? 'other' ) );
		}

		if ( 'none' === $encryption ) {
			return $this->result_for_unencrypted_connection( $probe );
		}

		return $this->result_for_encrypted_connection( $host, $encryption, $probe );
	}

	/**
	 * Builds the result for a connection that never became reachable.
	 *
	 * @param string $host     The configured SMTP host.
	 * @param int    $port     The configured SMTP port.
	 * @param string $category The classified connection failure reason.
	 */
	private function connection_failure_result( string $host, int $port, string $category ): DiagnosticResult {
		if ( 'timeout' === $category || 'dns' === $category ) {
			return new DiagnosticResult(
				status: 'unknown',
				severity: 'low',
				message: sprintf( 'Connecting to "%s:%d" did not complete; the failure may be transient.', $host, $port ),
				raw: array(
					'stage'    => 'connect',
					'category' => $category,
				)
			);
		}

		return new DiagnosticResult(
			status: 'fail',
			severity: 'high',
			message: sprintf( 'Unable to connect to "%s:%d".', $host, $port ),
			impact: 'Mail cannot be sent through this server while it is unreachable.',
			recommended_action: 'Verify the configured SMTP host and port, and confirm the server allows connections from this network.',
			raw: array(
				'stage'    => 'connect',
				'category' => $category,
			)
		);
	}

	/**
	 * Builds the result when encryption is explicitly disabled ('none').
	 *
	 * @param array $probe The completed probe data.
	 */
	private function result_for_unencrypted_connection( array $probe ): DiagnosticResult {
		$evidence = $this->capability_evidence( $probe );

		if ( (bool) ( $probe['starttls_offered'] ?? false ) ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: 'The server supports STARTTLS, but encryption is disabled in the current configuration.',
				evidence: $evidence,
				impact: 'Credentials and message content are transmitted in plaintext even though a secure option is available.',
				recommended_action: 'Enable TLS encryption in the SMTP provider settings.',
				raw: $probe
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: 'Connected to the configured SMTP server without encryption, as configured.',
			evidence: $evidence,
			raw: $probe
		);
	}

	/**
	 * Builds the result when encryption is 'tls' or 'ssl'.
	 *
	 * @param string $host       The configured SMTP host, used for certificate hostname evidence.
	 * @param string $encryption The configured encryption mode ('tls' | 'ssl').
	 * @param array  $probe      The completed probe data.
	 */
	private function result_for_encrypted_connection( string $host, string $encryption, array $probe ): DiagnosticResult {
		$evidence = $this->capability_evidence( $probe );

		if ( 'tls' === $encryption && ! (bool) ( $probe['starttls_offered'] ?? false ) ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: 'The server does not advertise STARTTLS support, but TLS encryption is configured.',
				evidence: $evidence,
				impact: 'The connection cannot be secured as configured; mail transport may fail or silently fall back to plaintext.',
				recommended_action: 'Confirm the server supports STARTTLS on this port, or switch to the correct encryption mode.',
				raw: $probe
			);
		}

		if ( ! (bool) ( $probe['tls_negotiated'] ?? false ) ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: 'Unable to establish the requested secure connection.',
				evidence: $evidence,
				impact: 'Mail transport requires a secure connection that could not be negotiated with this server.',
				recommended_action: 'Verify the server\'s TLS configuration and the encryption mode selected here.',
				raw: $probe
			);
		}

		$cert = $probe['cert'] ?? null;

		if ( ! is_array( $cert ) ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: 'A secure connection was established, but certificate details could not be verified.',
				evidence: $evidence,
				raw: $probe
			);
		}

		return $this->result_for_certificate( $host, $evidence, $probe, $cert );
	}

	/**
	 * Builds the result from parsed certificate evidence.
	 *
	 * @param string $host     The configured SMTP host.
	 * @param string $evidence The already-built capability evidence text.
	 * @param array  $probe    The completed probe data.
	 * @param array  $cert     Parsed certificate evidence.
	 */
	private function result_for_certificate( string $host, string $evidence, array $probe, array $cert ): DiagnosticResult {
		$cert_evidence = $evidence . sprintf(
			"\nCertificate subject: %s\nCertificate issuer: %s\nExpires in: %d day(s)",
			(string) ( $cert['subject_cn'] ?? 'unknown' ),
			(string) ( $cert['issuer_cn'] ?? 'unknown' ),
			(int) ( $cert['expires_in_days'] ?? 0 )
		);

		if ( true === ( $cert['expired'] ?? false ) ) {
			return new DiagnosticResult(
				status: 'fail',
				severity: 'high',
				message: sprintf( 'The TLS certificate presented by "%s" has expired.', $host ),
				evidence: $cert_evidence,
				impact: 'Mail clients and servers may refuse this connection until the certificate is renewed.',
				recommended_action: 'Renew the TLS certificate on the SMTP server.',
				raw: $probe
			);
		}

		if ( false === ( $cert['hostname_match'] ?? true ) ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'The TLS certificate does not match the configured host "%s".', $host ),
				evidence: $cert_evidence,
				impact: 'A hostname mismatch can cause strict mail clients or servers to reject the connection.',
				recommended_action: 'Confirm the certificate covers the configured SMTP host, or update the host to match the certificate.',
				raw: $probe
			);
		}

		$expires_in_days = (int) ( $cert['expires_in_days'] ?? PHP_INT_MAX );

		if ( $expires_in_days <= self::CERT_EXPIRY_WARNING_DAYS ) {
			return new DiagnosticResult(
				status: 'warn',
				severity: 'medium',
				message: sprintf( 'The TLS certificate for "%s" expires soon.', $host ),
				evidence: $cert_evidence,
				impact: 'Mail transport will begin failing once this certificate expires.',
				recommended_action: 'Renew the TLS certificate before it expires.',
				raw: $probe
			);
		}

		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: sprintf( 'A valid, matching TLS certificate was found for "%s".', $host ),
			evidence: $cert_evidence,
			raw: $probe
		);
	}

	/**
	 * Builds safe, non-secret evidence text from probe capability flags.
	 *
	 * @param array $probe The completed probe data.
	 */
	private function capability_evidence( array $probe ): string {
		$lines = array(
			sprintf( 'STARTTLS offered: %s', ( $probe['starttls_offered'] ?? false ) ? 'yes' : 'no' ),
			sprintf( 'AUTH offered: %s', ( $probe['auth_offered'] ?? false ) ? 'yes' : 'no' ),
		);

		if ( ! empty( $probe['protocol'] ) ) {
			$lines[] = sprintf( 'Protocol: %s', (string) $probe['protocol'] );
		}

		if ( ! empty( $probe['cipher'] ) ) {
			$lines[] = sprintf( 'Cipher: %s', (string) $probe['cipher'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Returns true when the given hostname/IP string is malformed.
	 *
	 * Mirrors SmtpProvider::is_host_malformed(): rejects control characters,
	 * embedded whitespace and URL scheme prefixes; does not block private or
	 * internal IP ranges, since private SMTP relays are legitimate.
	 *
	 * @param string $host Hostname or IP string to inspect.
	 */
	private static function is_valid_host( string $host ): bool {
		if ( '' === $host ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $host ) ) {
			return false;
		}
		if ( preg_match( '/\s/', $host ) ) {
			return false;
		}
		foreach ( self::FORBIDDEN_HOST_SCHEMES as $scheme ) {
			if ( 0 === stripos( $host, $scheme ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Default production probe: connects to the SMTP host, reads its EHLO
	 * capabilities and, when encryption is requested, negotiates TLS and
	 * captures certificate evidence. Never authenticates.
	 *
	 * @param string $host       The SMTP host to probe.
	 * @param int    $port       The SMTP port to probe.
	 * @param string $encryption 'tls' | 'ssl' | 'none'.
	 */
	private static function default_probe( string $host, int $port, string $encryption ): array|false {
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true,
				),
			)
		);

		$transport = 'ssl' === $encryption ? 'ssl://' . $host : $host;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- errno/errstr below are the only signal needed; the warning duplicates that information.
		$socket = @stream_socket_client(
			$transport . ':' . $port,
			$errno,
			$errstr,
			5,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( false === $socket ) {
			return array(
				'reachable' => false,
				'error'     => self::classify_connect_error( $errno, $errstr ),
			);
		}

		stream_set_timeout( $socket, 5 );

		$result = array(
			'reachable'        => true,
			'error'            => null,
			'starttls_offered' => false,
			'auth_offered'     => false,
			'tls_negotiated'   => 'ssl' === $encryption,
			'protocol'         => null,
			'cipher'           => null,
			'cert'             => null,
		);

		self::read_line( $socket ); // Greeting banner; not evidence-bearing.
		$capabilities               = self::ehlo( $socket );
		$result['starttls_offered'] = in_array( 'STARTTLS', $capabilities, true );
		$result['auth_offered']     = self::has_prefix( $capabilities, 'AUTH' );

		if ( 'tls' === $encryption && $result['starttls_offered'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to a raw TCP/TLS socket stream, not a filesystem file; WP_Filesystem does not apply.
			fwrite( $socket, "STARTTLS\r\n" );
			self::read_line( $socket );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- stream_socket_enable_crypto() emits an E_WARNING on handshake failure; the boolean return value below is the only signal needed.
			$enabled                  = @stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT );
			$result['tls_negotiated'] = true === $enabled;

			if ( $result['tls_negotiated'] ) {
				self::ehlo( $socket ); // Re-issue EHLO inside the TLS session.
			}
		}

		if ( $result['tls_negotiated'] ) {
			$result['cert'] = self::read_certificate( $socket, $host );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing a raw TCP/TLS socket stream, not a filesystem file; WP_Filesystem does not apply.
		fclose( $socket );

		return $result;
	}

	/**
	 * Classifies a stream_socket_client() failure into a stable category.
	 *
	 * @param int    $errno  The connection errno.
	 * @param string $errstr The connection error string.
	 */
	private static function classify_connect_error( int $errno, string $errstr ): string {
		$message = strtolower( $errstr );

		if ( str_contains( $message, 'timed out' ) || 110 === $errno ) {
			return 'timeout';
		}
		if ( str_contains( $message, 'refused' ) || 111 === $errno ) {
			return 'refused';
		}
		if ( str_contains( $message, 'resolve' ) || str_contains( $message, 'lookup' ) ) {
			return 'dns';
		}
		return 'other';
	}

	/**
	 * Sends EHLO and returns the list of capability keywords advertised.
	 *
	 * @param resource $socket The connected socket.
	 * @return string[]
	 */
	private static function ehlo( $socket ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to a raw TCP/TLS socket stream, not a filesystem file; WP_Filesystem does not apply.
		fwrite( $socket, "EHLO scalyn-diagnostics\r\n" );

		$capabilities = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$line = self::read_line( $socket );
			if ( '' === $line ) {
				break;
			}
			if ( preg_match( '/^\d{3}[ -](.+)$/', $line, $matches ) ) {
				$capabilities[] = strtoupper( trim( (string) strtok( $matches[1], ' ' ) ) );
			}
			if ( preg_match( '/^\d{3} /', $line ) ) {
				break; // Final line of the multi-line response.
			}
		}
		return $capabilities;
	}

	/**
	 * Returns whether any capability in the list starts with the given prefix.
	 *
	 * @param string[] $capabilities Capability keywords.
	 * @param string   $prefix       Prefix to match.
	 */
	private static function has_prefix( array $capabilities, string $prefix ): bool {
		foreach ( $capabilities as $capability ) {
			if ( 0 === strpos( $capability, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a single line from the socket, bounded by its configured timeout.
	 *
	 * @param resource $socket The connected socket.
	 */
	private static function read_line( $socket ): string {
		$line = fgets( $socket, 512 );
		return false === $line ? '' : trim( $line );
	}

	/**
	 * Reads and parses the peer certificate captured during TLS negotiation.
	 * Returns only non-secret evidence: subject/issuer common names, expiry
	 * status and hostname match — never the raw certificate contents.
	 *
	 * @param resource $socket The socket with TLS already enabled.
	 * @param string   $host   The configured SMTP host, for hostname matching.
	 */
	private static function read_certificate( $socket, string $host ): ?array {
		$params = stream_context_get_params( $socket );
		$cert   = $params['options']['ssl']['peer_certificate'] ?? null;

		if ( null === $cert ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- openssl_x509_parse() returns false on a malformed certificate; that is the only signal needed.
		$parsed = @openssl_x509_parse( $cert );
		if ( false === $parsed ) {
			return null;
		}

		$now       = time();
		$valid_to  = (int) ( $parsed['validTo_time_t'] ?? 0 );
		$subject   = (string) ( $parsed['subject']['CN'] ?? '' );
		$issuer    = (string) ( $parsed['issuer']['CN'] ?? '' );
		$alt_names = (string) ( $parsed['extensions']['subjectAltName'] ?? '' );

		return array(
			'subject_cn'      => $subject,
			'issuer_cn'       => $issuer,
			'expired'         => $valid_to < $now,
			'expires_in_days' => max( 0, (int) floor( ( $valid_to - $now ) / DAY_IN_SECONDS ) ),
			'hostname_match'  => self::certificate_matches_host( $host, $subject, $alt_names ),
		);
	}

	/**
	 * Returns whether the certificate's subject CN or SAN list matches the
	 * configured host, allowing a single leading wildcard label.
	 *
	 * @param string $host      The configured SMTP host.
	 * @param string $subject   The certificate subject common name.
	 * @param string $alt_names The raw subjectAltName extension text.
	 */
	private static function certificate_matches_host( string $host, string $subject, string $alt_names ): bool {
		$candidates = array( $subject );
		foreach ( explode( ',', $alt_names ) as $entry ) {
			if ( 1 === preg_match( '/DNS:\s*([^,]+)/i', trim( $entry ), $matches ) ) {
				$candidates[] = trim( $matches[1] );
			}
		}

		$host = strtolower( $host );
		foreach ( $candidates as $candidate ) {
			$candidate = strtolower( trim( $candidate ) );
			if ( '' === $candidate ) {
				continue;
			}
			if ( $candidate === $host ) {
				return true;
			}
			if ( str_starts_with( $candidate, '*.' ) && str_ends_with( $host, substr( $candidate, 1 ) ) ) {
				return true;
			}
		}
		return false;
	}
}
