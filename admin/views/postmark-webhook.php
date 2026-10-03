<?php
/**
 * Optional Postmark delivery/bounce evidence source: draft, verify, opt in, disable.
 *
 * Variables injected by PostmarkWebhookForm::render():
 *   array  $config Public source state; never credentials, keys or tokens.
 *   string $notice Fixed feedback.
 *   bool   $saved  Whether the last action succeeded.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;

$wh_action_url = admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=3' );
$wh_pauses     = array(
	'reverify' => __( 'Paused: the Postmark provider, token, sender or DKIM selector changed since verification. Verify the source again to resume.', 'scalyn-mail-relay' ),
	'provider' => __( 'Paused: Postmark is not the active sending provider.', 'scalyn-mail-relay' ),
	'stream'   => __( 'Paused: only the "outbound" transactional stream is used for sending.', 'scalyn-mail-relay' ),
	'storage'  => __( 'Paused: delivery evidence storage is not ready.', 'scalyn-mail-relay' ),
);
if ( ! $config['configured'] ) {
	$wh_collection = __( 'Off — no source saved', 'scalyn-mail-relay' );
} elseif ( ! $config['enabled'] ) {
	$wh_collection = __( 'Off', 'scalyn-mail-relay' );
} elseif ( $config['collecting'] ) {
	$wh_collection = __( 'On for new Postmark sends', 'scalyn-mail-relay' );
} else {
	$wh_collection = $wh_pauses[ $config['pause_reason'] ] ?? __( 'Paused', 'scalyn-mail-relay' );
}
?>
<details class="scalyn-card scalyn-webhook-source" <?php echo $notice || $config['enabled'] ? 'open' : ''; ?>>
	<summary><?php esc_html_e( 'Advanced: Postmark delivery and bounce evidence (optional)', 'scalyn-mail-relay' ); ?></summary>
	<p><?php esc_html_e( 'Optional. Skip this section to finish mail setup. When enabled, Postmark reports whether each recipient’s mail server accepted or bounced a message. This never proves inbox placement and never changes the Accepted sending status.', 'scalyn-mail-relay' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $saved ? 'notice-success' : 'notice-error'; ?> inline" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<dl class="scalyn-webhook-status">
		<dt><?php esc_html_e( 'Source', 'scalyn-mail-relay' ); ?></dt>
		<dd><?php echo esc_html( $config['configured'] ? __( 'Saved', 'scalyn-mail-relay' ) : __( 'Not saved', 'scalyn-mail-relay' ) ); ?></dd>
		<dt><?php esc_html_e( 'Verification', 'scalyn-mail-relay' ); ?></dt>
		<dd>
			<?php
			echo esc_html(
				$config['verified']
					/* translators: %s: UTC verification time. */
					? sprintf( __( 'Verified for the current Postmark configuration (%s UTC)', 'scalyn-mail-relay' ), $config['verified_at'] )
					: __( 'Not verified for the current Postmark configuration', 'scalyn-mail-relay' )
			);
			?>
		</dd>
		<dt><?php esc_html_e( 'Collection', 'scalyn-mail-relay' ); ?></dt>
		<dd><?php echo esc_html( $wh_collection ); ?></dd>
	</dl>

	<?php if ( $config['configured'] ) : ?>
		<h3><?php esc_html_e( 'Postmark webhook settings', 'scalyn-mail-relay' ); ?></h3>
		<p><label for="wh-endpoint"><?php esc_html_e( 'Webhook URL', 'scalyn-mail-relay' ); ?></label><br />
			<input id="wh-endpoint" class="large-text code" type="text" readonly value="<?php echo esc_attr( $config['endpoint'] ); ?>" aria-describedby="wh-endpoint-help" /></p>
		<?php if ( ! str_starts_with( $config['endpoint'], 'https://' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site’s webhook URL is not HTTPS. Callbacks are rejected unless the request reaches WordPress over HTTPS.', 'scalyn-mail-relay' ); ?></p></div>
		<?php endif; ?>
		<ol id="wh-endpoint-help">
			<li><?php esc_html_e( 'In Postmark, open this server’s outbound stream and add a webhook with the URL above. It must be reachable over public HTTPS.', 'scalyn-mail-relay' ); ?></li>
			<li><?php esc_html_e( 'Enter the dedicated webhook username and password saved here as the webhook’s Basic authentication credentials.', 'scalyn-mail-relay' ); ?></li>
			<li><?php esc_html_e( 'Select only the Delivery and Bounce events, and leave “Include bounce content” off.', 'scalyn-mail-relay' ); ?></li>
		</ol>
		<p class="description"><?php esc_html_e( 'Reports sent while collection is off are acknowledged and discarded. Postmark test or demo callbacks never count as delivery evidence.', 'scalyn-mail-relay' ); ?></p>

		<form method="post" action="<?php echo esc_url( $wh_action_url ); ?>">
			<?php wp_nonce_field( 'scalyn_postmark_webhook' ); ?>
			<input type="hidden" name="scalyn_postmark_webhook_draft" value="1" />
			<input type="hidden" name="wh_op" value="verify" />
			<p class="description"><?php esc_html_e( 'Verification checks that the server ID above belongs to the Live server of the saved Server API token. No email is sent.', 'scalyn-mail-relay' ); ?></p>
			<?php submit_button( __( 'Verify webhook source', 'scalyn-mail-relay' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( ! $config['enabled'] ) : ?>
			<form method="post" action="<?php echo esc_url( $wh_action_url ); ?>" class="scalyn-webhook-enable">
				<?php wp_nonce_field( 'scalyn_postmark_webhook' ); ?>
				<input type="hidden" name="scalyn_postmark_webhook_draft" value="1" />
				<input type="hidden" name="wh_op" value="enable" />
				<h3><?php esc_html_e( 'Collect delivery and bounce evidence', 'scalyn-mail-relay' ); ?></h3>
				<p id="wh-privacy">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: mail-log retention period in days. */
							__( 'Stores provider message identifiers, delivery/bounce events and keyed recipient tokens to match results to individual recipients. Tokens are pseudonymous data, not anonymous data. Recipient addresses are processed transiently for matching; this feature does not retain raw addresses or message bodies. Records follow your mail-log retention setting (currently %d days). Provider delivery confirmation does not prove inbox placement.', 'scalyn-mail-relay' ),
							(int) $config['retention_days']
						)
					);
					?>
				</p>
				<p><label><input name="wh_acknowledge" type="checkbox" value="1" aria-describedby="wh-privacy" /> <?php esc_html_e( 'I understand and want to collect delivery and bounce evidence for new Postmark sends.', 'scalyn-mail-relay' ); ?></label></p>
				<?php if ( ! $config['verified'] ) : ?>
					<p class="description"><?php esc_html_e( 'Verify the webhook source first.', 'scalyn-mail-relay' ); ?></p>
				<?php endif; ?>
				<?php submit_button( __( 'Enable collection', 'scalyn-mail-relay' ), 'primary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $wh_action_url ); ?>">
				<?php wp_nonce_field( 'scalyn_postmark_webhook' ); ?>
				<input type="hidden" name="scalyn_postmark_webhook_draft" value="1" />
				<input type="hidden" name="wh_op" value="disable" />
				<p class="description"><?php esc_html_e( 'Disabling stops tracking new sends and storing reports immediately. It does not delete retained evidence, which follows normal cleanup, and it does not remove the webhook from Postmark — pause or remove it there too.', 'scalyn-mail-relay' ); ?></p>
				<?php submit_button( __( 'Disable collection', 'scalyn-mail-relay' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Webhook source', 'scalyn-mail-relay' ); ?></h3>
	<?php if ( $config['enabled'] ) : ?>
		<p><?php esc_html_e( 'Disable collection before changing or removing the source.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<form method="post" autocomplete="off" action="<?php echo esc_url( $wh_action_url ); ?>">
			<?php wp_nonce_field( 'scalyn_postmark_webhook' ); ?>
			<input type="hidden" name="scalyn_postmark_webhook_draft" value="1" />
			<p><label for="wh-server"><?php esc_html_e( 'Postmark Server ID', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-server" name="wh_server_id" type="number" min="1" value="<?php echo $config['server_id'] ? esc_attr( $config['server_id'] ) : ''; ?>" /></p>
			<p><label for="wh-stream"><?php esc_html_e( 'Message stream', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-stream" name="wh_stream" type="text" maxlength="100" value="<?php echo esc_attr( '' !== $config['stream'] ? $config['stream'] : 'outbound' ); ?>" aria-describedby="wh-stream-help" /></p>
			<p id="wh-stream-help" class="description"><?php esc_html_e( 'Sending uses the "outbound" transactional stream; collection requires it.', 'scalyn-mail-relay' ); ?></p>
			<p><label for="wh-ips"><?php esc_html_e( 'Allowed webhook IP addresses (one exact address per line)', 'scalyn-mail-relay' ); ?></label><br /><textarea id="wh-ips" name="wh_ips" rows="4" maxlength="12000" aria-describedby="wh-ip-help"><?php echo esc_textarea( implode( "\n", $config['allowed_ips'] ) ); ?></textarea></p>
			<p id="wh-ip-help"><?php esc_html_e( 'Use current Postmark webhook addresses verified by your server administrator. CIDR ranges and forwarded-header overrides are not supported. Behind a proxy or CDN, the address WordPress sees may differ; confirm it before enabling.', 'scalyn-mail-relay' ); ?></p>
			<p><label for="wh-action"><?php esc_html_e( 'Webhook credential action', 'scalyn-mail-relay' ); ?></label><br /><select id="wh-action" name="wh_action">
				<option value="keep" <?php selected( $config['configured'], true ); ?>><?php esc_html_e( 'Keep saved credentials', 'scalyn-mail-relay' ); ?></option>
				<option value="replace" <?php selected( $config['configured'], false ); ?>><?php esc_html_e( 'Add or replace credentials', 'scalyn-mail-relay' ); ?></option>
				<option value="remove"><?php esc_html_e( 'Remove source', 'scalyn-mail-relay' ); ?></option>
			</select></p>
			<p id="wh-secret-help"><?php esc_html_e( 'Use dedicated random credentials from your password manager, not the Server API token. Allowed characters: letters, digits, hyphen and underscore. Username: 16–128 characters; password: 32–128. Keep a secure copy for the Postmark webhook settings. Saved credentials are never displayed; leave both fields blank when keeping or removing.', 'scalyn-mail-relay' ); ?></p>
			<p><label for="wh-user"><?php esc_html_e( 'New webhook username', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-user" name="wh_username" type="password" maxlength="128" autocomplete="new-password" value="" aria-describedby="wh-secret-help" /></p>
			<p><label for="wh-password"><?php esc_html_e( 'New webhook password', 'scalyn-mail-relay' ); ?></label><br /><input id="wh-password" name="wh_password" type="password" maxlength="128" autocomplete="new-password" value="" aria-describedby="wh-secret-help" /></p>
			<p><label><input name="wh_confirm_remove" type="checkbox" value="1" /> <?php esc_html_e( 'I confirm removal of this source and its rate-limit counter. Retained evidence follows normal cleanup. Postmark credentials and webhooks are not changed.', 'scalyn-mail-relay' ); ?></label></p>
			<?php submit_button( __( 'Save webhook source action', 'scalyn-mail-relay' ) ); ?>
		</form>
	<?php endif; ?>
</details>
