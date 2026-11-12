<?php
/**
 * Escaped brand and support identity.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay scalyn-brand-strip" aria-label="<?php esc_attr_e( 'Email operations support', 'scalyn-mail-relay' ); ?>">
	<?php if ( '' !== $logo ) : ?>
		<img class="scalyn-brand-logo" src="<?php echo esc_url( $logo ); ?>" alt="" width="120" height="48">
	<?php endif; ?>
	<span class="scalyn-brand-name"><?php echo esc_html( $brand['name'] ); ?></span>
	<nav aria-label="<?php esc_attr_e( 'Support links', 'scalyn-mail-relay' ); ?>">
		<?php
		if ( '' !== $brand['support_email'] ) :
			?>
			<a href="<?php echo esc_url( 'mailto:' . $brand['support_email'] ); ?>"><?php esc_html_e( 'Email support', 'scalyn-mail-relay' ); ?></a><?php endif; ?>
		<?php
		if ( '' !== $brand['support_url'] ) :
			?>
			<a href="<?php echo esc_url( $brand['support_url'] ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'Get support', 'scalyn-mail-relay' ); ?></a><?php endif; ?>
		<?php
		if ( '' !== $brand['docs_url'] ) :
			?>
			<a href="<?php echo esc_url( $brand['docs_url'] ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'scalyn-mail-relay' ); ?></a><?php endif; ?>
	</nav>
</div>
