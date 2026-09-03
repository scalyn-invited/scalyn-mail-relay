<?php
/**
 * Deterministic failure classifier for mail transport failures.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Maps send failures to TransportFailureCategory with remediation guidance.
 *
 * Classification is deterministic and evidence-based: each category assignment
 * is grounded in response codes, error messages, or explicit failure_category
 * from the provider. When evidence is insufficient, UNKNOWN is returned rather
 * than guessing.
 *
 * Never exposes credentials, SMTP transcripts, provider metadata, or raw error
 * output in remediation text.
 *
 * Ownership: Kim / Mail.
 */
final class FailureClassifier {

	/**
	 * Classifies a send failure and returns remediation guidance.
	 *
	 * @param SendResult $result The normalized send result from a provider.
	 * @return RemediationSuggestion The failure category and remediation text.
	 */
	public function classify( SendResult $result ): RemediationSuggestion {
		// If the provider has already classified it, respect that classification.
		if ( null !== $result->failure_category ) {
			return $this->remediation_for_category( $result->failure_category, $result );
		}

		// Analyze response codes first.
		if ( null !== $result->response_code ) {
			$category = $this->classify_by_response_code( $result->response_code );
			if ( TransportFailureCategory::UNKNOWN !== $category ) {
				return $this->remediation_for_category( $category, $result );
			}
		}

		// Fall back to message-based classification if code returned unknown.
		if ( null !== $result->response_message ) {
			$category = $this->classify_by_message( $result->response_message );
			if ( TransportFailureCategory::UNKNOWN !== $category ) {
				return $this->remediation_for_category( $category, $result );
			}
		}

		// No evidence; classify as unknown.
		return $this->remediation_for_category( TransportFailureCategory::UNKNOWN, $result );
	}

	/**
	 * Classifies a failure by SMTP response code.
	 *
	 * SMTP defines response codes as:
	 * - 4xx: Transient failure; retry may succeed.
	 * - 5xx: Permanent failure; retry will not succeed.
	 *
	 * Within these ranges, specific codes indicate the problem domain:
	 * - 4xx/5xx 0: Syntax error.
	 * - 4xx/5xx 1: Information.
	 * - 4xx/5xx 2: Connection status.
	 * - 4xx/5xx 3: Authentication, accrual, or mailbox status.
	 * - 4xx/5xx 4: Unspecified transient failure.
	 * - 4xx/5xx 5: Mail system status, network issues.
	 *
	 * @param string $code SMTP response code (e.g., "535", "421").
	 * @return string One of TransportFailureCategory constants.
	 */
	private function classify_by_response_code( string $code ): string {
		// Normalize to numeric code.
		$numeric_code = (int) $code;

		if ( $numeric_code < 100 || $numeric_code >= 600 ) {
			return TransportFailureCategory::UNKNOWN;
		}

		// 421: Service not available; timeout or server overload.
		if ( 421 === $numeric_code ) {
			return TransportFailureCategory::TIMEOUT;
		}

		// 450, 451, 452: Transient failure; often timeout or server busy.
		if ( in_array( $numeric_code, array( 450, 451, 452 ), true ) ) {
			return TransportFailureCategory::TIMEOUT;
		}

		// 500: Syntax error.
		if ( 500 === $numeric_code ) {
			return TransportFailureCategory::UNKNOWN;
		}

		// 501: Syntax error in parameters.
		if ( 501 === $numeric_code ) {
			return TransportFailureCategory::UNKNOWN;
		}

		// 502, 503, 504: Service unavailable or command unrecognized.
		if ( in_array( $numeric_code, array( 502, 503, 504 ), true ) ) {
			return TransportFailureCategory::CONNECTIVITY;
		}

		// 535, 539: Authentication failed.
		if ( in_array( $numeric_code, array( 535, 539 ), true ) ) {
			return TransportFailureCategory::AUTH;
		}

		// 550, 551, 552, 553, 554: User/message rejection or relay denied.
		if ( in_array( $numeric_code, array( 550, 551, 552, 553, 554 ), true ) ) {
			return TransportFailureCategory::PROVIDER_REJECTION;
		}

		// Default: unknown (caller will try message-based classification if available).
		return TransportFailureCategory::UNKNOWN;
	}

	/**
	 * Classifies a failure by analyzing the response message text.
	 *
	 * Used as a fallback when response code alone is insufficient or unavailable.
	 *
	 * @param string $message The response message from the provider.
	 * @return string One of TransportFailureCategory constants.
	 */
	private function classify_by_message( string $message ): string {
		$msg_lower = strtolower( $message );

		// TLS/SSL errors.
		if ( strpos( $msg_lower, 'tls' ) !== false || strpos( $msg_lower, 'ssl' ) !== false ) {
			// Distinguish between certificate and negotiation issues.
			if ( strpos( $msg_lower, 'certificate' ) !== false || strpos( $msg_lower, 'cert' ) !== false ) {
				return TransportFailureCategory::CERTIFICATE;
			}
			return TransportFailureCategory::TLS;
		}

		// Certificate errors.
		if ( strpos( $msg_lower, 'certificate' ) !== false || strpos( $msg_lower, 'cert' ) !== false ) {
			return TransportFailureCategory::CERTIFICATE;
		}

		// Authentication errors.
		if ( strpos( $msg_lower, 'auth' ) !== false || strpos( $msg_lower, 'credential' ) !== false ) {
			return TransportFailureCategory::AUTH;
		}

		// Connection/timeout errors.
		if ( strpos( $msg_lower, 'timeout' ) !== false || strpos( $msg_lower, 'timed out' ) !== false ) {
			return TransportFailureCategory::TIMEOUT;
		}

		if ( strpos( $msg_lower, 'refused' ) !== false || strpos( $msg_lower, 'unreachable' ) !== false ) {
			return TransportFailureCategory::CONNECTIVITY;
		}

		// Unknown: no keywords matched.
		return TransportFailureCategory::UNKNOWN;
	}

	/**
	 * Returns remediation text for a classified failure category.
	 *
	 * @param string     $category The failure category from TransportFailureCategory.
	 * @param SendResult $result   The send result for optional evidence context.
	 * @return RemediationSuggestion Remediation guidance.
	 */
	private function remediation_for_category( string $category, SendResult $result ): RemediationSuggestion {
		$evidence = $this->format_evidence( $result );

		return match ( $category ) {
			TransportFailureCategory::AUTH => new RemediationSuggestion(
				TransportFailureCategory::AUTH,
				__( 'SMTP authentication failed. Verify the credentials configured in the Setup Wizard match the mail provider account.', 'scalyn-mail-relay' ),
				$evidence
			),
			TransportFailureCategory::CONNECTIVITY => new RemediationSuggestion(
				TransportFailureCategory::CONNECTIVITY,
				__( 'Could not reach the mail server. Verify the SMTP host and port are correct, and that your firewall allows outbound connections on that port.', 'scalyn-mail-relay' ),
				$evidence
			),
			TransportFailureCategory::TIMEOUT => new RemediationSuggestion(
				TransportFailureCategory::TIMEOUT,
				__( 'Connection attempt timed out. The mail server may be slow or overloaded; try again in a moment, or contact your mail provider.', 'scalyn-mail-relay' ),
				$evidence
			),
			TransportFailureCategory::TLS => new RemediationSuggestion(
				TransportFailureCategory::TLS,
				__( 'TLS encryption negotiation failed. Verify the server supports TLS/STARTTLS on the configured port, and that your PHP installation has OpenSSL enabled.', 'scalyn-mail-relay' ),
				$evidence
			),
			TransportFailureCategory::CERTIFICATE => new RemediationSuggestion(
				TransportFailureCategory::CERTIFICATE,
				__( 'The mail server\'s TLS certificate could not be verified. The certificate may be expired, self-signed, or mismatched to the hostname. Contact your mail provider.', 'scalyn-mail-relay' ),
				$evidence
			),
			TransportFailureCategory::PROVIDER_REJECTION => new RemediationSuggestion(
				TransportFailureCategory::PROVIDER_REJECTION,
				__( 'The mail server rejected the message. This may indicate a misconfiguration, an invalid recipient, or the server\'s relay policy. Check the mail provider\'s logs or contact support.', 'scalyn-mail-relay' ),
				$evidence
			),
			TransportFailureCategory::CONFIG => new RemediationSuggestion(
				TransportFailureCategory::CONFIG,
				__( 'Configuration error before sending. Verify the SMTP setup in the Setup Wizard and run diagnostics to check for other issues.', 'scalyn-mail-relay' ),
				$evidence
			),
			default => new RemediationSuggestion(
				TransportFailureCategory::UNKNOWN,
				__( 'An error occurred, but the specific cause could not be determined. Check the mail provider logs or contact support for details.', 'scalyn-mail-relay' ),
				$evidence
			),
		};
	}

	/**
	 * Formats safe evidence from a send result for display.
	 *
	 * Never includes credentials, full SMTP transcript, raw message bodies, or secrets.
	 *
	 * @param SendResult $result The send result.
	 * @return string|null Safe evidence summary, or null if none available.
	 */
	private function format_evidence( SendResult $result ): ?string {
		$parts = array();

		if ( null !== $result->response_code ) {
			$parts[] = sprintf( 'Code: %s', sanitize_text_field( $result->response_code ) );
		}

		if ( null !== $result->response_message ) {
			// Sanitize the message but keep it for evidence.
			$safe_msg = sanitize_text_field( $result->response_message );
			// Truncate very long messages to avoid cluttering the UI.
			if ( strlen( $safe_msg ) > 100 ) {
				$safe_msg = substr( $safe_msg, 0, 97 ) . '...';
			}
			$parts[] = sprintf( 'Message: %s', $safe_msg );
		}

		return ! empty( $parts ) ? implode( ' | ', $parts ) : null;
	}
}
