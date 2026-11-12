<?php
/**
 * Saved identity list; settings and credentials stay in wizard step 3.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;

$configuration_settings = $this->settings;
$form_fields            = static function () use ( $wizard, $configuration_settings ): void {
	wp_nonce_field( 'scalyn_configuration' );
	\Scalyn\MailRelay\Admin\ConfigurationGuard::field( $configuration_settings );
	echo '<input type="hidden" name="action" value="scalyn_configuration" />';
	echo '<input type="hidden" name="return_page" value="' . esc_attr( $wizard ? 'wizard' : 'providers' ) . '" />';
};
?>
<section class="scalyn-card scalyn-configurations" aria-labelledby="scalyn-configurations-heading">
	<h2 id="scalyn-configurations-heading"><?php esc_html_e( 'Saved configurations', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Choose a named configuration as the site default. Selection takes effect immediately. Creating or renaming one does not select it. Routing and opt-in recovery are configured separately; saving another configuration does not enable either.', 'scalyn-mail-relay' ); ?></p>
	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixed read-only feedback, never used as evidence of persistence.
	$feedback = isset( $_GET['configuration_saved'] ) && is_string( $_GET['configuration_saved'] ) ? sanitize_text_field( wp_unslash( $_GET['configuration_saved'] ) ) : '';
	if ( '' !== $feedback ) :
		?>
		<p class="scalyn-helper-highlight" role="status"><?php echo esc_html( 'yes' === $feedback ? __( 'Configuration action saved. Review the selected default below.', 'scalyn-mail-relay' ) : __( 'No changes saved. Reload and check the name, selection, managed locks and server encryption. The active default cannot be archived.', 'scalyn-mail-relay' ) ); ?></p>
	<?php endif; ?>
	<?php if ( $locked ) : ?>
		<p><?php esc_html_e( 'Configurations are managed by your administrator or agency manager.', 'scalyn-mail-relay' ); ?></p>
	<?php elseif ( empty( $configurations ) ) : ?>
		<p><?php esc_html_e( 'Import the existing provider settings to manage named configurations. The current sending provider and its evidence are preserved.', 'scalyn-mail-relay' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $form_fields(); ?>
			<button class="button" name="operation" value="import"><?php esc_html_e( 'Import existing configurations', 'scalyn-mail-relay' ); ?></button>
		</form>
	<?php endif; ?>
	<?php foreach ( $configurations as $configuration ) : ?>
		<article class="scalyn-configuration-item">
			<h3><?php echo esc_html( $configuration['name'] ); ?><?php echo $configuration['active'] ? ' — ' . esc_html__( 'Default', 'scalyn-mail-relay' ) : ''; ?><?php echo $configuration['archived'] ? ' — ' . esc_html__( 'Archived', 'scalyn-mail-relay' ) : ''; ?></h3>
			<p><?php echo esc_html( strtoupper( $configuration['provider'] ) ); ?> · <?php esc_html_e( 'Evidence belongs to this configuration’s current revision; saved settings do not prove delivery.', 'scalyn-mail-relay' ); ?></p>
			<?php if ( ! $locked && ! $configuration['archived'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $form_fields(); ?>
					<input type="hidden" name="configuration" value="<?php echo esc_attr( $configuration['id'] ); ?>" />
					<p><label for="configuration-<?php echo esc_attr( $configuration['id'] ); ?>"><?php esc_html_e( 'Configuration name', 'scalyn-mail-relay' ); ?></label><br />
					<input id="configuration-<?php echo esc_attr( $configuration['id'] ); ?>" name="configuration_name" type="text" maxlength="80" value="<?php echo esc_attr( $configuration['name'] ); ?>" /></p>
					<div class="scalyn-actions">
						<button class="button" name="operation" value="rename"><?php esc_html_e( 'Rename', 'scalyn-mail-relay' ); ?></button>
						<button class="button button-primary" name="operation" value="select"><?php echo esc_html( $configuration['active'] ? __( 'Configure in wizard', 'scalyn-mail-relay' ) : __( 'Select default and configure', 'scalyn-mail-relay' ) ); ?></button>
						<?php
						if ( ! $configuration['active'] ) :
							?>
							<button class="button" name="operation" value="archive"><?php esc_html_e( 'Archive', 'scalyn-mail-relay' ); ?></button><?php endif; ?>
					</div>
				</form>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
	<?php if ( ! $locked ) : ?>
		<details class="scalyn-disclosure"><summary><?php esc_html_e( 'Add a configuration', 'scalyn-mail-relay' ); ?></summary>
			<p><?php esc_html_e( 'Server encryption is required. After creating, explicitly select the new configuration and enter credentials in step 3. An unconfigured default cannot send mail.', 'scalyn-mail-relay' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $form_fields(); ?>
				<p><label for="new-configuration-name"><?php esc_html_e( 'New configuration name', 'scalyn-mail-relay' ); ?></label><br /><input id="new-configuration-name" name="configuration_name" required maxlength="80" type="text" /></p>
				<p><label for="new-configuration-provider"><?php esc_html_e( 'Provider', 'scalyn-mail-relay' ); ?></label><br />
				<select id="new-configuration-provider" name="configuration">
				<?php
				foreach ( \Scalyn\MailRelay\Core\NamedConfigurations::PROVIDERS as $configuration_provider ) :
					?>
					<option value="<?php echo esc_attr( $configuration_provider ); ?>"><?php echo esc_html( strtoupper( $configuration_provider ) ); ?></option><?php endforeach; ?></select></p>
				<button class="button" name="operation" value="create"><?php esc_html_e( 'Create configuration', 'scalyn-mail-relay' ); ?></button>
			</form>
		</details>
	<?php endif; ?>
</section>
