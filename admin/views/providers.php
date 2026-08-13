<?php
/**
 * Providers page placeholder view.
 *
 * Provider listing, configuration forms, and connection management UI will
 * be implemented once Saturn delivers the provider contracts and REST endpoints.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Providers', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Manage your mail provider connections.', 'scalyn-mail-relay' ); ?></p>

	<div class="scalyn-card">
		<h2><?php esc_html_e( 'No Providers Available', 'scalyn-mail-relay' ); ?></h2>
		<p><?php esc_html_e( 'Mail provider management is coming soon. Once provider modules are installed, you will configure and switch between providers here.', 'scalyn-mail-relay' ); ?></p>
		<p class="description"><?php esc_html_e( 'Use the Setup Wizard to configure your first provider.', 'scalyn-mail-relay' ); ?></p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Open Setup Wizard', 'scalyn-mail-relay' ); ?>
		</a>
	</div>
</div>
