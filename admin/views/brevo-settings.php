<?php
/**
 * Brevo configuration only; credentials never populate markup.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo $in_wizard ? 'scalyn-wizard-provider-form' : 'scalyn-card'; ?>" aria-labelledby="scalyn-brevo-heading">
	<h2 id="scalyn-brevo-heading"><?php echo esc_html( $in_wizard ? __( 'Configure Brevo', 'scalyn-mail-relay' ) : __( 'Brevo settings', 'scalyn-mail-relay' ) ); ?></h2>
	<p><?php echo esc_html( $in_wizard ? __( 'Save your sender and API key here, then continue to connection verification. Saving changes the Brevo configuration used by this site.', 'scalyn-mail-relay' ) : __( 'Save your sender and API key, then use the Setup Wizard to select Brevo and verify the connection.', 'scalyn-mail-relay' ) ); ?></p>
	<?php if ( ! $encryption_available ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Server encryption is unavailable. A valid SCALYN_MAIL_RELAY_ENCRYPTION_KEY and PHP OpenSSL are required before an API key can be saved.', 'scalyn-mail-relay' ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Before saving a key, configure SCALYN_MAIL_RELAY_ENCRYPTION_KEY on the server with a base64-encoded random 32-byte key. Keep it outside Git and back it up separately. Missing or changed encryption keys require credential replacement.', 'scalyn-mail-relay' ); ?></p>
	<p><?php echo esc_html( $config['has_key'] ? __( 'An encrypted credential is stored. This does not confirm readability, authentication or delivery.', 'scalyn-mail-relay' ) : __( 'No API key is stored.', 'scalyn-mail-relay' ) ); ?></p>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $saved ? 'notice-success' : 'notice-error'; ?> inline" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<?php if ( $config['has_key'] && '' !== $config['from_email'] ) : ?>
		<p><strong><?php esc_html_e( 'Sender and encrypted API key are saved.', 'scalyn-mail-relay' ); ?></strong></p>
		<?php if ( ! $in_wizard ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=' . $wizard_step ) ); ?>"><?php esc_html_e( 'Continue in Setup Wizard', 'scalyn-mail-relay' ); ?></a></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $can_manage ) : ?>
		<form method="post" autocomplete="off"
		<?php
		if ( $in_wizard ) :
			?>
			action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=3' ) ); ?>"<?php endif; ?>>
			<?php wp_nonce_field( 'scalyn_brevo_settings' ); ?>
			<input type="hidden" name="scalyn_brevo_settings" value="1" />
			<p><label for="brevo-from-email"><?php esc_html_e( 'Sender email', 'scalyn-mail-relay' ); ?></label><br />
			<input id="brevo-from-email" name="from_email" type="email" maxlength="254" required value="<?php echo esc_attr( $config['from_email'] ); ?>" /></p>
			<p><label for="brevo-from-name"><?php esc_html_e( 'Sender name', 'scalyn-mail-relay' ); ?></label><br />
			<input id="brevo-from-name" name="from_name" type="text" maxlength="200" value="<?php echo esc_attr( $config['from_name'] ); ?>" /></p>
			<p><label for="brevo-key-action"><?php esc_html_e( 'API key action', 'scalyn-mail-relay' ); ?></label><br />
			<select id="brevo-key-action" name="key_action">
				<option value="keep" <?php selected( $config['has_key'], true ); ?>><?php esc_html_e( 'Keep saved key', 'scalyn-mail-relay' ); ?></option>
				<option value="replace" <?php selected( $config['has_key'], false ); ?>><?php esc_html_e( 'Add or replace key', 'scalyn-mail-relay' ); ?></option>
				<option value="remove"><?php esc_html_e( 'Remove saved key', 'scalyn-mail-relay' ); ?></option>
			</select></p>
			<p><label for="brevo-api-key"><?php esc_html_e( 'New API key (only for Add or replace)', 'scalyn-mail-relay' ); ?></label><br />
			<input id="brevo-api-key" name="api_key" type="password" maxlength="512" autocomplete="new-password" value="" aria-describedby="brevo-key-help" /></p>
			<p id="brevo-key-help"><?php esc_html_e( 'Use a Brevo API key, not an SMTP key. Account-read and transactional sending access are required. Check Brevo authorized IP restrictions. The saved key is never displayed. Leave this blank when keeping or removing a key. Sender verification and domain authentication are configured in Brevo.', 'scalyn-mail-relay' ); ?></p>
			<p><label><input type="checkbox" name="confirm_remove" value="1" /> <?php esc_html_e( 'I confirm removal of the saved API key. This does not revoke the key at Brevo.', 'scalyn-mail-relay' ); ?></label></p>
			<?php submit_button( __( 'Save Brevo configuration', 'scalyn-mail-relay' ) ); ?>
		</form>
	<?php else : ?>
		<p><?php esc_html_e( 'You do not have permission to configure mail providers. Ask an administrator to save these settings.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
</section>
<?php if ( $in_wizard && $config['has_key'] && '' !== $config['from_email'] ) : ?>
	<p><?php esc_html_e( 'Save any changes above before continuing. Verification uses the saved configuration.', 'scalyn-mail-relay' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=' . $wizard_step ) ); ?>"><?php esc_html_e( 'Continue to verification', 'scalyn-mail-relay' ); ?></a></p>
<?php endif; ?>
