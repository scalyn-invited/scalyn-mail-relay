<?php
/**
 * Disabled draft configuration; no callback URL or enable action exists yet.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<details class="scalyn-card" <?php echo $notice ? 'open' : ''; ?>>
	<summary><?php esc_html_e( 'Advanced: prepare Postmark delivery webhooks (disabled)', 'scalyn-mail-relay' ); ?></summary>
	<p><?php esc_html_e( 'Optional preparation only. Skip this section to finish mail setup. No endpoint is active, no recipient tokens are collected, and delivery tracking cannot be enabled yet. Do not register a webhook at Postmark yet.', 'scalyn-mail-relay' ); ?></p>
	<p><strong><?php echo esc_html( $config['configured'] ? __( 'Draft saved — disabled and unverified.', 'scalyn-mail-relay' ) : __( 'No webhook draft saved.', 'scalyn-mail-relay' ) ); ?></strong></p>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $saved ? 'notice-success' : 'notice-error'; ?> inline" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<form method="post" autocomplete="off" action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=3' ) ); ?>">
		<?php wp_nonce_field( 'scalyn_postmark_webhook' ); ?>
		<input type="hidden" name="scalyn_postmark_webhook_draft" value="1" />
		<p><label for="wh-server"><?php esc_html_e( 'Postmark Server ID', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-server" name="wh_server_id" type="number" min="1" value="<?php echo $config['server_id'] ? esc_attr( $config['server_id'] ) : ''; ?>" /></p>
		<p><label for="wh-stream"><?php esc_html_e( 'Message stream', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-stream" name="wh_stream" type="text" maxlength="100" value="<?php echo esc_attr( '' !== $config['stream'] ? $config['stream'] : 'outbound' ); ?>" /></p>
		<p><label for="wh-ips"><?php esc_html_e( 'Allowed webhook IP addresses (one exact address per line)', 'scalyn-mail-relay' ); ?></label><br /><textarea id="wh-ips" name="wh_ips" rows="4" maxlength="12000" aria-describedby="wh-ip-help"><?php echo esc_textarea( implode( "\n", $config['allowed_ips'] ) ); ?></textarea></p>
		<p id="wh-ip-help"><?php esc_html_e( 'Use current Postmark webhook addresses verified by your server administrator. CIDR ranges and forwarded-header overrides are not supported here. This draft does not test connectivity.', 'scalyn-mail-relay' ); ?></p>
		<p><label for="wh-action"><?php esc_html_e( 'Webhook credential action', 'scalyn-mail-relay' ); ?></label><br /><select id="wh-action" name="wh_action">
			<option value="keep" <?php selected( $config['configured'], true ); ?>><?php esc_html_e( 'Keep saved credentials', 'scalyn-mail-relay' ); ?></option>
			<option value="replace" <?php selected( $config['configured'], false ); ?>><?php esc_html_e( 'Add or replace credentials', 'scalyn-mail-relay' ); ?></option>
			<option value="remove"><?php esc_html_e( 'Remove disabled draft', 'scalyn-mail-relay' ); ?></option>
		</select></p>
		<p id="wh-secret-help"><?php esc_html_e( 'Use dedicated random credentials from your password manager, not the Server API token. Allowed characters: letters, digits, hyphen and underscore. Username: 16–128 characters; password: 32–128. Keep a secure copy for later provider configuration. Saved credentials are never displayed; leave both fields blank when keeping or removing.', 'scalyn-mail-relay' ); ?></p>
		<p><label for="wh-user"><?php esc_html_e( 'New webhook username', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-user" name="wh_username" type="password" maxlength="128" autocomplete="new-password" value="" aria-describedby="wh-secret-help" /></p>
		<p><label for="wh-password"><?php esc_html_e( 'New webhook password', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-password" name="wh_password" type="password" maxlength="128" autocomplete="new-password" value="" aria-describedby="wh-secret-help" /></p>
		<p><label><input name="wh_confirm_remove" type="checkbox" value="1" /> <?php esc_html_e( 'I confirm removal of this disabled draft and its rate-limit counter. Postmark credentials are not revoked.', 'scalyn-mail-relay' ); ?></label></p>
		<?php submit_button( __( 'Save webhook draft action', 'scalyn-mail-relay' ) ); ?>
	</form>
</details>
