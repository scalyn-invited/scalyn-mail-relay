<?php
/**
 * Bounded credential-free diagnostic execution status.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Audit\AuditActor;

defined( 'ABSPATH' ) || exit;

/** All mutations require ownership of the shared diagnostic run lock. */
final class DiagnosticRunStateRepository {

	public const OPTION_KEY = 'scalyn_mail_relay_diagnostic_run_status';
	private const SLOTS     = array( 'latest', 'scheduled', 'last_success', 'last_scheduled_success', 'previous_unfinished' );
	private const FAILURES  = array( '', 'context_failed', 'checks_failed', 'publication_failed' );

	/** Reads only normalized status, never arbitrary stored data.
	 *
	 * @return array Fixed bounded slots; missing or malformed records are null.
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$safe   = array();
		foreach ( self::SLOTS as $slot ) {
			$safe[ $slot ] = $this->record( is_array( $stored ) ? ( $stored[ $slot ] ?? null ) : null );
			if ( null !== $safe[ $slot ] && (
				( in_array( $slot, array( 'last_success', 'last_scheduled_success' ), true ) && 'completed' !== $safe[ $slot ]['state'] )
				|| ( in_array( $slot, array( 'scheduled', 'last_scheduled_success' ), true ) && 'scheduled' !== $safe[ $slot ]['source'] )
				|| ( 'previous_unfinished' === $slot && 'running' !== $safe[ $slot ]['state'] )
			) ) {
				$safe[ $slot ] = null;
			}
		}
		return $safe;
	}

	/** Records intent before executing any checks.
	 *
	 * @param string $uuid Fresh run UUID.
	 * @param string $source Trusted execution context.
	 * @return bool True only when the start marker is saved.
	 */
	public function begin( string $uuid, string $source ): bool {
		$state  = $this->get();
		$record = $this->record(
			array(
				'uuid'         => $uuid,
				'source'       => $source,
				'state'        => 'running',
				'started_at'   => time(),
				'finished_at'  => 0,
				'failure_code' => '',
			)
		);
		if ( null === $record ) {
			return false;
		}
		if ( 'running' === ( $state['latest']['state'] ?? '' ) ) {
			$state['previous_unfinished'] = $state['latest'];
		}
		$state['latest'] = $record;
		if ( 'scheduled' === $source ) {
			$state['scheduled'] = $record;
		}
		return $this->save( $state );
	}

	/** Records a terminal outcome only for the active UUID.
	 *
	 * @param string $uuid Owning run UUID.
	 * @param string $failure Fixed failure code; empty means committed completion.
	 * @return bool Whether the terminal state was persisted.
	 */
	public function finish( string $uuid, string $failure = '' ): bool {
		$state  = $this->get();
		$record = $state['latest'];
		if ( null === $record || $record['uuid'] !== $uuid || 'running' !== $record['state'] || ! in_array( $failure, self::FAILURES, true ) ) {
			return false;
		}
		$record['state']        = '' === $failure ? 'completed' : 'failed';
		$record['finished_at']  = max( $record['started_at'], time() );
		$record['failure_code'] = $failure;
		$state['latest']        = $record;
		if ( 'scheduled' === $record['source'] ) {
			$state['scheduled'] = $record;
		}
		if ( '' === $failure ) {
			$state['last_success'] = $record;
			if ( 'scheduled' === $record['source'] ) {
				$state['last_scheduled_success'] = $record;
			}
		}
		return $this->save( $state );
	}

	/** Saves a fixed-size option outside the diagnostic publication transaction.
	 *
	 * @param array $state Normalized internal slots.
	 * @return bool Whether the write succeeded or already matches.
	 */
	private function save( array $state ): bool {
		try {
			return update_option( self::OPTION_KEY, $state, false ) || get_option( self::OPTION_KEY ) === $state;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Projects one stored row through strict privacy and consistency rules.
	 *
	 * @param mixed $row Untrusted stored record.
	 * @return array|null Safe record.
	 */
	private function record( mixed $row ): ?array {
		if ( ! is_array( $row ) || ! is_string( $row['uuid'] ?? null ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $row['uuid'] )
			|| ! in_array( $row['source'] ?? null, AuditActor::SOURCES, true )
			|| ! in_array( $row['state'] ?? null, array( 'running', 'completed', 'failed' ), true )
			|| ! is_int( $row['started_at'] ?? null ) || $row['started_at'] <= 0
			|| ! is_int( $row['finished_at'] ?? null ) || $row['finished_at'] < 0
			|| ! in_array( $row['failure_code'] ?? null, self::FAILURES, true ) ) {
			return null;
		}
		if ( ( 'running' === $row['state'] && ( 0 !== $row['finished_at'] || '' !== $row['failure_code'] ) )
			|| ( 'running' !== $row['state'] && $row['finished_at'] < $row['started_at'] )
			|| ( 'completed' === $row['state'] && '' !== $row['failure_code'] )
			|| ( 'failed' === $row['state'] && '' === $row['failure_code'] ) ) {
			return null;
		}
		return array_intersect_key( $row, array_flip( array( 'uuid', 'source', 'state', 'started_at', 'finished_at', 'failure_code' ) ) );
	}
}
