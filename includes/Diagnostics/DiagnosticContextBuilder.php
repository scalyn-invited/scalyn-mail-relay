<?php
/**
 * Credential-safe DiagnosticContext builder.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the DiagnosticContext handed to every check in a diagnostic run.
 *
 * This is the single place where plugin settings are translated into a
 * context, so the credential boundary is enforced once, by allowlist: only
 * the keys named in SMTP_CONTEXT_KEYS are copied out of the SMTP
 * configuration. The username, password, and any future API keys or tokens
 * are never read here, so they can never reach a check,
 * DiagnosticResult::$raw / $evidence, or the scalyn_diagnostics table.
 *
 * Domain: DNS checks (SPF, DKIM, DMARC, MX) evaluate the domain that outgoing
 * mail is sent from, i.e. the domain part of the configured From address.
 * When no valid From address is stored, the supplied fallback (the site host)
 * is used so the checks still run against something meaningful.
 *
 * Ownership: Yaj / Diagnostics.
 */
final class DiagnosticContextBuilder {

	/**
	 * SMTP configuration keys that are safe to expose to checks.
	 *
	 * Deliberately excludes 'username' and 'password'. Add a key here only if
	 * it can never carry a secret.
	 *
	 * @var string[]
	 */
	private const SMTP_CONTEXT_KEYS = array( 'host', 'port', 'encryption' );

	/**
	 * Builds a credential-free context from the current plugin settings.
	 *
	 * @param SettingsRepository $settings        Plugin settings.
	 * @param string             $fallback_domain Domain used when no valid From address is
	 *                                            configured (typically the site host).
	 * @return DiagnosticContext Context whose $settings contain only host, port, and encryption.
	 */
	public function build( SettingsRepository $settings, string $fallback_domain ): DiagnosticContext {
		$smtp = $settings->get_smtp_config();

		$safe = array();
		foreach ( self::SMTP_CONTEXT_KEYS as $key ) {
			if ( array_key_exists( $key, $smtp ) ) {
				$safe[ $key ] = $smtp[ $key ];
			}
		}

		$safe['host']       = trim( (string) ( $safe['host'] ?? '' ) );
		$safe['port']       = absint( $safe['port'] ?? 0 );
		$safe['encryption'] = (string) ( $safe['encryption'] ?? 'tls' );

		$domain = self::domain_from_email( (string) ( $smtp['from_email'] ?? '' ) );

		return new DiagnosticContext( '' !== $domain ? $domain : $fallback_domain, $safe );
	}

	/**
	 * Extracts the lower-cased domain part of an email address.
	 *
	 * Returns '' when the value has no '@' or the part after it does not look
	 * like a hostname, so callers can fall back to another domain source.
	 *
	 * @param string $email Email address, e.g. "noreply@example.org".
	 * @return string Domain such as "example.org", or '' when none can be derived.
	 */
	public static function domain_from_email( string $email ): string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}

		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );

		// Hostname labels: letters, digits, and inner hyphens, joined by dots, with at least one dot.
		$pattern = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';
		if ( '' === $domain || 1 !== preg_match( $pattern, $domain ) ) {
			return '';
		}

		return $domain;
	}
}
