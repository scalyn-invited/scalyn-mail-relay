<?php
/** Privacy-minimal submission evidence, separate from delivery recipient matching.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\SubmissionSafety;
use Scalyn\MailRelay\Mail\TransportFailureCategory;

defined( 'ABSPATH' ) || exit;

/** Unique logical/number constraints prevent the same attempt being claimed twice. */
class SubmissionAttemptRepository {

	/** Strict UUID validation shared by recovery identifiers.
	 *
	 * @param mixed $value Candidate identifier.
	 */
	public static function uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
	}

	/**
	 * Durably establishes Prepared before submission may start. Not Accepted.
	 *
	 * @param string      $logical Logical message UUID.
	 * @param string      $attempt Unique transport attempt UUID.
	 * @param int         $number Bounded submission number, including initial attempt.
	 * @param string      $provider Registered provider ID.
	 * @param string|null $revision Exact evidence revision, or unknown for legacy sends.
	 * @param string      $origin Registered source/type only.
	 * @throws \RuntimeException For duplicate, invalid or failed evidence writes.
	 */
	public function begin( string $logical, string $attempt, int $number, string $provider, ?string $revision, string $origin ): void {
		global $wpdb;
		if ( ! self::uuid( $logical ) || ! self::uuid( $attempt ) || $number < 1 || $number > 3 || ( null !== $revision && ! self::uuid( $revision ) ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,99}$/D', $provider ) || ! isset( \Scalyn\MailRelay\Mail\MailOrigin::types()[ $origin ] ) ) {
			throw new \RuntimeException( 'Invalid submission attribution.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned append-only attempt identity.
		$saved = $wpdb->insert(
			$wpdb->prefix . 'scalyn_submission_attempts',
			array(
				'attempt_uuid'     => $attempt,
				'logical_uuid'     => $logical,
				'attempt_number'   => $number,
				'configuration_id' => $revision,
				'provider'         => $provider,
				'origin'           => $origin,
				'status'           => 'Prepared',
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( 1 !== $saved ) {
			throw new \RuntimeException( 'Submission evidence could not be saved.' );
		}
	}

	/**
	 * Finalizes only Prepared evidence, never rewrites an already observed outcome.
	 *
	 * @param string     $attempt Attempt UUID.
	 * @param SendResult $result Normalized adapter evidence, no raw response persisted.
	 * @throws \RuntimeException For stale or failed evidence writes.
	 */
	public function finish( string $attempt, SendResult $result ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-set final evidence.
		$saved = $wpdb->update(
			$wpdb->prefix . 'scalyn_submission_attempts',
			array(
				'status'           => $result->success ? 'Accepted' : ( $result->acceptance_unconfirmed ? 'Prepared' : 'Failed' ),
				'failure_category' => $result->success ? null : TransportFailureCategory::retained( $result->failure_category ),
				'safe_to_retry'    => SubmissionSafety::retryable( $result ) ? 1 : 0,
				'finished_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'attempt_uuid' => $attempt,
				'status'       => 'Prepared',
				'finished_at'  => null,
			)
		);
		if ( 1 !== $saved ) {
			throw new \RuntimeException( 'Submission outcome could not be saved.' );
		}
	}

	/**
	 * Bounded content-free logical history, including incomplete evidence as such.
	 *
	 * @param string $logical Logical message UUID.
	 * @return array At most three attempts.
	 */
	public function history( string $logical ): array {
		global $wpdb;
		if ( ! self::uuid( $logical ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded read through the correlation index.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT attempt_uuid, logical_uuid, configuration_id, provider, origin, attempt_number, status, failure_category, safe_to_retry, created_at, finished_at FROM %i WHERE logical_uuid=%s ORDER BY attempt_number LIMIT 3', $wpdb->prefix . 'scalyn_submission_attempts', $logical ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Prunes bounded metadata under the existing log-retention policy.
	 *
	 * @param string $cutoff UTC SQL timestamp.
	 */
	public function prune( string $cutoff ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded repository retention.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 500', $wpdb->prefix . 'scalyn_submission_attempts', $cutoff ) );
	}
}
