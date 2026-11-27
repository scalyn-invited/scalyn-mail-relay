<?php
/**
 * Providers admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Admin\Components\GmailSettingsForm;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Health\ProviderHealthAssessment;

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
		$smtp_settings      = $settings->get_smtp_settings();
		$sendgrid_settings  = $settings->get_sendgrid_settings();
		$postmark_settings  = $settings->get_postmark_settings();
		$smtp2go_settings   = $settings->get_smtp2go_settings();
		$brevo_settings     = $settings->get_brevo_settings();
		$gmail_settings     = $settings->get_gmail_settings();

		// Consume the per-user Gmail OAuth result transient (from OAuth callback).
		$gmail_oauth_result = get_transient( 'scalyn_gmail_oauth_result_' . get_current_user_id() );
		if ( false !== $gmail_oauth_result ) {
			delete_transient( 'scalyn_gmail_oauth_result_' . get_current_user_id() );
		}
		$can_configure        = current_user_can( Capabilities::MANAGE_SETTINGS );
		$gmail_form           = null;
		$show_gmail_configure = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Recommended -- GET read for routing only; form uses its own nonce.
		if ( $can_configure && isset( $_GET['configure'] ) && 'gmail' === sanitize_key( wp_unslash( (string) $_GET['configure'] ) ) ) {
			$gmail_form           = new GmailSettingsForm( $settings, $container->get( CredentialCipher::class ) );
			$show_gmail_configure = true;
			$gmail_form->handle(); // Handle early: may redirect for OAuth initiation before HTML output.
		}
		$assessment = null;
		if ( $can_configure ) {
			try {
				$assessment = $container->get( ProviderHealthAssessment::class )->current();
			} catch ( \Throwable $error ) {
				$assessment = null;
			}
		}
		$evidence_status = 'off';
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
				'smtp2go' => $smtp2go_settings['has_key'] && '' !== $smtp2go_settings['from_email'],
				'brevo' => $brevo_settings['has_key'] && '' !== $brevo_settings['from_email'],
				'gmail' => $gmail_settings['connected'] && '' !== $gmail_settings['from_email'],
				default => false,
			};
			$providers[] = array(
				'id'         => $id,
				'label'      => $provider->get_label(),
				'is_active'  => $id === $active_provider_id,
				'configured' => $configured,
				'health'     => $id === $active_provider_id ? $assessment : null,
				'evidence'   => match ( $id ) {
					'postmark' => $evidence_status,
					'smtp2go', 'brevo' => $container->get( \Scalyn\MailRelay\Core\ApiWebhookSettings::class . ':' . $id )->collection_status(),
					default => 'unsupported',
				},
				'transport'  => match ( $id ) {
					'smtp' => 'SMTP',
					'sendgrid', 'postmark', 'smtp2go', 'brevo', 'gmail' => 'API (HTTPS)',
					default => __( 'Not reported', 'scalyn-mail-relay' ),
				},
			);
		}
		unset( $smtp_settings );
		$active_label = $registry->has( $active_provider_id ) ? $registry->get( $active_provider_id )->get_label() : __( 'No registered provider selected', 'scalyn-mail-relay' );

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/providers.php';
	}
}
