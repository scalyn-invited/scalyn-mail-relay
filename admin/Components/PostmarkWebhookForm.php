<?php
/**
 * Optional disabled webhook draft inside Postmark setup.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;

defined( 'ABSPATH' ) || exit;

/** Never enables a receiver or reflects submitted credentials. */
final class PostmarkWebhookForm {
	/** Fixed save feedback.
	 *
	 * @var string
	 */
	private string $notice = '';

	/** Whether the mutation succeeded.
	 *
	 * @var bool
	 */
	private bool $saved = false;

	/** Prevent duplicate processing within a request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * Uses the approved settings service, never raw options or transport.
	 *
	 * @param PostmarkWebhookSettings $settings Draft service.
	 */
	public function __construct( private PostmarkWebhookSettings $settings ) {}

	/** Handles only this form's POST after capability and nonce validation. */
	public function handle(): void {
		if ( $this->handled ) {
			return;
		}
		$this->handled = true;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Form detection and exact server method comparison only; nonce checked below.
		if ( ! isset( $_POST['scalyn_postmark_webhook_draft'] ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			wp_die( esc_html__( 'You do not have permission to manage webhook configuration.', 'scalyn-mail-relay' ) );
		}
		check_admin_referer( 'scalyn_postmark_webhook' );
		$input = array();
		foreach ( array( '_wpnonce', 'wh_action', 'wh_server_id', 'wh_stream', 'wh_ips', 'wh_username', 'wh_password', 'wh_confirm_remove' ) as $field ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict types and service validation; credentials must not be rewritten.
			$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
		}
		try {
			if ( 'remove' === $input['wh_action'] ) {
				$this->saved  = $this->settings->remove( '1' === $input['wh_confirm_remove'], $input['_wpnonce'] );
				$this->notice = $this->saved ? __( 'Webhook draft removed. This does not remove or revoke anything in your Postmark account.', 'scalyn-mail-relay' ) : __( 'Webhook draft could not be removed. Try again.', 'scalyn-mail-relay' );
			} else {
				$server       = filter_var( $input['wh_server_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				$this->saved  = $this->settings->save(
					array(
						'server_id'         => false === $server ? null : $server,
						'stream'            => $input['wh_stream'],
						'allowed_ips'       => preg_split( '/\r\n|\r|\n/', trim( $input['wh_ips'] ) ),
						'credential_action' => $input['wh_action'],
						'username'          => $input['wh_username'],
						'password'          => $input['wh_password'],
					),
					$input['_wpnonce']
				);
				$this->notice = $this->saved ? __( 'Webhook draft saved. Delivery tracking is still disabled and the source is not verified. Sending settings are unchanged.', 'scalyn-mail-relay' ) : __( 'Webhook draft could not be saved. Try again.', 'scalyn-mail-relay' );
			}
		} catch ( \Throwable $error ) {
			$this->notice = __( 'No draft changes saved. Check the server ID, stream, exact IP addresses, credential action and removal confirmation. Encryption must be available. Remove the disabled draft before changing server or stream.', 'scalyn-mail-relay' );
		}
		unset( $input );
	}

	/** Renders public settings and empty credential inputs only. */
	public function render(): void {
		$this->handle();
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			return;
		}
		$config = $this->settings->public_settings();
		$notice = $this->notice;
		$saved  = $this->saved;
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/postmark-webhook.php';
	}
}
