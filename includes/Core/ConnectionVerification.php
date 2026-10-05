<?php
/**
 * Scoped verification and credential-free connection evidence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Database\ConnectionEvidenceRepository;
use Scalyn\MailRelay\Providers\ConnectionResult;

defined( 'ABSPATH' ) || exit;

/** Keeps actual connection checks separate from legacy send-success timestamps. */
final class ConnectionVerification {
	/**
	 * Creates the application service.
	 *
	 * @param SettingsRepository           $settings Configuration snapshot.
	 * @param ProviderRegistry             $providers Registered transports.
	 * @param ConnectionEvidenceRepository $evidence Evidence repository.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ProviderRegistry $providers,
		private readonly ConnectionEvidenceRepository $evidence
	) {}

	/** Runs only after the controller's capability and nonce checks; sends no mail. */
	public function verify(): ConnectionResult {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return new ConnectionResult( false, __( 'You do not have permission to run a connection test.', 'scalyn-mail-relay' ) );
		}
		$provider = $this->settings->get_active_provider_id();
		$revision = $this->settings->get_diagnostic_revision();
		$started  = self::now();
		$status   = 'unknown';
		try {
			$result = $this->providers->get( $provider )->test_connection( $this->settings->get_provider_config( $provider ) );
			$status = $result->success ? 'passed' : 'failed';
		} catch ( \Throwable $error ) {
			$result = new ConnectionResult( false, __( 'The connection test could not complete. Check your provider configuration.', 'scalyn-mail-relay' ) );
		}
		try {
			// Deliberately excludes result messages, metadata and transport config.
			$this->evidence->record( $revision, $provider, $status, $started, self::now() );
		} catch ( \Throwable $error ) {
			// Evidence storage cannot change the observed transport outcome.
			unset( $error );
		}
		return $result;
	}

	/**
	 * Returns current-scope freshness without claiming provider health or delivery.
	 *
	 * @param \DateTimeImmutable|null $now Optional evaluation time for deterministic checks.
	 * @return array Status and UTC timestamps only. Unknown/stale are not failures.
	 */
	public function current( ?\DateTimeImmutable $now = null ): array {
		$unknown = array(
			'status'     => 'unknown',
			'checked_at' => null,
		);
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return $unknown;
		}
		try {
			$row = $this->evidence->find( $this->settings->get_diagnostic_revision(), $this->settings->get_active_provider_id() );
		} catch ( \Throwable $error ) {
			return $unknown;
		}
		if ( null === $row ) {
			return $unknown;
		}
		$now   ??= new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$checked = new \DateTimeImmutable( $row['checked_at'], new \DateTimeZone( 'UTC' ) );
		if ( $checked > $now || $checked < $now->modify( '-' . $this->settings->get_log_retention_days() . ' days' ) ) {
			return $unknown;
		}
		return array(
			'status'     => $checked < $now->modify( '-7 days' ) ? 'stale' : $row['status'],
			'checked_at' => $row['checked_at'],
		);
	}

	/** Canonical UTC microsecond timestamp; independent of site timezone. */
	private static function now(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' );
	}
}
