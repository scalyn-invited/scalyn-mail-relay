<?php
/**
 * Providers page view.
 *
 * Variables injected by ProvidersPage::render():
 *   array  $providers  List of provider data arrays: id, label, is_active, configured.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;

$wizard_url = admin_url( 'admin.php?page=scalyn-mail-relay-wizard' );
?>
<div class="wrap scalyn-mail-relay scalyn-providers">
	<h1><?php esc_html_e( 'Providers', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Manage how your site sends email. Configure a provider, verify its connection, and send a test.', 'scalyn-mail-relay' ); ?></p>
	<section class="scalyn-card scalyn-provider-overview" aria-labelledby="scalyn-active-route">
		<div><h2 id="scalyn-active-route"><?php esc_html_e( 'Active sending route', 'scalyn-mail-relay' ); ?></h2>
		<p class="scalyn-provider-overview__name"><?php echo esc_html( $active_label ); ?></p>
		<p><?php esc_html_e( 'Only the selected provider handles site email. Saved settings on another provider do not enable automatic failover.', 'scalyn-mail-relay' ); ?></p></div>
		<?php if ( $can_configure ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'step', 2, $wizard_url ) ); ?>"><?php esc_html_e( 'Change sending provider', 'scalyn-mail-relay' ); ?></a>
		<?php endif; ?>
	</section>

	<?php if ( empty( $providers ) ) : ?>
		<div class="scalyn-card">
			<h2><?php esc_html_e( 'No Providers Registered', 'scalyn-mail-relay' ); ?></h2>
			<p><?php esc_html_e( 'No mail provider modules are currently registered.', 'scalyn-mail-relay' ); ?></p>
			<?php
			if ( $can_configure ) :
				?>
				<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Open Setup Wizard', 'scalyn-mail-relay' ); ?>
			</a><?php endif; ?>
		</div>
	<?php else : ?>
		<div class="scalyn-grid scalyn-provider-grid">
					<?php foreach ( $providers as $p ) : ?>
						<section class="scalyn-card scalyn-provider-card">
							<div class="scalyn-panel-heading">
								<h2><?php echo esc_html( $p['label'] ); ?></h2>
								<?php if ( $p['is_active'] ) : ?>
									<span class="scalyn-badge scalyn-badge--connected"><?php esc_html_e( 'Active', 'scalyn-mail-relay' ); ?></span>
								<?php endif; ?>
							</div>
							<p class="scalyn-provider-description"><?php echo esc_html( 'smtp' === $p['id'] ? __( 'Connect an existing mail server using your provider’s host and login details.', 'scalyn-mail-relay' ) : ( 'sendgrid' === $p['id'] ? __( 'Send through the SendGrid API with a Mail Send key and an authorized sender.', 'scalyn-mail-relay' ) : __( 'A registered mail provider extension.', 'scalyn-mail-relay' ) ) ); ?></p>
							<p>
								<?php if ( $p['configured'] ) : ?>
									<span class="scalyn-badge scalyn-badge--connected"><?php esc_html_e( 'Settings saved', 'scalyn-mail-relay' ); ?></span>
								<?php else : ?>
									<span class="scalyn-badge scalyn-badge--disconnected"><?php esc_html_e( 'Not configured', 'scalyn-mail-relay' ); ?></span>
								<?php endif; ?>
							</p>
							<dl>
								<dt><?php esc_html_e( 'Transport', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $p['transport'] ); ?></dd>
								<dt><?php esc_html_e( 'Verification', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $p['verified'] ? __( 'Success recorded', 'scalyn-mail-relay' ) : __( 'Not verified for the current selection', 'scalyn-mail-relay' ) ); ?></dd>
								<?php
								if ( $p['verified'] ) :
									?>
									<dt><?php esc_html_e( 'Last success (site time)', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $p['verified_at'] ); ?></dd><?php endif; ?>
							</dl>
							<div class="scalyn-actions scalyn-provider-actions">
								<?php if ( $can_configure ) : ?>
									<a class="button" href="<?php echo esc_url( add_query_arg( 'step', $p['is_active'] ? 3 : 2, $wizard_url ) ); ?>"><?php echo esc_html( $p['is_active'] ? __( 'Configure in wizard', 'scalyn-mail-relay' ) : __( 'Select in wizard', 'scalyn-mail-relay' ) ); ?></a>
									<?php
									if ( $p['is_active'] && $p['configured'] ) :
										?>
										<a class="button" href="<?php echo esc_url( add_query_arg( 'step', 4, $wizard_url ) ); ?>"><?php esc_html_e( 'Verify connection', 'scalyn-mail-relay' ); ?></a><?php endif; ?>
								<?php endif; ?>
							</div>
						</section>
					<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<details class="scalyn-provider-evidence scalyn-disclosure"><summary><?php esc_html_e( 'What these statuses mean', 'scalyn-mail-relay' ); ?></summary>
		<p><?php esc_html_e( 'Saved settings are not proof of authorization or delivery. Verification records a successful check or accepted send, not live availability or the latest attempt. SendGrid sandbox verification does not prove real-send permission. Provider acceptance never guarantees inbox placement.', 'scalyn-mail-relay' ); ?></p>
	</details>
		<aside class="scalyn-card" aria-labelledby="scalyn-provider-help"><h2 id="scalyn-provider-help"><?php esc_html_e( 'Before you switch providers', 'scalyn-mail-relay' ); ?></h2>
			<ol><li><?php esc_html_e( 'Prepare the provider credentials and an authorized sender.', 'scalyn-mail-relay' ); ?></li><li><?php esc_html_e( 'Use the wizard to select and configure the sending route. Selection takes effect immediately.', 'scalyn-mail-relay' ); ?></li><li><?php esc_html_e( 'Verify the connection, send a test to an inbox you control, and check receipt.', 'scalyn-mail-relay' ); ?></li></ol>
			<p><?php esc_html_e( 'Provider settings are managed in the Setup Wizard. Select the provider in step 2 before configuring it in step 3. Changing settings for the active provider affects site email.', 'scalyn-mail-relay' ); ?></p>
		</aside>
</div>
