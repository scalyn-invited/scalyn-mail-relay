<?php
/**
 * Escaped agency settings, using the native WordPress navigation.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Agency Settings', 'scalyn-mail-relay' ); ?></h1>
	<p><?php esc_html_e( 'Protect critical configuration while giving clients and support staff appropriate access.', 'scalyn-mail-relay' ); ?></p>
	<?php if ( '' !== $notice ) : ?>
		<div class="notice <?php echo esc_attr( $error ? 'notice-error' : 'notice-success' ); ?>" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<div class="scalyn-grid">
		<section class="scalyn-card" aria-labelledby="scalyn-managed-title">
			<h2 id="scalyn-managed-title"><?php esc_html_e( 'Managed configuration', 'scalyn-mail-relay' ); ?></h2>
			<p><?php esc_html_e( 'Locks apply to delegated users. Administrators and Agency Managers can always override them. Diagnostics and ordinary mail sending continue.', 'scalyn-mail-relay' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'scalyn_agency_settings' ); ?>
				<input type="hidden" name="agency_action" value="policy">
				<p><label><input type="checkbox" name="managed" value="1" <?php checked( $policy['managed'] ); ?>> <?php esc_html_e( 'Enable managed mode', 'scalyn-mail-relay' ); ?></label></p>
				<fieldset><legend><?php esc_html_e( 'Locked setting groups', 'scalyn-mail-relay' ); ?></legend>
					<?php
					foreach ( array(
						'smtp'      => __( 'SMTP credentials and sender', 'scalyn-mail-relay' ),
						'providers' => __( 'Provider selection, API credentials and delivery webhooks', 'scalyn-mail-relay' ),
						'critical'  => __( 'Retention, metadata logging, monitoring, DKIM selector, alerts and uninstall policy', 'scalyn-mail-relay' ),
					) as $field => $label ) :
						?>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( $policy[ $field ] ); ?>> <?php echo esc_html( $label ); ?></label></p>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( __( 'Save managed policy', 'scalyn-mail-relay' ) ); ?>
			</form>
		</section>
		<section class="scalyn-card" aria-labelledby="scalyn-access-title">
			<h2 id="scalyn-access-title"><?php esc_html_e( 'Plugin access profiles', 'scalyn-mail-relay' ); ?></h2>
			<p><?php esc_html_e( 'Apply an access profile to an existing site member without replacing their WordPress roles. Only administrators can grant or change Agency Manager access. Administrator access is never reduced.', 'scalyn-mail-relay' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'scalyn_agency_settings' ); ?>
				<input type="hidden" name="agency_action" value="profile">
				<p><label for="scalyn-access-user"><?php esc_html_e( 'WordPress user ID', 'scalyn-mail-relay' ); ?></label><br><input id="scalyn-access-user" name="access_user_id" type="number" min="1" step="1" required aria-describedby="scalyn-access-help"></p>
				<p id="scalyn-access-help" class="description"><?php esc_html_e( 'Find the ID in the edit-user URL under Users. This form does not create users or change their access to other plugins.', 'scalyn-mail-relay' ); ?></p>
				<p><label for="scalyn-access-profile"><?php esc_html_e( 'Access profile', 'scalyn-mail-relay' ); ?></label><br><select id="scalyn-access-profile" name="access_profile">
					<?php foreach ( $profiles as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></p>
				<?php submit_button( __( 'Apply access profile', 'scalyn-mail-relay' ) ); ?>
			</form>
			<p><?php esc_html_e( 'Support Engineers can inspect logs and run diagnostics. Auditors can inspect logs and audit history. Client Viewers have read-only dashboard access. No profile grants access to message payloads or provider secrets.', 'scalyn-mail-relay' ); ?></p>
		</section>
	</div>
</div>
