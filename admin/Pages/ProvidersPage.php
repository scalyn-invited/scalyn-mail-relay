<?php
/**
 * Providers admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
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
		$smtp_settings      = $settings->get_smtp_config();
		$sendgrid_settings  = $settings->get_sendgrid_settings();
		$postmark_settings  = $settings->get_postmark_settings();
		$can_configure      = current_user_can( Capabilities::MANAGE_SETTINGS );
		$verified           = $settings->is_provider_verified();
		$verified_time      = $verified ? strtotime( $settings->get_provider_verified_at() ?? '' ) : false;
		$verified_date      = false !== $verified_time ? wp_date( 'Y-m-d H:i:s', $verified_time ) : __( 'Not recorded', 'scalyn-mail-relay' );
		$evidence_status    = 'off';
		try {
			$evidence_status = $container->get( PostmarkWebhookSettings::class )->collection_status();
		} catch ( \Throwable $error ) {
			$evidence_status = 'unavailable';
		}
		$providers = array();
		foreach ( $registry->all() as $id => $provider ) {
			$configured = match ( $id ) {
				'smtp' => '' !== ( $smtp_settings['host'] ?? '' ) && '' !== ( $smtp_settings['from_email'] ?? '' ),
				'sendgrid' => $sendgrid_settings['has_key'] && '' !== $sendgrid_settings['from_email'],
				'postmark' => $postmark_settings['has_key'] && '' !== $postmark_settings['from_email'],
				default => false,
			};
			$providers[] = array(
				'id'          => $id,
				'label'       => $provider->get_label(),
				'is_active'   => $id === $active_provider_id,
				'configured'  => $configured,
				'verified'    => $id === $active_provider_id && $verified,
				'verified_at' => $verified_date,
				'evidence'    => match ( $id ) {
					'postmark' => $evidence_status,
					default => 'unsupported',
				},
				'transport'   => match ( $id ) {
					'smtp' => 'SMTP',
					'sendgrid' => 'API (HTTPS)',
					'postmark' => 'API (HTTPS)',
					default => __( 'Not reported', 'scalyn-mail-relay' ),
				},
			);
		}
		unset( $smtp_settings );
		$active_label = $registry->has( $active_provider_id ) ? $registry->get( $active_provider_id )->get_label() : __( 'No registered provider selected', 'scalyn-mail-relay' );

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/providers.php';
	}
}
