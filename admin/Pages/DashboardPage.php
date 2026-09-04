<?php
/**
 * Dashboard admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Admin\HealthScorePresenter;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Logging\MailLogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares dashboard data and renders the dashboard view.
 *
 * Data boundary: reads from Core services (SettingsRepository, ProviderRegistry)
 * and the shared MailLogRepository. Must not query database tables directly,
 * construct transports, access Saturn/Yaj internals, or calculate health scores.
 *
 * Health score, activity stats, and diagnostic results remain as explicit
 * empty state until Yaj's logging and diagnostics REST contracts are available.
 *
 * Ownership: Kim / Admin.
 */
final class DashboardPage {

	/**
	 * Performs a capability check and renders the dashboard view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view Scalyn Mail Relay.', 'scalyn-mail-relay' ) );
		}

		$container           = Plugin::instance()->container();
		$settings            = $container->get( SettingsRepository::class );
		$provider_configured = $this->is_provider_configured();
		$provider_verified   = $provider_configured && $settings->is_provider_verified();

		$log_repo   = $container->get( MailLogRepository::class );
		$rows       = $log_repo->find_recent( 1, 0 );
		$latest_log = $rows[0] ?? null;

		$timeline_url = '';
		if ( null !== $latest_log ) {
			$uuid = (string) ( $latest_log['message_uuid'] ?? '' );
			if ( null !== $this->validate_uuid( $uuid ) ) {
				$timeline_url = add_query_arg(
					array( 'message_uuid' => $uuid ),
					admin_url( 'admin.php?page=scalyn-mail-relay-logs' )
				);
			}
		}

		// Health score: read the last HealthScorer snapshot — the same source and
		// presentation the Diagnostics page uses — so both screens agree.
		$health            = HealthScorePresenter::present( $container->get( HealthScoreRepository::class )->find_latest() );
		$health_score      = $health['score'];
		$health_ui_status  = $health['ui_status'];
		$health_ui_label   = $health['label'];
		$health_components = $health['components'];
		$health_summary    = $health['summary'];

		// Wire the "Run Diagnostics" button to the REST endpoint.
		$diagnostics_run_url = rest_url( 'scalyn-mail-relay/v1/diagnostics/run' );

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/dashboard.php';
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
	 * Validates a UUID string against the standard 8-4-4-4-12 hexadecimal format.
	 *
	 * Returns the UUID unchanged if valid, or null if the format does not match.
	 * Case-insensitive to accommodate both upper- and lower-case hex.
	 *
	 * Note: The same validation pattern exists in LogsPage::validate_uuid().
	 * A future consolidation may extract a shared UUID helper.
	 *
	 * @param string $uuid The UUID string to validate.
	 * @return string|null The UUID if valid; null if the format is invalid.
	 */
	private function validate_uuid( string $uuid ): ?string {
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			return $uuid;
		}
		return null;
	}
}
