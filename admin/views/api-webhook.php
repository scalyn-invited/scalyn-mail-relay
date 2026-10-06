<?php
/**
 * Optional bearer-authenticated delivery evidence controls.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<details class="scalyn-disclosure">
	<summary><?php esc_html_e( 'Advanced: delivery and bounce evidence (optional)', 'scalyn-mail-relay' ); ?></summary>
	<p><?php esc_html_e( 'Off by default. Use a public HTTPS endpoint and a dedicated random webhook token, never your sending API key. Configure Bearer authentication with the same token in your provider account. Tokens are never displayed again.', 'scalyn-mail-relay' ); ?></p>
	<?php
	if ( $notice ) :
		?>
		<p role="status"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
	<p><?php echo esc_html( $config['configured'] ? __( 'Webhook source and encrypted token saved.', 'scalyn-mail-relay' ) : __( 'No webhook source saved.', 'scalyn-mail-relay' ) ); ?></p>
	<p><?php esc_html_e( 'Collection:', 'scalyn-mail-relay' ); ?> <strong><?php echo esc_html( $config['collecting'] ? __( 'Enabled for new sends', 'scalyn-mail-relay' ) : ( $config['enabled'] ? __( 'Paused — configuration changed or storage unavailable', 'scalyn-mail-relay' ) : __( 'Disabled', 'scalyn-mail-relay' ) ) ); ?></strong></p>
	<p><?php esc_html_e( 'Saving a source is not remote verification. Check a real callback in Email Logs after setup. Recipient-server delivery does not prove inbox placement; the sending status stays unchanged.', 'scalyn-mail-relay' ); ?></p>
	<?php
	if ( $config['endpoint'] ) :
		?>
		<p><?php esc_html_e( 'Webhook URL:', 'scalyn-mail-relay' ); ?> <code><?php echo esc_html( $config['endpoint'] ); ?></code></p><?php endif; ?>
	<?php if ( 'smtp2go' === $config['provider'] ) : ?>
		<p><?php esc_html_e( 'SMTP2GO: select JSON, Delivered and Bounce; scope the webhook to the sending API key. Include the X-Scalyn-Message-UUID email header. Obtain current webhook IPs from the provider’s documented webhooks.smtp2go.com A record.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Brevo: create a transactional email webhook for delivered, hardBounce and softBounce with Bearer authentication and batching disabled. Use the provider’s published outbound webhook IP addresses.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
	<form method="post">
		<?php wp_nonce_field( 'scalyn_' . $config['provider'] . '_webhook' ); ?>
		<input type="hidden" name="scalyn_api_webhook" value="1">
		<p><label for="api-wh-action"><?php esc_html_e( 'Token action', 'scalyn-mail-relay' ); ?></label><br><select id="api-wh-action" name="wh_action"><option value="keep" <?php selected( $config['configured'], true ); ?>><?php esc_html_e( 'Keep saved token', 'scalyn-mail-relay' ); ?></option><option value="replace" <?php selected( $config['configured'], false ); ?>><?php esc_html_e( 'Add or replace token', 'scalyn-mail-relay' ); ?></option></select></p>
		<p><label for="api-wh-token"><?php esc_html_e( 'New dedicated webhook token (32–128 characters)', 'scalyn-mail-relay' ); ?></label><br><input id="api-wh-token" type="password" name="wh_password" autocomplete="new-password" value="" maxlength="128"></p>
		<p><label for="api-wh-ips"><?php esc_html_e( 'Allowed webhook IPs — one exact IPv4 or IPv6 address per line', 'scalyn-mail-relay' ); ?></label><br><textarea id="api-wh-ips" name="wh_ips" rows="4"><?php echo esc_textarea( implode( "\n", $config['allowed_ips'] ) ); ?></textarea></p>
		<p><?php esc_html_e( 'Forwarded IP headers are not trusted. Hosts behind a proxy must preserve the actual provider peer securely. Raw callbacks can contain sensitive data: disable request-body and Authorization logging at the web server or proxy.', 'scalyn-mail-relay' ); ?></p>
		<button class="button" name="wh_op" value="save"><?php esc_html_e( 'Save disabled source', 'scalyn-mail-relay' ); ?></button>
		<p><label><input type="checkbox" name="wh_acknowledge" value="1"> <?php esc_html_e( 'I configured this webhook in the sending account and consent to keyed recipient matching and minimal delivery/bounce evidence under the mail-log retention policy. No historical messages will be backfilled.', 'scalyn-mail-relay' ); ?></label></p>
		<button class="button" name="wh_op" value="enable"><?php esc_html_e( 'Enable collection', 'scalyn-mail-relay' ); ?></button>
		<button class="button" name="wh_op" value="disable"><?php esc_html_e( 'Disable collection', 'scalyn-mail-relay' ); ?></button>
		<p><label><input type="checkbox" name="wh_confirm_remove" value="1"> <?php esc_html_e( 'Remove the disabled source locally. Retained evidence follows normal cleanup. I will also remove the webhook in my provider account.', 'scalyn-mail-relay' ); ?></label></p>
		<button class="button" name="wh_op" value="remove"><?php esc_html_e( 'Remove source', 'scalyn-mail-relay' ); ?></button>
	</form>
</details>
