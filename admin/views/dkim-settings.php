<?php
/**
 * Public DKIM selector configuration, no signing keys.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card" aria-labelledby="scalyn-dkim-heading">
	<h2 id="scalyn-dkim-heading"><?php esc_html_e( 'DKIM configuration', 'scalyn-mail-relay' ); ?></h2>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?>" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<?php if ( $can_manage ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-data-controls#scalyn-dkim-heading' ) ); ?>">
			<?php wp_nonce_field( 'scalyn_dkim_settings' ); ?>
			<input type="hidden" name="scalyn_dkim_settings" value="1" />
			<p><label for="scalyn-dkim-selector"><?php esc_html_e( 'DKIM selector', 'scalyn-mail-relay' ); ?></label></p>
			<input class="regular-text" type="text" id="scalyn-dkim-selector" name="dkim_selector" maxlength="63" value="<?php echo esc_attr( $selector ); ?>" aria-describedby="scalyn-dkim-help" autocomplete="off" spellcheck="false" />
			<p id="scalyn-dkim-help"><?php esc_html_e( 'Enter the selector supplied by your mail provider (the s= value in a DKIM-Signature header), for example selector1. Do not enter the full DNS record name or any signing key. Leave blank to clear. This currently supports a single DNS label, up to 63 characters.', 'scalyn-mail-relay' ); ?></p>
			<p><?php esc_html_e( 'The lookup uses the domain of your configured From email, or the site domain if no valid From email is configured. Your selector must belong to that domain. This checks the published DNS record; it does not enable DKIM signing or verify a message signature.', 'scalyn-mail-relay' ); ?></p>
			<?php submit_button( __( 'Save DKIM selector', 'scalyn-mail-relay' ) ); ?>
		</form>
	<?php else : ?>
		<p><?php esc_html_e( 'Ask an administrator with Mail Relay settings permission to configure the DKIM selector.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
</section>
