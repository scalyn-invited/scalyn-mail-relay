<?php
/**
 * Gmail OAuth configuration view.
 *
 * Variables injected by GmailSettingsForm::render():
 *   bool   $in_wizard           Whether rendered inside wizard step 3.
 *   bool   $can_manage          Whether the current user can configure providers.
 *   string $notice              Fixed safe feedback string (empty when none).
 *   bool   $saved               Whether the last save succeeded.
 *   bool   $encryption_available Whether server encryption is usable.
 *   array  $config              Credential-free Gmail settings.
 *   string $redirect_uri        OAuth redirect URI to register in Google Cloud Console.
 *   int    $wizard_step         Wizard step to link back to after saving.
 *
 * @security OAuth tokens are never rendered. Only client_id (non-sensitive) is shown.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo $in_wizard ? 'scalyn-wizard-provider-form' : 'scalyn-card'; ?>" aria-labelledby="scalyn-gmail-heading">
	<h2 id="scalyn-gmail-heading"><?php echo esc_html( $in_wizard ? __( 'Configure Gmail / Google Workspace', 'scalyn-mail-relay' ) : __( 'Gmail / Google Workspace settings', 'scalyn-mail-relay' ) ); ?></h2>
	<p>
	<?php
	echo esc_html(
		$in_wizard
		? __( 'Connect your Google account with the gmail.send scope, then continue to connection verification.', 'scalyn-mail-relay' )
		: __( 'Connect a Google or Google Workspace account for Gmail-API sending with the gmail.send scope.', 'scalyn-mail-relay' )
	);
	?>
	</p>

	<?php if ( ! $encryption_available ) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'Server encryption is unavailable. To save credentials, check private file permissions and PHP OpenSSL support.', 'scalyn-mail-relay' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $saved ? 'notice-success' : 'notice-error'; ?> inline" role="status">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $config['connected'] ) : ?>
		<p class="scalyn-badge scalyn-badge--connected"><?php esc_html_e( 'Connected', 'scalyn-mail-relay' ); ?></p>
		<?php if ( '' !== $config['from_email'] ) : ?>
			<p><?php esc_html_e( 'Sending as:', 'scalyn-mail-relay' ); ?> <strong><?php echo esc_html( $config['from_email'] ); ?></strong></p>
		<?php endif; ?>
		<?php if ( $config['expires_at'] > 0 ) : ?>
			<p class="description">
			<?php
			echo esc_html(
				$config['expires_at'] > time()
					? sprintf(
						/* translators: %s: local datetime string */
						__( 'Access token valid until %s (UTC). The plugin refreshes it automatically before expiry.', 'scalyn-mail-relay' ),
						gmdate( 'Y-m-d H:i', $config['expires_at'] )
					)
					: __( 'Access token is expired and will be refreshed on the next send or connection check.', 'scalyn-mail-relay' )
			);
			?>
			</p>
		<?php endif; ?>
		<?php if ( ! $in_wizard ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=' . $wizard_step ) ); ?>"><?php esc_html_e( 'Continue in Setup Wizard', 'scalyn-mail-relay' ); ?></a></p>
		<?php endif; ?>
	<?php else : ?>
		<p class="scalyn-badge scalyn-badge--disconnected"><?php esc_html_e( 'Not connected', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>

	<details class="scalyn-disclosure" <?php echo '' === $config['client_id'] ? 'open' : ''; ?>>
		<summary><?php esc_html_e( 'Google Cloud Console setup instructions', 'scalyn-mail-relay' ); ?></summary>
		<ol>
			<li><?php esc_html_e( 'Open Google Cloud Console → APIs & Services → Credentials.', 'scalyn-mail-relay' ); ?></li>
			<li><?php esc_html_e( 'Create or select an OAuth 2.0 Client ID of type "Web application".', 'scalyn-mail-relay' ); ?></li>
			<li><?php esc_html_e( 'Enable the Gmail API in APIs & Services → Library.', 'scalyn-mail-relay' ); ?></li>
			<li>
			<?php esc_html_e( 'Add this exact Authorized Redirect URI:', 'scalyn-mail-relay' ); ?>
			<br /><strong><code><?php echo esc_html( $redirect_uri ); ?></code></strong>
			</li>
			<li><?php esc_html_e( 'Copy the Client ID and Client Secret below, then click Save and Connect.', 'scalyn-mail-relay' ); ?></li>
		</ol>
		<p class="description"><?php esc_html_e( 'Only the gmail.send scope is requested. For Google Workspace accounts, an admin may need to approve the OAuth consent screen in the workspace admin console.', 'scalyn-mail-relay' ); ?></p>
	</details>

	<?php if ( $can_manage ) : ?>

		<?php /* ----- Client credentials form ----- */ ?>
		<form method="post" autocomplete="off"
		<?php
		if ( $in_wizard ) :
			?>
			action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=3' ) ); ?>"<?php endif; ?>>
			<?php wp_nonce_field( 'scalyn_gmail_client' ); ?>
			<?php \Scalyn\MailRelay\Admin\ConfigurationGuard::field( $this->settings ); ?>
			<input type="hidden" name="scalyn_gmail_client" value="1" />

			<h3><?php echo esc_html( $config['connected'] ? __( 'Reconnect with different credentials', 'scalyn-mail-relay' ) : __( 'Connect Google account', 'scalyn-mail-relay' ) ); ?></h3>

			<p>
				<label for="gmail-client-id"><?php esc_html_e( 'Client ID', 'scalyn-mail-relay' ); ?></label><br />
				<input id="gmail-client-id" name="gmail_client_id" type="text" class="regular-text"
					maxlength="200" autocomplete="off"
					value="<?php echo esc_attr( $config['client_id'] ); ?>"
					placeholder="123456789-abc.apps.googleusercontent.com" />
			</p>
			<p>
				<label for="gmail-client-secret"><?php esc_html_e( 'Client Secret', 'scalyn-mail-relay' ); ?></label><br />
				<input id="gmail-client-secret" name="gmail_client_secret" type="password" class="regular-text"
					maxlength="200" autocomplete="new-password" value="" />
				<span class="description"><?php echo esc_html( $config['has_secret'] ? __( 'A secret is saved. Enter a new value to replace it.', 'scalyn-mail-relay' ) : __( 'Enter the Client Secret from Google Cloud Console.', 'scalyn-mail-relay' ) ); ?></span>
			</p>
			<p class="description"><?php esc_html_e( 'Saving redirects your browser to Google for account authorization. You will return here automatically.', 'scalyn-mail-relay' ); ?></p>

			<?php submit_button( $config['connected'] ? __( 'Reconnect Google account', 'scalyn-mail-relay' ) : __( 'Save and Connect Google account', 'scalyn-mail-relay' ), 'primary' ); ?>
		</form>

		<?php if ( $config['connected'] ) : ?>
			<?php /* ----- Sender form ----- */ ?>
			<form method="post" autocomplete="off"
			<?php
			if ( $in_wizard ) :
				?>
				action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=3' ) ); ?>"<?php endif; ?>>
				<?php wp_nonce_field( 'scalyn_gmail_sender' ); ?>
				<?php \Scalyn\MailRelay\Admin\ConfigurationGuard::field( $this->settings ); ?>
				<input type="hidden" name="scalyn_gmail_sender" value="1" />

				<h3><?php esc_html_e( 'Sender', 'scalyn-mail-relay' ); ?></h3>
				<p>
					<label for="gmail-from-email"><?php esc_html_e( 'Sender email', 'scalyn-mail-relay' ); ?></label><br />
					<input id="gmail-from-email" name="gmail_from_email" type="email" class="regular-text"
						maxlength="254" required value="<?php echo esc_attr( $config['from_email'] ); ?>" />
					<span class="description"><?php esc_html_e( 'Must be the Gmail or Google Workspace address you authorized.', 'scalyn-mail-relay' ); ?></span>
				</p>
				<p>
					<label for="gmail-from-name"><?php esc_html_e( 'Sender name', 'scalyn-mail-relay' ); ?></label><br />
					<input id="gmail-from-name" name="gmail_from_name" type="text" class="regular-text"
						maxlength="200" value="<?php echo esc_attr( $config['from_name'] ); ?>" />
				</p>
				<?php submit_button( __( 'Save sender', 'scalyn-mail-relay' ) ); ?>
			</form>
		<?php endif; ?>

	<?php else : ?>
		<p><?php esc_html_e( 'You do not have permission to configure mail providers. Ask an administrator to connect the Google account.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>

</section>
<?php if ( $in_wizard && $config['connected'] && '' !== $config['from_email'] ) : ?>
	<p><?php esc_html_e( 'Save any changes above before continuing. Verification uses the saved configuration.', 'scalyn-mail-relay' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard&step=' . $wizard_step ) ); ?>"><?php esc_html_e( 'Continue to verification', 'scalyn-mail-relay' ); ?></a></p>
<?php endif; ?>
