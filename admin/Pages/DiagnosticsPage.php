<?php
/**
 * Diagnostics admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Admin\HealthScorePresenter;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Mail\FailureClassifier;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares diagnostics page data and renders the diagnostics view.
 *
 * Data boundary: reads from Core services (SettingsRepository, ProviderRegistry)
 * and DiagnosticRepository only. Must not query database tables directly,
 * construct transports, or access Yaj/Saturn internals.
 *
 * Retrieves diagnostic results and pre-calculated health score from Y1's
 * DiagnosticRepository, organizes by check type (SPF/DKIM/DMARC), and maps
 * to UI status vocabulary. Shows empty state when no results exist yet.
 *
 * Ownership: Bernie / Admin.
 */
final class DiagnosticsPage {

	/**
	 * Performs a capability check and renders the diagnostics view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			wp_die( esc_html__( 'You do not have permission to run diagnostics.', 'scalyn-mail-relay' ) );
		}

		$provider_configured = $this->is_provider_configured();
		$wizard_url          = admin_url( 'admin.php?page=scalyn-mail-relay-wizard' );

		// Wire the "Run Diagnostics" button to the REST endpoint.
		$diagnostics_run_url = rest_url( 'scalyn-mail-relay/v1/diagnostics/run' );

		// Fetch and organize diagnostic results from Y1's repository.
		$container       = Plugin::instance()->container();
		$diagnostic_repo = $container->get( DiagnosticRepository::class );
		$score_repo      = $container->get( HealthScoreRepository::class );
		$mail_log_repo   = $container->get( MailLogRepository::class );
		$classifier      = $container->get( FailureClassifier::class );
		$run_data        = $diagnostic_repo->find_latest_run();
		$diagnostics     = $this->organize_diagnostics( $run_data['results'] );

		// Read the last HealthScorer snapshot from HealthScoreRepository and present it
		// exactly as the Dashboard does (same source, same thresholds, same breakdown).
		$health            = HealthScorePresenter::present( $score_repo->find_latest() );
		$health_score      = $health['score'];
		$health_ui_status  = $health['ui_status'];
		$health_ui_label   = $health['label'];
		$health_components = $health['components'];
		$health_summary    = $health['summary'];

		// Fetch and classify recent mail failures.
		$recent_failures = $this->get_recent_failures( $mail_log_repo, $classifier );

		// Pre-calculate UI status and severity values for each diagnostic check.
		$spf_ui_status = $diagnostics['spf'] ? $this->get_ui_status( $diagnostics['spf']['status'] ) : 'unknown';
		$spf_severity  = $diagnostics['spf'] ? $this->get_severity_class( $diagnostics['spf']['severity'] ?? '' ) : '';

		$mx_ui_status = $diagnostics['mx'] ? $this->get_ui_status( $diagnostics['mx']['status'] ) : 'unknown';
		$mx_severity  = $diagnostics['mx'] ? $this->get_severity_class( $diagnostics['mx']['severity'] ?? '' ) : '';

		$dkim_ui_status = $diagnostics['dkim'] ? $this->get_ui_status( $diagnostics['dkim']['status'] ) : 'unknown';
		$dkim_severity  = $diagnostics['dkim'] ? $this->get_severity_class( $diagnostics['dkim']['severity'] ?? '' ) : '';

		$dmarc_ui_status = $diagnostics['dmarc'] ? $this->get_ui_status( $diagnostics['dmarc']['status'] ) : 'unknown';
		$dmarc_severity  = $diagnostics['dmarc'] ? $this->get_severity_class( $diagnostics['dmarc']['severity'] ?? '' ) : '';

		$smtp_tls_ui_status = $diagnostics['smtp_tls'] ? $this->get_ui_status( $diagnostics['smtp_tls']['status'] ) : 'unknown';
		$smtp_tls_severity  = $diagnostics['smtp_tls'] ? $this->get_severity_class( $diagnostics['smtp_tls']['severity'] ?? '' ) : '';

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/diagnostics.php';
	}

	/**
	 * Returns whether a provider is actively configured and registered in the plugin.
	 *
	 * Reads the active provider ID from SettingsRepository and checks presence
	 * in ProviderRegistry. Returns false when no provider ID is stored or when
	 * the stored ID has not been registered by a provider module.
	 */
	private function is_provider_configured(): bool {
		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		$registry  = $container->get( ProviderRegistry::class );
		$id        = $settings->get_active_provider_id();

		return '' !== $id && $registry->has( $id );
	}

	/**
	 * Organizes raw diagnostic results by check type for easier UI consumption.
	 *
	 * Maps check_name (e.g., 'spf_record') to display keys (e.g., 'spf').
	 * Returns an associative array keyed by diagnostic type, or null for missing checks.
	 *
	 * Supported checks:
	 * - 'spf_record' → 'spf'
	 * - 'mx_record' → 'mx'
	 * - 'dkim_record' → 'dkim'
	 * - 'dmarc_policy' → 'dmarc'
	 * - 'smtp_tls' → 'smtp_tls'
	 *
	 * @param array<int, array<string, mixed>> $raw_results Rows from DiagnosticRepository.
	 * @return array<string, array<string, mixed>|null> Organized by check type.
	 */
	private function organize_diagnostics( array $raw_results ): array {
		$organized = array(
			'spf'      => null,
			'mx'       => null,
			'dkim'     => null,
			'dmarc'    => null,
			'smtp_tls' => null,
		);

		foreach ( $raw_results as $result ) {
			$check_name = $result['check_name'] ?? '';

			if ( 'spf_record' === $check_name ) {
				$organized['spf'] = $result;
			} elseif ( 'mx_record' === $check_name ) {
				$organized['mx'] = $result;
			} elseif ( 'dkim_record' === $check_name ) {
				$organized['dkim'] = $result;
			} elseif ( 'dmarc_policy' === $check_name ) {
				$organized['dmarc'] = $result;
			} elseif ( 'smtp_tls' === $check_name ) {
				$organized['smtp_tls'] = $result;
			}
		}

		return $organized;
	}

	/**
	 * Maps a diagnostic check status to the UI status vocabulary.
	 *
	 * @param string $check_status Status from DiagnosticResult: 'pass', 'warn', 'fail', 'error'.
	 * @return string UI status: 'healthy', 'warning', 'critical', 'unknown'.
	 */
	private function get_ui_status( string $check_status ): string {
		return match ( $check_status ) {
			'pass'  => 'healthy',
			'warn'  => 'warning',
			'fail'  => 'critical',
			'error' => 'warning',
			default => 'unknown',
		};
	}

	/**
	 * Maps a diagnostic check severity to a CSS class name.
	 *
	 * @param string $severity Severity from DiagnosticResult: 'low', 'medium', 'high', 'critical'.
	 * @return string CSS class name for styling (e.g., 'scalyn-severity-high').
	 */
	private function get_severity_class( string $severity ): string {
		return match ( $severity ) {
			'low'      => 'scalyn-severity-low',
			'medium'   => 'scalyn-severity-medium',
			'high'     => 'scalyn-severity-high',
			'critical' => 'scalyn-severity-critical',
			default    => 'scalyn-severity-unknown',
		};
	}

	/**
	 * Fetches and classifies recent mail failures.
	 *
	 * Returns up to 5 most recent failed sends with their classified failure categories
	 * and remediation suggestions.
	 *
	 * @param MailLogRepository $repo       The mail log repository.
	 * @param FailureClassifier $classifier The failure classifier.
	 * @return array<int, array{
	 *   'provider': string,
	 *   'status': string,
	 *   'response_code': string|null,
	 *   'response_message': string|null,
	 *   'failed_at': string|null,
	 *   'category': string,
	 *   'remediation': string,
	 *   'evidence': string|null
	 * }> Array of recent failures with classification, or empty if none.
	 */
	private function get_recent_failures( MailLogRepository $repo, FailureClassifier $classifier ): array {
		// Fetch recent logs using the repository's public interface.
		// find_recent( limit, offset ) returns the most recent $limit rows.
		try {
			$recent_logs = $repo->find_recent( 5, 0 );
		} catch ( \Exception $e ) {
			// If there's an error fetching logs, return empty array gracefully.
			return array();
		}

		if ( empty( $recent_logs ) ) {
			return array();
		}

		$failures = array();

		foreach ( $recent_logs as $log ) {
			// Only include failed sends.
			if ( 'failed' !== $log['status'] ) {
				continue;
			}

			// Reconstruct a SendResult-like object to pass to the classifier.
			$send_result = new \Scalyn\MailRelay\Mail\SendResult(
				success: false,
				provider: $log['provider'] ?? '',
				response_code: $log['response_code'] ?? null,
				response_message: $log['response_message'] ?? null,
				failure_category: null
			);

			$suggestion = $classifier->classify( $send_result );

			// Format the timestamp using site's date/time settings.
			$failed_at_raw       = $log['failed_at'] ?? null;
			$failed_at_formatted = null;
			if ( $failed_at_raw ) {
				$timestamp = strtotime( $failed_at_raw );
				if ( false !== $timestamp ) {
					$failed_at_formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
				}
			}

			$failures[] = array(
				'provider'            => $log['provider'] ?? 'unknown',
				'status'              => $log['status'] ?? 'unknown',
				'response_code'       => $log['response_code'] ?? null,
				'response_message'    => $log['response_message'] ?? null,
				'failed_at'           => $failed_at_raw,
				'failed_at_formatted' => $failed_at_formatted,
				'category'            => $suggestion->category,
				'remediation'         => $suggestion->suggestion,
				'evidence'            => $suggestion->evidence,
			);
		}

		return $failures;
	}
}
