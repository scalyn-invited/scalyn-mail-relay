<?php
/** Routing form, variables from RoutingForm.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card">
	<h2><?php esc_html_e( 'Email routing', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Route explicit mail sources to a named configuration. Unmatched mail uses the default route, or the selected provider when no default route is set. Subjects, addresses and header markers never select a route.', 'scalyn-mail-relay' ); ?></p>
	<p><?php esc_html_e( 'Alternates are used only with opt-in recovery. Saving alternates does not enable retries. Editing or archiving a configuration can stop a saved route.', 'scalyn-mail-relay' ); ?></p>
	<?php
	if ( $notice ) :
		?>
		<p role="status"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-providers' ) . '#email-routing' ); ?>">
		<?php
		wp_nonce_field( 'scalyn_routing' );
		\Scalyn\MailRelay\Admin\ConfigurationGuard::field( $settings );
		?>
		<input type="hidden" name="routing_token" value="<?php echo esc_attr( $policy['token'] ); ?>">
		<p><label class="scalyn-switch"><input type="checkbox" role="switch" id="scalyn-routing-enabled" name="routing_enabled" value="1" aria-describedby="scalyn-routing-disabled" <?php checked( true, $policy['enabled'] ); ?>><span class="scalyn-switch__track" aria-hidden="true"></span><span><?php esc_html_e( 'Enable explicit routing', 'scalyn-mail-relay' ); ?></span><span class="scalyn-switch__off" aria-hidden="true"><?php esc_html_e( 'Off', 'scalyn-mail-relay' ); ?></span><span class="scalyn-switch__on" aria-hidden="true"><?php esc_html_e( 'On', 'scalyn-mail-relay' ); ?></span></label></p>
		<p id="scalyn-routing-disabled" class="scalyn-helper-highlight" role="status" <?php echo $policy['enabled'] ? 'hidden' : ''; ?>><?php esc_html_e( 'Routing is disabled. All emails use the site-default configuration. Enable explicit routing to edit these rules. Saved selections are kept.', 'scalyn-mail-relay' ); ?></p>
		<fieldset id="scalyn-routing-rules" <?php disabled( ! $policy['enabled'] ); ?>>
		<legend class="screen-reader-text"><?php esc_html_e( 'Source routing rules', 'scalyn-mail-relay' ); ?></legend>
		<?php foreach ( \Scalyn\MailRelay\Mail\MailOrigin::types() as $key => $label ) : ?>
		<fieldset class="scalyn-routing-row">
			<legend><?php echo esc_html( $label ); ?></legend>
			<?php foreach ( array( __( 'Primary', 'scalyn-mail-relay' ), __( 'Alternate 1', 'scalyn-mail-relay' ), __( 'Alternate 2', 'scalyn-mail-relay' ) ) as $index => $position ) : ?>
			<label><?php echo esc_html( $position ); ?>
				<select name="routes[<?php echo esc_attr( str_replace( '/', '__', $key ) ); ?>][]">
					<option value=""><?php esc_html_e( 'Not set', 'scalyn-mail-relay' ); ?></option>
					<?php foreach ( $configurations as $item ) : ?>
					<option value="<?php echo esc_attr( $item['id'] ); ?>" <?php selected( $policy['rules'][ $key ][ $index ] ?? '', $item['id'] ); ?>><?php echo esc_html( $item['name'] . ' (' . $item['provider'] . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php endforeach; ?>
		</fieldset>
		<?php endforeach; ?>
		</fieldset>
		<p><button class="button button-primary" name="scalyn_routing_save" value="1"><?php esc_html_e( 'Save routing', 'scalyn-mail-relay' ); ?></button></p>
	</form>
</section>
