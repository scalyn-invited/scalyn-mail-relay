<?php
/**
 * Diagnostics admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;

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
		$diagnostic_repo = Plugin::instance()->container()->get( DiagnosticRepository::class );
		$run_data        = $diagnostic_repo->find_latest_run();
		$diagnostics     = $this->organize_diagnostics( $run_data['results'] );
		$health_score    = $run_data['health_score'];

		// Pre-calculate UI status values for each diagnostic check.
		$spf_ui_status   = $diagnostics['spf'] ? $this->get_ui_status( $diagnostics['spf']['status'] ) : 'unknown';
		$dkim_ui_status  = $diagnostics['dkim'] ? $this->get_ui_status( $diagnostics['dkim']['status'] ) : 'unknown';
		$dmarc_ui_status = $diagnostics['dmarc'] ? $this->get_ui_status( $diagnostics['dmarc']['status'] ) : 'unknown';

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
	 * @param array<int, array<string, mixed>> $raw_results Rows from DiagnosticRepository.
	 * @return array<string, array<string, mixed>|null> Organized by check type.
	 */
	private function organize_diagnostics( array $raw_results ): array {
		$organized = array(
			'spf'   => null,
			'dkim'  => null,
			'dmarc' => null,
		);

		foreach ( $raw_results as $result ) {
			$check_name = $result['check_name'] ?? '';

			if ( 'spf_record' === $check_name ) {
				$organized['spf'] = $result;
			} elseif ( 'dkim_record' === $check_name ) {
				$organized['dkim'] = $result;
			} elseif ( 'dmarc_policy' === $check_name ) {
				$organized['dmarc'] = $result;
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
}
