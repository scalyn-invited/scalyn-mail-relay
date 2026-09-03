<?php
/**
 * Remediation suggestion for a classified failure.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object pairing a failure category with plain-English remediation text.
 *
 * Each suggestion is deterministic and evidence-based, grounded in the failure
 * category determined by FailureClassifier. Suggestions are never generated
 * algorithmically or inferred from incomplete data.
 *
 * Ownership: Kim / Mail.
 */
final class RemediationSuggestion {

	/**
	 * Creates a remediation suggestion.
	 *
	 * @param string $category    One of TransportFailureCategory constants (auth, connectivity, tls, etc.).
	 * @param string $suggestion  Plain-English remediation text; must not contain credentials, raw SMTP, or secrets.
	 * @param string $evidence    Optional brief evidence summary (e.g. "SMTP 535: Authentication failed").
	 */
	public function __construct(
		public readonly string $category,
		public readonly string $suggestion,
		public readonly ?string $evidence = null
	) {}
}
