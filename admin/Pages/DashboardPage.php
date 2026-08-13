<?php
/**
 * Dashboard admin page.
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
 * Prepares dashboard data and renders the dashboard view.
 *
 * Data boundary: reads only from Core services (SettingsRepository,
 * ProviderRegistry). Must not query database tables directly, construct
 * transports, access Saturn/Yaj internals, or calculate health scores.
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

		$provider_configured = $this->is_provider_configured();

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
}
