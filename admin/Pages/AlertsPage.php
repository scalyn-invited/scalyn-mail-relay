<?php
/**
 * Alert controls and history.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Alerts\AlertRepository;
use Scalyn\MailRelay\Alerts\WebhookChannel;

defined( 'ABSPATH' ) || exit;

/** Protected settings and repository-backed history. */
final class AlertsPage {

	/**
	 * Supplies approved services.
	 *
	 * @param AlertRepository $repository Safe history read model.
	 * @param WebhookChannel  $channel Configuration readiness only.
	 */
	public function __construct( private AlertRepository $repository, private WebhookChannel $channel ) {}

	/** Capability and nonce checked controls, bounded cursor history. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage alerts.', 'scalyn-mail-relay' ) );
		}
		$settings = new SettingsRepository();
		$notice   = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server-controlled request method.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'scalyn_alert_settings' );
			$enabled = isset( $_POST['alert_webhook_enabled'] ) && '1' === $_POST['alert_webhook_enabled'];
			if ( $enabled && ! $this->channel->configured() ) {
				$notice = __( 'Configure a valid HTTPS webhook in server configuration before enabling notifications.', 'scalyn-mail-relay' );
			} else {
				$settings->save( array( 'advanced' => array( 'alert_webhook_enabled' => $enabled ) ) );
				$settings = new SettingsRepository();
				$notice   = $settings->get_alert_webhook_enabled() === $enabled ? __( 'Alert notification preference saved.', 'scalyn-mail-relay' ) : __( 'The setting could not be saved. Try again.', 'scalyn-mail-relay' );
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cursor; no mutation.
		$before  = isset( $_GET['before'] ) && is_string( $_GET['before'] ) ? absint( $_GET['before'] ) : 0;
		$history = array(
			'rows' => array(),
			'next' => 0,
		);
		try {
			$history = $this->repository->page( $before );
		} catch ( \Throwable $error ) {
			$notice = __( 'Alert history is unavailable. Check the database upgrade and database availability.', 'scalyn-mail-relay' );
		}
		$enabled    = $settings->get_alert_webhook_enabled();
		$configured = $this->channel->configured();
		$next       = wp_next_scheduled( ScheduledHooks::ALERTS );
		$execution  = $this->repository->status();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/alerts.php';
	}
}
