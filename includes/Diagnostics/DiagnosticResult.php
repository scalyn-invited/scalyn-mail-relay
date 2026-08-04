<?php
/**
 * Diagnostic check result value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result returned by DiagnosticCheckInterface::run().
 *
 * @security The $evidence and $raw fields must NEVER contain SMTP credentials,
 * API keys, OAuth tokens, authorization headers, or any other secrets — even in
 * partial or masked form. Diagnostic checks that inspect connection or
 * authentication details must redact all sensitive values before populating
 * these fields. This applies equally to values persisted in the database
 * (scalyn_diagnostics.raw_result) and to values returned via REST endpoints.
 */
final class DiagnosticResult {

	/**
	 * Creates a new diagnostic check result.
	 *
	 * @param string   $status             Check outcome: 'pass' | 'warn' | 'fail' | 'error'.
	 * @param string   $severity           Severity level: 'low' | 'medium' | 'high' | 'critical'.
	 * @param string   $message            Human-readable summary of the result.
	 * @param string   $evidence           Supporting evidence (DNS records, headers, etc.).
	 *                                     Must not contain credentials or secrets.
	 * @param string   $impact             Description of the impact if not resolved.
	 * @param string   $recommended_action Steps the administrator should take.
	 * @param int|null $score              Optional 0-100 contribution to the health score.
	 * @param array    $raw                Raw provider/DNS data for advanced diagnostics.
	 *                                     Must not contain credentials or secrets.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $severity,
		public readonly string $message,
		public readonly string $evidence = '',
		public readonly string $impact = '',
		public readonly string $recommended_action = '',
		public readonly ?int $score = null,
		public readonly array $raw = array()
	) {}
}
