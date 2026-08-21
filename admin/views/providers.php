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
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Providers', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Registered mail transport providers.', 'scalyn-mail-relay' ); ?></p>

	<?php if ( empty( $providers ) ) : ?>
		<div class="scalyn-card">
			<h2><?php esc_html_e( 'No Providers Registered', 'scalyn-mail-relay' ); ?></h2>
			<p><?php esc_html_e( 'No mail provider modules are currently registered.', 'scalyn-mail-relay' ); ?></p>
			<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Open Setup Wizard', 'scalyn-mail-relay' ); ?>
			</a>
		</div>
	<?php else : ?>
		<div class="scalyn-card">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Provider', 'scalyn-mail-relay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ID', 'scalyn-mail-relay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'scalyn-mail-relay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'scalyn-mail-relay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $providers as $p ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $p['label'] ); ?></strong></td>
							<td><code><?php echo esc_html( $p['id'] ); ?></code></td>
							<td>
								<?php if ( $p['configured'] ) : ?>
									<span class="scalyn-badge scalyn-badge--connected"><?php esc_html_e( 'Configured', 'scalyn-mail-relay' ); ?></span>
								<?php else : ?>
									<span class="scalyn-badge scalyn-badge--disconnected"><?php esc_html_e( 'Not configured', 'scalyn-mail-relay' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Configure via Wizard', 'scalyn-mail-relay' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
