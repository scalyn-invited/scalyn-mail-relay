<?php
/**
 * Atomic diagnostic run publication.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Diagnostics\HealthScorer;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;
use Scalyn\MailRelay\Logging\MailLogRepository;

defined( 'ABSPATH' ) || exit;

/** Publishes bounded results and their derived snapshot in one transaction. */
final class DiagnosticPublicationRepository {

	/** Creates a repository using existing persistence contracts.
	 *
	 * @param DiagnosticRepository  $diagnostics Check rows.
	 * @param HealthScoreRepository $health Snapshot rows.
	 * @param HealthScorer          $scorer Deterministic scoring.
	 * @param MailLogRepository     $mail Operational score inputs.
	 */
	public function __construct(
		private DiagnosticRepository $diagnostics,
		private HealthScoreRepository $health,
		private HealthScorer $scorer,
		private MailLogRepository $mail
	) {}

	/** Publishes a complete run; errors reveal no SQL or provider payloads.
	 *
	 * @param string $uuid Fresh diagnostic run UUID.
	 * @param array  $checks Bounded normalized runner output.
	 * @return array Published results and score.
	 * @throws \RuntimeException When validation, reading or publication fails.
	 */
	public function publish( string $uuid, array $checks ): array {
		global $wpdb;
		$started = false;
		try {
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $uuid ) || count( $checks ) < 1 || count( $checks ) > 20 ) {
				throw new \RuntimeException();
			}
			$ids = array();
			foreach ( $checks as $check ) {
				if ( ! is_array( $check ) || ! ( ( $check['result'] ?? null ) instanceof DiagnosticResult ) || ! is_string( $check['id'] ?? null ) || ! is_string( $check['category'] ?? null ) || isset( $ids[ $check['id'] ] ) ) {
					throw new \RuntimeException();
				}
				$ids[ $check['id'] ] = true;
			}
			// Refuse nontransactional installations instead of promising false atomicity.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned engine verification.
			$engines = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s) AND ENGINE = 'InnoDB'", $wpdb->prefix . 'scalyn_diagnostics', $wpdb->prefix . 'scalyn_health_scores' ) );
			if ( '2' !== (string) $engines ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository-owned atomic publication.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException();
			}
			$started = true;
			$created = current_time( 'mysql' );
			foreach ( $checks as $check ) {
				$this->diagnostics->persist_result( $uuid, $check['category'], $check['id'], $check['result'], $created );
			}
			$rows = $this->diagnostics->find_by_uuid( $uuid );
			if ( ! empty( $wpdb->last_error ) || count( $rows ) !== count( $checks ) ) {
				throw new \RuntimeException();
			}
			$counts = $this->mail->count_recent_by_status();
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			$score = $this->scorer->score( $rows, $counts );
			if ( null !== $score ) {
				$this->health->persist( $score, $uuid, $created );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit only the complete run and optional snapshot.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException();
			}
			return array(
				'results'      => $rows,
				'health_score' => $score?->overall_score,
			);
		} catch ( \Throwable $error ) {
			if ( $started ) {
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back incomplete publication.
					$wpdb->query( 'ROLLBACK' );
				} catch ( \Throwable $rollback_error ) {
					// The public failure remains fixed even if the connection was lost.
					$started = false;
				}
			}
			throw new \RuntimeException( 'Diagnostic publication failed.' );
		}
	}
}
