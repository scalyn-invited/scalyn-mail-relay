<?php
/** Conservative retry gate: classification alone is never sufficient.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/** Unknown, partial, Accepted and auth/config outcomes never permit resubmission. */
final class SubmissionSafety {

	/**
	 * Requires an adapter's explicit pre-submission or definite-rejection evidence.
	 *
	 * @param SendResult $result Actual normalized result.
	 */
	public static function retryable( SendResult $result ): bool {
		return ! $result->success && ! $result->acceptance_unconfirmed && $result->safe_to_retry && $result->retryable
			&& in_array( $result->failure_category, array( TransportFailureCategory::CONNECTIVITY, TransportFailureCategory::TIMEOUT, TransportFailureCategory::RATE_LIMIT ), true );
	}

	/**
	 * At least one/five minutes after actual outcome, respecting provider delay.
	 *
	 * @param SendResult $result Normalized result.
	 * @param int        $completed Total completed submissions.
	 */
	public static function delay( SendResult $result, int $completed ): int {
		return max( 1 === $completed ? 60 : 300, $result->retry_after_seconds );
	}
}
