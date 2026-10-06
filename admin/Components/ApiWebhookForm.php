<?php
/**
 * Optional provider webhook lifecycle controls.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\ApiWebhookSettings;
use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Never reflects credentials or changes the provider account. */
final class ApiWebhookForm {
	/**
	 * Fixed user feedback.
	 *
	 * @var string
	 */
	private string $notice = '';
	/**
	 * Prevent duplicate handling.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * Uses the approved lifecycle service.
	 *
	 * @param ApiWebhookSettings $settings Provider-bound source.
	 */
	public function __construct( private ApiWebhookSettings $settings ) {}

	/** Capability and provider-specific nonce guard every mutation. */
	public function handle(): void {
		if ( $this->handled ) {
			return; }
		$this->handled = true;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Detection only; nonce and types checked below.
		if ( ! isset( $_POST['scalyn_api_webhook'] ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return; }
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			wp_die( esc_html__( 'Webhook configuration is not authorized.', 'scalyn-mail-relay' ) ); }
		check_admin_referer( 'scalyn_' . $this->settings->provider() . '_webhook' );
		$input = array();
		foreach ( array( '_wpnonce', 'wh_op', 'wh_action', 'wh_ips', 'wh_password', 'wh_acknowledge', 'wh_confirm_remove' ) as $field ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict service validation; never rewrite secrets.
			$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
		}
		try {
			$ok = match ( $input['wh_op'] ) {
				'enable' => $this->settings->enable( '1' === $input['wh_acknowledge'], $input['_wpnonce'] ),
				'disable' => $this->settings->disable( $input['_wpnonce'] ),
				'remove' => $this->settings->remove( '1' === $input['wh_confirm_remove'], $input['_wpnonce'] ),
				'save' => $this->settings->save(
					array(
						'credential_action' => $input['wh_action'],
						'allowed_ips'       => preg_split( '/\\r\\n|\\r|\\n/', trim( $input['wh_ips'] ) ),
						'password'          => $input['wh_password'],
					),
					$input['_wpnonce']
				),
				default => false,
			};
			$this->notice = $ok ? __( 'Webhook action saved. Review the collection state below. No provider-account settings were changed and no mail was sent.', 'scalyn-mail-relay' ) : __( 'Webhook action was not saved.', 'scalyn-mail-relay' );
		} catch ( \Throwable $error ) {
			$this->notice = __( 'No webhook changes saved. Check the token (32–128 letters, digits, hyphens or underscores), exact IP allowlist, encryption, HTTPS and confirmation. Disable before editing. If sending configuration changed, remove the disabled source and create a new one.', 'scalyn-mail-relay' );
		}
		unset( $input );
	}

	/** Displays only the safe public projection. */
	public function render(): void {
		$this->handle();
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			return; }
		$config = $this->settings->public_settings();
		$notice = $this->notice;
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/api-webhook.php';
	}
}
