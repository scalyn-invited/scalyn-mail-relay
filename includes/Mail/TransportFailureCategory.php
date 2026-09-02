<?php
/**
 * Canonical transport failure category identifiers.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Stable, closed vocabulary for classifying SMTP/provider transport failures.
 *
 * Every module that reads or writes a transport failure category string must
 * reference these constants instead of bare string literals.
 *
 * An unclassifiable failure must be reported as UNKNOWN, never guessed into one
 * of the other categories — an inability to classify is not evidence of a
 * specific problem.
 */
final class TransportFailureCategory {

	/** SMTP authentication was rejected by the server. */
	public const AUTH = 'auth';

	/** The server could not be reached (refused, unreachable, DNS resolution failure). */
	public const CONNECTIVITY = 'connectivity';

	/** The connection attempt exceeded its time limit. */
	public const TIMEOUT = 'timeout';

	/** TLS/STARTTLS negotiation failed. */
	public const TLS = 'tls';

	/** The server's TLS certificate could not be verified (expired, mismatched, untrusted). */
	public const CERTIFICATE = 'certificate';

	/** The provider rejected the message itself (recipient rejected, relay denied). */
	public const PROVIDER_REJECTION = 'provider-rejection';

	/** The failure could not be classified into any of the categories above. */
	public const UNKNOWN = 'unknown';

	/** Pre-transport validation failure; not a transport failure. */
	public const CONFIG = 'config';

	/**
	 * Returns the six closed transport-failure categories (excludes UNKNOWN and CONFIG).
	 *
	 * @return string[]
	 */
	public static function transport_categories(): array {
		return array(
			self::AUTH,
			self::CONNECTIVITY,
			self::TIMEOUT,
			self::TLS,
			self::CERTIFICATE,
			self::PROVIDER_REJECTION,
		);
	}

	/**
	 * Maps SmtpTlsCheck's legacy connect-error taxonomy onto this shared vocabulary.
	 *
	 * This is for evidence/display purposes only — it does not affect SmtpTlsCheck's
	 * own diagnostic status/severity branching.
	 *
	 * @param string $legacy One of 'timeout'|'refused'|'dns'|'other'.
	 * @return string One of self::TIMEOUT, self::CONNECTIVITY, self::UNKNOWN.
	 */
	public static function from_connect_error( string $legacy ): string {
		return match ( $legacy ) {
			'timeout' => self::TIMEOUT,
			'refused', 'dns' => self::CONNECTIVITY,
			default => self::UNKNOWN,
		};
	}

	/**
	 * Private constructor: this class is a static constant holder only.
	 */
	private function __construct() {}
}
