<?php
/** Shared current-configuration Deliverability Score service.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliverabilityRepository;
use Scalyn\MailRelay\Health\ProviderDeliveryEvidence;

defined( 'ABSPATH' ) || exit;

/** Explicit assessment writes; reads never silently manufacture scores. */
final class DeliverabilityAssessment {

	/** Builds the service from approved read models and repositories.
	 *
	 * @param SettingsRepository       $settings Current configuration.
	 * @param CurrentDiagnostics       $diagnostics Current revision findings.
	 * @param DeliverabilityRepository $repository Minimal evidence storage.
	 * @param ProviderDeliveryEvidence $delivery Authenticated source aggregates.
	 */
	public function __construct( private SettingsRepository $settings, private CurrentDiagnostics $diagnostics, private DeliverabilityRepository $repository, private ProviderDeliveryEvidence $delivery ) {}

	/** Computes and persists an assessment, including missing-evidence outcomes.
	 *
	 * @throws \RuntimeException When unauthorized or configuration changes.
	 */
	public function assess(): array {
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			throw new \RuntimeException( 'Assessment is not authorized.' );
		}
		$revision  = $this->settings->get_diagnostic_revision();
		$provider  = $this->settings->get_active_provider_id();
		$scope     = $this->diagnostics->snapshot();
		$now       = time();
		$days      = min( 30, $this->settings->get_log_retention_days() );
		$checks    = array();
		$alignment = 'not_assessed';
		foreach ( $scope['results'] as $row ) {
			$name = $row['check_name'] ?? '';
			if ( ! in_array( $name, array( 'spf_record', 'dkim_record', 'dmarc_policy', 'mx_record', 'reverse_dns' ), true ) ) {
				continue;
			}
			$date            = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) ( $row['created_at'] ?? '' ), wp_timezone() );
			$checks[ $name ] = array(
				'status'     => $row['status'] ?? 'unknown',
				'checked_at' => false !== $date && $date->format( 'Y-m-d H:i:s' ) === $row['created_at'] ? $date->getTimestamp() : 0,
			);
			if ( 'dmarc_policy' === $name ) {
				$raw       = json_decode( (string) ( $row['raw_result'] ?? '' ), true );
				$alignment = $raw['raw']['alignment']['dkim'] ?? 'not_assessed';
			}
		}
		$input = array(
			'evaluated_at' => $now,
			'fresh_for'    => $this->fresh_for(),
			'days'         => $days,
			'transport'    => 'smtp' === $provider ? 'smtp' : 'api',
			'checks'       => $checks,
			'alignment'    => $alignment,
			'submissions'  => $this->repository->submissions( $revision, $provider, $days, $now ),
			'delivery'     => $this->delivery->current( $days, $now, true ),
		);
		// Re-read durable settings after evidence queries; never publish as current
		// a configuration that was superseded while assessment was running.
		$fresh_settings = new SettingsRepository();
		if ( $fresh_settings->get_diagnostic_revision() !== $revision || $fresh_settings->get_active_provider_id() !== $provider ) {
			throw new \RuntimeException( 'Configuration changed. Assess the current configuration again.' );
		}
		return $this->repository->persist_score( $revision, $provider, (string) ( $scope['results'][0]['diagnostic_uuid'] ?? '' ), $input );
	}

	/** Returns a replay-verified saved assessment or an explicit empty state. */
	public function current(): ?array {
		if ( ! current_user_can( Capabilities::VIEW_DASHBOARD ) && ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			return null;
		}
		try {
			$saved = $this->repository->latest( $this->settings->get_diagnostic_revision(), $this->settings->get_active_provider_id() );
			if ( null === $saved ) {
				return null;
			}
			$scope           = $this->diagnostics->snapshot();
			$input           = $saved['inputs'];
			$delivery        = $this->delivery->current( min( 30, $this->settings->get_log_retention_days() ), null, true );
			$current_run     = (string) ( $scope['results'][0]['diagnostic_uuid'] ?? '' );
			$days            = min( 30, $this->settings->get_log_retention_days() );
			$fresh_for       = $this->fresh_for();
			$delivery_status = $delivery['status'] ?? '';
			$source_id       = $delivery['source_id'] ?? '';
			$changed         = (string) $saved['diagnostic_uuid'] !== $current_run || $input['days'] !== $days || $input['fresh_for'] !== $fresh_for || $input['delivery']['status'] !== $delivery_status || $input['delivery']['source_id'] !== $source_id;
			foreach ( $input['checks'] as $check ) {
				if ( in_array( $check['status'], array( 'pass', 'warn', 'fail' ), true ) && ( $check['checked_at'] > time() || time() - $check['checked_at'] > $this->fresh_for() ) ) {
					$changed = true;
				}
			}
			if ( $changed ) {
				$saved['score']         = null;
				$saved['missing'][]     = 'Evidence or collection settings changed or became stale. Refresh the assessment.';
				$saved['needs_refresh'] = true;
			}
			return $saved;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** Stores informational verdicts only; never contributes to scores.
	 *
	 * @param array $analysis Parsed, in-memory analysis.
	 * @throws \RuntimeException When unauthorized or storage is unavailable.
	 */
	public function record_header( array $analysis ): void {
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			throw new \RuntimeException( 'Header evidence is not authorized.' );
		}
		$this->repository->persist_header( $this->settings->get_diagnostic_revision(), $this->settings->get_active_provider_id(), $analysis );
	}

	/** DNS TTL is at most seven days or twice the scheduled cadence. */
	private function fresh_for(): int {
		$seconds = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
			'weekly'     => 604800,
		);
		return min( 604800, 2 * ( $seconds[ $this->settings->get_diagnostic_schedule() ] ?? 604800 ) );
	}
}
