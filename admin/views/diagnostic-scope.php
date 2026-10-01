<?php
/** Current diagnostic attribution, shared by health surfaces.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
if ( ! isset( $diagnostic_scope ) ) {
	return;
}
?>
<p class="scalyn-card__note">
	<?php
	/* translators: 1: Provider identifier, 2: Sending domain. */
	echo esc_html( sprintf( __( 'Current provider: %1$s · Sending domain: %2$s', 'scalyn-mail-relay' ), $diagnostic_scope['provider'], $diagnostic_scope['domain'] ) );
	?>
</p>
<?php if ( empty( $diagnostic_scope['results'] ) ) : ?>
	<p><?php esc_html_e( 'No diagnostics have been run for this configuration. Run diagnostics to see current results. Previous configurations are excluded.', 'scalyn-mail-relay' ); ?></p>
<?php endif; ?>
