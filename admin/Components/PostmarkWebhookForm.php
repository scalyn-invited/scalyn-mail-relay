<?php
/**
 * Optional disabled webhook draft inside Postmark setup.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;

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
	 * @param PostmarkWebhookSettings $settings Source lifecycle service.
	 * @param PostmarkProvider|null   $provider Read-only server check for verification.
	 */
	public function __construct( private PostmarkWebhookSettings $settings, private ?PostmarkProvider $provider = null ) {}

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
		foreach ( array( '_wpnonce', 'wh_op', 'wh_action', 'wh_server_id', 'wh_stream', 'wh_ips', 'wh_username', 'wh_password', 'wh_confirm_remove', 'wh_acknowledge' ) as $field ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict types and service validation; credentials must not be rewritten.
			$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
		}
		if ( in_array( $input['wh_op'], array( 'verify', 'enable', 'disable' ), true ) ) {
			$this->lifecycle( $input['wh_op'], '1' === $input['wh_acknowledge'], $input['_wpnonce'] );
			unset( $input );
			return;
		}
		try {
			if ( 'remove' === $input['wh_action'] ) {
				$this->saved  = $this->settings->remove( '1' === $input['wh_confirm_remove'], $input['_wpnonce'] );
				$this->notice = $this->saved ? __( 'Webhook source removed. Retained evidence follows normal cleanup. Nothing in your Postmark account was removed or revoked.', 'scalyn-mail-relay' ) : __( 'Webhook source could not be removed. Try again.', 'scalyn-mail-relay' );
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
				$this->notice = $this->saved ? __( 'Webhook source saved. Collection remains disabled until you enable it. Sending settings are unchanged.', 'scalyn-mail-relay' ) : __( 'Webhook source could not be saved. Try again.', 'scalyn-mail-relay' );
			}
		} catch ( \Throwable $error ) {
			$this->notice = __( 'No source changes saved. Check the server ID, stream, exact IP addresses, credential action and removal confirmation. Encryption must be available. Disable collection before editing, and remove the source before changing server or stream.', 'scalyn-mail-relay' );
		}
		unset( $input );
	}

	/**
	 * Runs one explicit source lifecycle action with fixed feedback.
	 *
	 * @param string $op verify, enable or disable.
	 * @param bool   $acknowledged Privacy notice acknowledgement for enablement.
	 * @param string $nonce Management nonce.
	 */
	private function lifecycle( string $op, bool $acknowledged, string $nonce ): void {
		try {
			if ( 'verify' === $op ) {
				$this->saved  = $this->settings->verify( $nonce, $this->provider ?? new PostmarkProvider() );
				$this->notice = $this->saved
					? __( 'Webhook source verified: the server ID matches the Live server of the configured Postmark token. No email was sent.', 'scalyn-mail-relay' )
					: __( 'Webhook source not verified. Postmark must be the active provider, the stream must be "outbound", and the server ID must match the Live server of the saved Server API token. No email was sent.', 'scalyn-mail-relay' );
			} elseif ( 'enable' === $op ) {
				$this->saved  = $this->settings->enable( $acknowledged, $nonce );
				$this->notice = __( 'Delivery and bounce evidence collection is enabled for new Postmark sends. Earlier messages are not tracked.', 'scalyn-mail-relay' );
			} else {
				$this->saved  = $this->settings->disable( $nonce );
				$this->notice = __( 'Collection disabled. New sends are not tracked and callbacks are acknowledged without storing evidence. Retained evidence remains until normal cleanup. Pause or remove the webhook in Postmark too.', 'scalyn-mail-relay' );
			}
		} catch ( \Throwable $error ) {
			$this->saved = false;
			// Map fixed, credential-free service prerequisites; never echo other exception text.
			$messages     = array(
				'Save a webhook source before verifying it.' => __( 'Save a webhook source before verifying it.', 'scalyn-mail-relay' ),
				'Save a webhook source before enabling collection.' => __( 'Save a webhook source before enabling collection.', 'scalyn-mail-relay' ),
				'Confirm the privacy notice before enabling collection.' => __( 'Confirm the privacy notice before enabling collection.', 'scalyn-mail-relay' ),
				'Verify the webhook source for the current Postmark configuration first.' => __( 'Verify the webhook source for the current Postmark configuration first.', 'scalyn-mail-relay' ),
				'Delivery evidence storage is not ready. Update the plugin database first.' => __( 'Delivery evidence storage is not ready. Update the plugin database first.', 'scalyn-mail-relay' ),
				'Recipient matching key could not be prepared. Encryption must be available.' => __( 'Recipient matching key could not be prepared. Encryption must be available.', 'scalyn-mail-relay' ),
			);
			$this->notice = $messages[ $error->getMessage() ] ?? __( 'The webhook action could not be completed. Try again.', 'scalyn-mail-relay' );
		}
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
