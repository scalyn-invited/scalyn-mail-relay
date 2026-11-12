<?php
/**
 * Diagnostic check execution context value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only context supplied to DiagnosticCheckInterface::run().
 *
 * @security The $settings array passed here must NEVER include SMTP credentials,
 * API keys, OAuth tokens, authorization headers, or any other secrets.
 * Before constructing a DiagnosticContext, the caller must strip all credential
 * fields from the settings array and supply only the non-sensitive values
 * that the check actually needs (e.g. host, port, encryption type, domain).
 *
 * This restriction also applies to any data derived from $settings that is
 * later stored in DiagnosticResult::$raw or DiagnosticResult::$evidence.
 *
 * Production callers must not construct this class from raw settings. Use
 * DiagnosticContextBuilder, which copies only an explicit allowlist of
 * non-sensitive SMTP keys (host, port, encryption) and derives the sending
 * domain from the configured From address.
 */
final class DiagnosticContext {

	/**
	 * Creates a new diagnostic execution context.
	 *
	 * @param string               $domain   The sending domain being diagnosed.
	 * @param array<string, mixed> $settings Non-sensitive configuration values only.
	 *                                       Must not contain credentials or secrets.
	 */
	public function __construct(
		public readonly string $domain,
		public readonly array $settings = array()
	) {}
}
