<?php
/**
 * Dashboard view.
 *
 * Variables injected by DashboardPage::render():
 *   bool $provider_configured  Whether a provider is configured and registered.
 *
 * Health score, activity statistics, and diagnostics results are not yet
 * available. Those sections use explicit empty state until Yaj's logging
 * and diagnostics REST contracts are implemented. Do not invent data.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\ActionButton;
use Scalyn\MailRelay\Admin\Components\EmptyState;
use Scalyn\MailRelay\Admin\Components\SetupStepIndicator;
use Scalyn\MailRelay\Admin\Components\StatusBadge;

defined( 'ABSPATH' ) || exit;

$wizard_url = admin_url( 'admin.php?page=scalyn-mail-relay-wizard' );
$logs_url   = admin_url( 'admin.php?page=scalyn-mail-relay-logs' );

$setup_steps = array(
	array(
		'label'  => __( 'Configure mail provider', 'scalyn-mail-relay' ),
		'status' => $provider_configured ? 'complete' : 'pending',
	),
	array(
		'label'  => __( 'Verify connection', 'scalyn-mail-relay' ),
		'status' => 'pending',
	),
	array(
		'label'  => __( 'Send test email', 'scalyn-mail-relay' ),
		'status' => 'pending',
	),
	array(
		'label'  => __( 'Verify SPF, DKIM and DMARC', 'scalyn-mail-relay' ),
		'status' => 'pending',
	),
	array(
		'label'  => __( 'Run diagnostics and health check', 'scalyn-mail-relay' ),
		'status' => 'pending',
	),
);
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Scalyn Mail Relay', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Email delivery, diagnostics, monitoring and remediation.', 'scalyn-mail-relay' ); ?></p>

	<div class="scalyn-grid">

		<section class="scalyn-card" aria-labelledby="scalyn-health-heading">
			<h2 id="scalyn-health-heading"><?php esc_html_e( 'Email Health', 'scalyn-mail-relay' ); ?></h2>
			<strong class="scalyn-score" aria-label="<?php esc_attr_e( 'Health score not yet assessed', 'scalyn-mail-relay' ); ?>">—</strong>
			<?php StatusBadge::render( 'unknown', __( 'Unknown', 'scalyn-mail-relay' ) ); ?>
			<p class="scalyn-card__note"><?php esc_html_e( 'Run the initial diagnostics after configuring a provider to generate your first health score.', 'scalyn-mail-relay' ); ?></p>
		</section>

		<section class="scalyn-card" aria-labelledby="scalyn-provider-heading">
			<h2 id="scalyn-provider-heading"><?php esc_html_e( 'Mail Provider', 'scalyn-mail-relay' ); ?></h2>
			<?php if ( $provider_configured ) : ?>
				<?php StatusBadge::render( 'connected', __( 'Configured', 'scalyn-mail-relay' ) ); ?>
			<?php else : ?>
				<?php StatusBadge::render( 'disconnected', __( 'Not configured', 'scalyn-mail-relay' ) ); ?>
				<p class="scalyn-card__note"><?php esc_html_e( 'No mail provider has been configured. Use the Setup Wizard to get started.', 'scalyn-mail-relay' ); ?></p>
				<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary"><?php esc_html_e( 'Start Setup Wizard', 'scalyn-mail-relay' ); ?></a>
			<?php endif; ?>
		</section>

		<section class="scalyn-card" aria-labelledby="scalyn-activity-heading">
			<h2 id="scalyn-activity-heading"><?php esc_html_e( 'Recent Email Activity', 'scalyn-mail-relay' ); ?></h2>
			<?php
			// Activity data awaits Yaj's logging module (includes/Logging/) and REST contracts.
			EmptyState::render(
				__( 'No email activity data is available yet. Send a test email after configuring a provider.', 'scalyn-mail-relay' )
			);
			?>
		</section>

		<section class="scalyn-card" aria-labelledby="scalyn-progress-heading">
			<h2 id="scalyn-progress-heading"><?php esc_html_e( 'Setup Progress', 'scalyn-mail-relay' ); ?></h2>
			<?php SetupStepIndicator::render( $setup_steps ); ?>
		</section>

	</div>

	<section class="scalyn-card scalyn-actions-card" aria-labelledby="scalyn-actions-heading">
		<h2 id="scalyn-actions-heading"><?php esc_html_e( 'Quick Actions', 'scalyn-mail-relay' ); ?></h2>
		<div class="scalyn-actions">
			<?php ActionButton::render( __( 'Configure Mailer', 'scalyn-mail-relay' ), $wizard_url ); ?>
			<?php ActionButton::render( __( 'Run Diagnostics', 'scalyn-mail-relay' ), '', true ); ?>
			<?php ActionButton::render( __( 'Send Test Email', 'scalyn-mail-relay' ), '', true ); ?>
			<?php ActionButton::render( __( 'View Logs', 'scalyn-mail-relay' ), $logs_url ); ?>
		</div>
		<p class="scalyn-actions__note description"><?php esc_html_e( 'Run Diagnostics and Send Test Email will be enabled after a mail provider is configured and verified.', 'scalyn-mail-relay' ); ?></p>
	</section>

</div>
