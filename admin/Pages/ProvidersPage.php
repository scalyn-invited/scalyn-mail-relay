<?php
/**
 * Providers admin page.
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
 * Lists registered mail providers with their configuration status.
 *
 * Ownership: Kim / Admin.
 */
final class ProvidersPage {

	/**
	 * Performs a capability check and renders the providers view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			wp_die( esc_html__( 'You do not have permission to manage mail providers.', 'scalyn-mail-relay' ) );
		}

		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		$registry  = $container->get( ProviderRegistry::class );

		$active_provider_id = $settings->get_active_provider_id();
		$providers          = array();
		foreach ( $registry->all() as $id => $provider ) {
			$providers[] = array(
				'id'         => $id,
				'label'      => $provider->get_label(),
				'is_active'  => $id === $active_provider_id,
				'configured' => $id === $active_provider_id && '' !== $active_provider_id,
			);
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/providers.php';
	}
}
