<?php
/** Shared minimal evidence projection for chat and MCP.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Diagnostics\CurrentDiagnostics;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;

defined( 'ABSPATH' ) || exit;

/** Projects fixed fields only; never forwards repository rows wholesale. */
final class AiEvidence {

	/** Creates the read-model adapter.
	 *
	 * @param SettingsRepository      $settings Revision scope.
	 * @param CurrentDiagnostics      $diagnostics Current evidence.
	 * @param MailLogRepository       $logs Aggregate activity.
	 * @param RecoveryQueueRepository $recovery Bounded recovery summaries.
	 */
	public function __construct( private SettingsRepository $settings, private CurrentDiagnostics $diagnostics, private MailLogRepository $logs, private RecoveryQueueRepository $recovery ) {}

	/** Returns a new snapshot for each question or external read. */
	public function snapshot(): array {
		AiAccess::require_admin();
		$revision = $this->settings->get_diagnostic_revision();
		$sources  = array(
			'help'          => array(
				'accepted'    => 'Provider acceptance is not confirmed delivery or inbox placement.',
				'unknown'     => 'Unknown and stale evidence must not be treated as healthy.',
				'routing'     => 'Explicit routing selects named configurations for supported mail sources. Recovery is separate and opt-in.',
				'diagnostics' => 'Checks inspect configuration and DNS, not authentication on a received message. AI cannot repair configuration.',
				'evidence'    => 'Webhook evidence may confirm recipient-server acceptance or bounce; missing callbacks are not failures.',
				'scope'       => 'Technical email setup, authentication, delivery, security and troubleshooting are supported. Email drafting is not supported.',
				'setup'       => 'Use Setup Wizard to choose a provider and enter credentials privately; authorize the sender/domain with the provider, publish its required DNS records, then verify connection. A separately requested test email is different from diagnostics. Review Email Health, Diagnostics and Email Logs for recorded evidence.',
			),
			'configuration' => array(
				'revision' => $revision,
				'provider' => $this->settings->get_active_provider_id(),
			),
			'diagnostics'   => array(
				'available' => false,
				'checks'    => array(),
			),
			'health'        => array( 'available' => false ),
			'activity'      => array( 'available' => false ),
		);
		try {
			$current = $this->diagnostics->snapshot();
			foreach ( array_slice( $current['results'], 0, 20 ) as $row ) {
				$name = $row['check_name'] ?? '';
				if ( ! in_array( $name, array( 'spf_record', 'dkim_record', 'dmarc_policy', 'mx_record', 'smtp_tls', 'reverse_dns' ), true ) ) {
					continue;
				}
				$status                             = in_array( $row['status'] ?? '', array( 'pass', 'warn', 'fail', 'unknown', 'error' ), true ) ? $row['status'] : 'unknown';
				$timestamp                          = is_string( $row['created_at'] ?? null ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $row['created_at'] ) ? $row['created_at'] : null;
				$sources['diagnostics']['checks'][] = array(
					'name'                  => $name,
					'status'                => $status,
					'recorded_at_site_time' => $timestamp,
					'freshness'             => $this->freshness( $timestamp ),
					'guidance'              => self::guidance( $name, $status ),
				);
			}
			$sources['diagnostics']['available'] = ! empty( $sources['diagnostics']['checks'] );
			$score                               = $current['health']['overall_score'] ?? null;
			$sources['health']                   = array(
				'available'           => is_numeric( $score ),
				'configuration_score' => is_numeric( $score ) ? max( 0, min( 100, (int) $score ) ) : null,
				'freshness'           => $this->freshness( $current['health']['created_at'] ?? null ),
				'limitation'          => 'Configuration score only, not delivery success or inbox placement.',
			);
		} catch ( \Throwable $error ) {
			$sources['diagnostics'] = array(
				'available' => false,
				'checks'    => array(),
			);
			$sources['health']      = array( 'available' => false );
		}
		try {
			$end                 = new \DateTimeImmutable( 'now', wp_timezone() );
			$period              = new \Scalyn\MailRelay\Reporting\ReportPeriod( $end->modify( '-7 days' )->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
			$totals              = $this->logs->activity_totals( $period )['statuses'];
			$sources['activity'] = array(
				'available'   => true,
				'window_days' => 7,
				'accepted'    => max( 0, (int) ( $totals['accepted'] ?? 0 ) ),
				'failed'      => max( 0, (int) ( $totals['failed'] ?? 0 ) ),
				'scope'       => 'Retained site-wide submissions, not current revision health.',
			);
			$states              = array();
			foreach ( $this->recovery->summaries() as $row ) {
				if ( in_array( $row['state'] ?? '', array( 'queued', 'claimed', 'submitting', 'completed', 'review', 'cancelled', 'failed' ), true ) ) {
					$states[ $row['state'] ] = ( $states[ $row['state'] ] ?? 0 ) + 1;
				}
			}
			$sources['activity']['bounded_recovery_states'] = $states;
		} catch ( \Throwable $error ) {
			$sources['activity'] = array( 'available' => false );
		}
		$latest_revision = $this->settings->get_diagnostic_revision();
		if ( ! hash_equals( $revision, $latest_revision ) ) {
			$revision                 = $latest_revision;
			$sources['configuration'] = array(
				'revision' => $revision,
				'provider' => $this->settings->get_active_provider_id(),
			);
			$sources['diagnostics']   = array(
				'available' => false,
				'checks'    => array(),
			);
			$sources['health']        = array( 'available' => false );
		}
		return array(
			'captured_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'revision'        => $revision,
			'sources'         => $sources,
		);
	}

	/** Describes age without treating stale or absent observations as health.
	 *
	 * @param string|null $timestamp Recorded site-local time.
	 */
	private function freshness( ?string $timestamp ): string {
		$date = is_string( $timestamp ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $timestamp, wp_timezone() ) : false;
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $timestamp || $date->getTimestamp() > time() ) {
			return 'unknown';
		}
		$intervals = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
		);
		$maximum   = ( $intervals[ $this->settings->get_diagnostic_schedule() ] ?? 86400 ) + 300;
		return time() - $date->getTimestamp() > $maximum ? 'stale' : 'recent_configuration_evidence_only';
	}

	/** Builds observed facts deterministically instead of trusting model assertions.
	 *
	 * @param array $snapshot Current allowlisted projection.
	 * @param array $references Relevant verified source keys.
	 */
	public static function observations( array $snapshot, array $references = array( 'configuration', 'diagnostics', 'health', 'activity' ) ): string {
		$sources = $snapshot['sources'];
		$lines   = array();
		if ( in_array( 'configuration', $references, true ) ) {
			$lines[] = 'Current provider: ' . $sources['configuration']['provider'] . '. Revision: ' . $snapshot['revision'] . '.';
		}
		if ( in_array( 'diagnostics', $references, true ) && ! $sources['diagnostics']['available'] ) {
			$lines[] = 'No current recorded diagnostic findings are available.';
		}
		foreach ( $sources['diagnostics']['checks'] as $check ) {
			if ( ! in_array( 'diagnostics', $references, true ) ) {
				continue;
			}
			$lines[] = $check['name'] . ': ' . $check['status'] . ' (' . $check['freshness'] . '; recorded site time: ' . ( $check['recorded_at_site_time'] ?? 'unknown' ) . ').';
		}
		if ( in_array( 'health', $references, true ) ) {
			$lines[] = $sources['health']['available'] ? 'Recorded configuration score: ' . $sources['health']['configuration_score'] . '/100 (' . $sources['health']['freshness'] . ').' : 'Configuration score unavailable.';
		}
		if ( in_array( 'activity', $references, true ) ) {
			$lines[] = $sources['activity']['available'] ? 'Retained site-wide submissions in seven days: Accepted ' . $sources['activity']['accepted'] . ', Failed ' . $sources['activity']['failed'] . '.' : 'Retained activity unavailable.';
		}
		$lines[] = 'Configuration checks and provider acceptance do not verify authentication on an actual message or inbox delivery.';
		return implode( "\n", $lines );
	}

	/** Fixed guides explain possible remediation without exposing raw findings.
	 *
	 * @param string $name Allowlisted check.
	 * @param string $status Recorded normalized status.
	 */
	private static function guidance( string $name, string $status ): string {
		$guides = array(
			'spf_record'   => 'Compare the single SPF TXT record with the sending provider requirements. Avoid duplicate SPF records and check lookup limits; actual outbound-IP authorization is not verified here.',
			'dkim_record'  => 'Confirm provider-side DKIM signing and the configured selector, then compare the published public key with provider instructions. DNS presence does not verify message signatures.',
			'dmarc_policy' => 'Review the DMARC TXT policy and confirm actual SPF or DKIM alignment using receiver authentication results before tightening enforcement.',
			'mx_record'    => 'Check the receiving mail host MX instructions. MX describes inbound routing and does not by itself authorize outbound sending.',
			'smtp_tls'     => 'Compare SMTP host, port and encryption mode with provider instructions. Investigate certificate or connection issues without disabling certificate verification.',
			'reverse_dns'  => 'Ask the sending-IP owner to check PTR and forward DNS consistency; this may not apply to a hosted API provider.',
		);
		return ( 'pass' === $status ? 'Recorded configuration check passed; this alone is not a reason to change settings. ' : 'Status alone does not identify the exact cause. Inspect the finding on Diagnostics; refresh unknown or stale evidence before changing settings. ' ) . ( $guides[ $name ] ?? '' );
	}
}
