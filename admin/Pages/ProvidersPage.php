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

		$container     = Plugin::instance()->container();
		$settings      = $container->get( SettingsRepository::class );
		$registry      = $container->get( ProviderRegistry::class );
		$sendgrid_form = new \Scalyn\MailRelay\Admin\Components\SendGridSettingsForm( $settings, $container->get( \Scalyn\MailRelay\Core\CredentialCipher::class ) );
		$sendgrid_form->handle();

		$active_provider_id = $settings->get_active_provider_id();
		$smtp_settings      = $settings->get_smtp_config();
		$sendgrid_settings  = $settings->get_sendgrid_settings();
		$providers          = array();
		foreach ( $registry->all() as $id => $provider ) {
			$configured = match ( $id ) {
				'smtp' => '' !== ( $smtp_settings['host'] ?? '' ) && '' !== ( $smtp_settings['from_email'] ?? '' ),
				'sendgrid' => $sendgrid_settings['has_key'] && '' !== $sendgrid_settings['from_email'],
				default => false,
			};
			$providers[] = array(
				'id'         => $id,
				'label'      => $provider->get_label(),
				'is_active'  => $id === $active_provider_id,
				'configured' => $configured,
			);
		}
		unset( $smtp_settings );

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/providers.php';
	}
}
