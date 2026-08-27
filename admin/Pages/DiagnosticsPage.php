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

defined( 'ABSPATH' ) || exit;

/**
 * Prepares diagnostics page data and renders the diagnostics view.
 *
 * Data boundary: reads from Core services (SettingsRepository, ProviderRegistry)
 * only. Must not query database tables directly, construct transports, access
 * Yaj/Saturn internals, or calculate health scores.
 *
 * Diagnostic results, findings, and health scoring remain empty state until
 * Yaj's diagnostics runner and REST contracts are available.
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

		// Diagnostics runner endpoint URL will be set here once Yaj's REST endpoint is available.
		// For now, keep empty to disable the "Run Diagnostics" button.
		$diagnostics_run_url = '';

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
}
