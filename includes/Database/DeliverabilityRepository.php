<?php
/** Minimal score and header-verdict persistence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Diagnostics\DeliverabilityScorer;

defined( 'ABSPATH' ) || exit;

/** Owned evidence storage; raw headers, messages and recipients are never accepted. */
final class DeliverabilityRepository {

	/** Aggregates terminal submissions attributed to this provider revision.
	 *
	 * @param string $revision Current configuration UUID.
	 * @param string $provider Current provider ID.
	 * @param int    $days Retained rolling window, at most thirty days.
	 * @param int    $now Evaluation instant.
	 */
	public function submissions( string $revision, string $provider, int $days, int $now ): ?array {
		global $wpdb;
		if ( ! $this->ready() || ! $this->valid_scope( $revision, $provider ) || $days < 1 || $days > 30 ) {
			return null;
		}
		// Logs use site time; project both UTC instants to the site's timezone.
		$start = wp_date( 'Y-m-d H:i:s', $now - $days * 86400 );
		$end   = wp_date( 'Y-m-d H:i:s', $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed revision/provider aggregate; no message metadata leaves SQL.
		$rows = $this->quiet( static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS row_count FROM %i WHERE configuration_id=%s AND provider=%s AND created_at >= %s AND created_at <= %s AND status IN ('accepted','failed') GROUP BY status", $wpdb->prefix . 'scalyn_mail_logs', $revision, $provider, $start, $end ), ARRAY_A ) );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return null;
		}
		$counts = array(
			'accepted' => 0,
			'failed'   => 0,
		);
		foreach ( $rows as $row ) {
			if ( ! isset( $counts[ $row['status'] ?? '' ] ) || ! preg_match( '/^[0-9]{1,8}$/D', (string) ( $row['row_count'] ?? '' ) ) ) {
				return null;
			}
			$counts[ $row['status'] ] = (int) $row['row_count'];
		}
		return $counts;
	}

	/** Computes and stores a reproducible trace, including Not assessed outcomes.
	 *
	 * @param string $revision Configuration UUID.
	 * @param string $provider Provider ID.
	 * @param string $run Diagnostic UUID or empty.
	 * @param array  $inputs Allowlisted aggregate inputs.
	 */
	public function persist_score( string $revision, string $provider, string $run, array $inputs ): array {
		$result = ( new DeliverabilityScorer() )->evaluate( $inputs );
		return $this->insert( $revision, $provider, $run, 'score', $result );
	}

	/** Saves only fixed-enum verdicts and alignment results, never descriptive text.
	 *
	 * @param string $revision Configuration UUID.
	 * @param string $provider Provider ID.
	 * @param array  $analysis In-memory analysis projection.
	 */
	public function persist_header( string $revision, string $provider, array $analysis ): array {
		$payload = array();
		foreach ( array( 'spf', 'dkim', 'dmarc' ) as $name ) {
			$value                        = $analysis['verdicts'][ $name ] ?? '';
			$payload['verdicts'][ $name ] = in_array( $value, array( 'pass', 'warn', 'fail', 'unknown' ), true ) ? $value : 'unknown';
		}
		foreach ( array( 'spf', 'dkim' ) as $name ) {
			$value                         = $analysis['alignment'][ $name ] ?? '';
			$payload['alignment'][ $name ] = in_array( $value, array( 'strict', 'relaxed', 'possible', 'no', 'yes', 'unknown' ), true ) ? $value : 'unknown';
		}
		return $this->insert( $revision, $provider, '', 'header', $payload );
	}

	/** Finds one exact-revision score, fail closed for malformed or unreadable storage.
	 *
	 * @param string $revision Configuration UUID.
	 * @param string $provider Provider ID.
	 */
	public function latest( string $revision, string $provider ): ?array {
		global $wpdb;
		if ( ! $this->ready() || ! $this->valid_scope( $revision, $provider ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded exact-revision read.
		$row = $this->quiet( static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT computation_uuid,diagnostic_uuid,weights_version,payload,created_at FROM %i WHERE configuration_id=%s AND provider=%s AND kind='score' ORDER BY id DESC LIMIT 1", $wpdb->prefix . 'scalyn_deliverability_evidence', $revision, $provider ), ARRAY_A ) );
		if ( ! is_array( $row ) || ! empty( $wpdb->last_error ) || DeliverabilityScorer::VERSION !== ( $row['weights_version'] ?? '' ) || strlen( (string) ( $row['payload'] ?? '' ) ) > 65536 || ! $this->uuid( (string) ( $row['computation_uuid'] ?? '' ) ) ) {
			return null;
		}
		$trace = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $trace ) || ! is_array( $trace['inputs'] ?? null ) ) {
			return null;
		}
		$replayed = ( new DeliverabilityScorer() )->evaluate( $trace['inputs'] );
		if ( wp_json_encode( $replayed ) !== wp_json_encode( $trace ) ) {
			return null;
		}
		return array_merge(
			$trace,
			array(
				'computation_uuid' => $row['computation_uuid'],
				'diagnostic_uuid'  => $row['diagnostic_uuid'],
				'created_at'       => $row['created_at'],
			)
		);
	}

	/** Deletes one bounded batch using the same site-time cutoff as health history.
	 *
	 * @param string $cutoff Exclusive site-time cutoff.
	 * @return int Rows deleted; a full batch means more may remain.
	 * @throws \RuntimeException If cleanup fails.
	 */
	public function delete_expired( string $cutoff ): int {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
			throw new \RuntimeException( 'Invalid assessment retention cutoff.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned bounded retention deletion.
		$count = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s ORDER BY created_at,id LIMIT 100', $wpdb->prefix . 'scalyn_deliverability_evidence', $cutoff ) ) );
		if ( false === $count ) {
			throw new \RuntimeException( 'Assessment retention failed.' );
		}
		return (int) $count;
	}

	/** Inserts a projected trace or fails without exposing database errors.
	 *
	 * @param string $revision Configuration UUID.
	 * @param string $provider Provider ID.
	 * @param string $run Diagnostic UUID or empty.
	 * @param string $kind Evidence kind.
	 * @param array  $payload Projected payload.
	 * @throws \RuntimeException If storage is unavailable.
	 */
	private function insert( string $revision, string $provider, string $run, string $kind, array $payload ): array {
		global $wpdb;
		if ( ! $this->ready() || ! $this->valid_scope( $revision, $provider ) || ( '' !== $run && ! $this->uuid( $run ) ) ) {
			throw new \RuntimeException( 'Assessment storage is unavailable.' );
		}
		$uuid    = wp_generate_uuid4();
		$created = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Prepared insertion of minimal projected evidence.
		$saved = $this->quiet(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Prepared repository-owned trace insert.
			static fn() => $wpdb->insert(
				$wpdb->prefix . 'scalyn_deliverability_evidence',
				array(
					'computation_uuid' => $uuid,
					'configuration_id' => $revision,
					'provider'         => $provider,
					'diagnostic_uuid'  => '' === $run ? null : $run,
					'kind'             => $kind,
					'weights_version'  => DeliverabilityScorer::VERSION,
					'payload'          => wp_json_encode( $payload ),
					'created_at'       => $created,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			)
		);
		if ( 1 !== $saved ) {
			throw new \RuntimeException( 'Assessment could not be saved.' );
		}
		return array_merge(
			$payload,
			array(
				'computation_uuid' => $uuid,
				'diagnostic_uuid'  => '' === $run ? null : $run,
				'created_at'       => $created,
			)
		);
	}

	/** Prevents database diagnostics from reaching markup or exception payloads.
	 *
	 * @param \Closure $operation Owned database operation.
	 * @throws \RuntimeException If the connection fails unexpectedly.
	 */
	private function quiet( \Closure $operation ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $operation();
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Assessment storage is unavailable.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Whether the verified additive schema is installed. */
	private function ready(): bool {
		return version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.11.0', '>=' );
	}

	/** Validates public attribution.
	 *
	 * @param string $revision Configuration UUID.
	 * @param string $provider Provider identifier.
	 */
	private function valid_scope( string $revision, string $provider ): bool {
		return $this->uuid( $revision ) && (bool) preg_match( '/^[a-z0-9_-]{1,100}$/D', $provider );
	}

	/** Validates immutable UUIDs.
	 *
	 * @param string $value Candidate UUID.
	 */
	private function uuid( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value );
	}
}
