<?php
/**
 * Dashboard view.
 *
 * Variables injected by DashboardPage::render():
 *   bool       $provider_configured  Whether a provider is configured and registered.
 *   bool       $test_email_accepted Whether the setup wizard recorded provider acceptance of a test email.
 *   array|null $latest_log           Most recent mail log row, or null when no records exist.
 *   string     $timeline_url         Validated timeline URL, or '' when no valid UUID exists.
 *   int|null   $health_score         Overall health score (0-100) or null if no results.
 *   string     $health_ui_status     UI status for health (healthy, warning, critical, unknown).
 *   string     $health_ui_label      Formatted health label (e.g., "80/100" or "Unknown").
 *   array      $health_components    Component label => score (0-100) or null when not evaluated.
 *   string     $health_summary       HealthScorer summary of what the score is based on, or ''.
 *   bool       $has_completed_diagnostics Whether at least one health snapshot exists.
 *   string     $test_email_url       Setup Wizard URL for the test-email step.
 *
 * Privacy: Do not render response_message, event_data, recipient, subject, body,
 * credentials, raw SMTP transcripts, or unrestricted provider error metadata.
 * Accepted status is not delivery evidence and must not be presented as delivered.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\EmptyState;
use Scalyn\MailRelay\Admin\Components\HealthScoreBreakdown;
use Scalyn\MailRelay\Admin\Components\SetupStepIndicator;
use Scalyn\MailRelay\Admin\Components\StatusBadge;

defined( 'ABSPATH' ) || exit;

$wizard_url           = admin_url( 'admin.php?page=scalyn-mail-relay-wizard' );
$logs_url             = admin_url( 'admin.php?page=scalyn-mail-relay-logs' );
$diagnostics_page_url = admin_url( 'admin.php?page=scalyn-mail-relay-diagnostics' );
$status_labels        = array(
	'accepted' => __( 'Accepted', 'scalyn-mail-relay' ),
	'failed'   => __( 'Failed', 'scalyn-mail-relay' ),
);

$setup_steps = array(
	array(
		'label'  => __( 'Configure mail provider', 'scalyn-mail-relay' ),
		'status' => $provider_configured ? 'complete' : 'pending',
	),
	array(
		'label'  => __( 'Verify connection', 'scalyn-mail-relay' ),
		'status' => $provider_verified ? 'complete' : 'pending',
	),
	array(
		'label'  => __( 'Send test email', 'scalyn-mail-relay' ),
		'status' => $test_email_accepted ? 'complete' : 'pending',
	),
	array(
		'label'  => __( 'Verify SPF, DKIM and DMARC', 'scalyn-mail-relay' ),
		'status' => $has_completed_diagnostics ? 'complete' : 'pending',
	),
	array(
		'label'  => __( 'Run diagnostics and health check', 'scalyn-mail-relay' ),
		'status' => $has_completed_diagnostics ? 'complete' : 'pending',
	),
);
?>
<div class="wrap scalyn-mail-relay scalyn-dashboard">
	<h1><?php esc_html_e( 'Scalyn Mail Relay', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Email delivery, diagnostics, monitoring and remediation.', 'scalyn-mail-relay' ); ?></p>
	<?php require SCALYN_MAIL_RELAY_PATH . 'admin/views/dashboard-actions.php'; ?>
	<?php require SCALYN_MAIL_RELAY_PATH . 'admin/views/activity-summary.php'; ?>

	<div class="scalyn-grid">

		<section class="scalyn-card" aria-labelledby="scalyn-health-heading">
			<h2 id="scalyn-health-heading"><?php esc_html_e( 'Email Health — configuration score', 'scalyn-mail-relay' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configuration checks only. Actual message authentication and inbox placement are not verified.', 'scalyn-mail-relay' ); ?></p>
			<?php if ( null === $health_score ) : ?>
				<strong class="scalyn-score" aria-label="<?php esc_attr_e( 'Health score not yet assessed', 'scalyn-mail-relay' ); ?>">—</strong>
				<?php StatusBadge::render( 'unknown', $health_ui_label ); ?>
				<p class="scalyn-card__note"><?php esc_html_e( 'Run the initial diagnostics after configuring a provider to generate your first health score.', 'scalyn-mail-relay' ); ?></p>
			<?php else : ?>
				<?php /* translators: %d is the numeric health score out of 100 */ ?>
				<strong class="scalyn-score" aria-label="<?php echo esc_attr( sprintf( __( 'Configuration score: %d out of 100; authentication and delivery not verified', 'scalyn-mail-relay' ), $health_score ) ); ?>"><?php echo esc_html( $health_score ); ?></strong>
				<?php StatusBadge::render( $health_ui_status, $health_ui_label ); ?>
				<progress class="scalyn-health-meter" max="100" value="<?php echo esc_attr( (string) $health_score ); ?>" aria-label="<?php esc_attr_e( 'Configuration score', 'scalyn-mail-relay' ); ?>"><?php echo esc_html( $health_score ); ?></progress>
				<details class="scalyn-disclosure"><summary><?php esc_html_e( 'Score breakdown', 'scalyn-mail-relay' ); ?></summary>
					<?php HealthScoreBreakdown::render( $health_components, $health_summary ); ?>
				</details>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Displayed health snapshot:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $score_freshness ); ?></p>
			<details class="scalyn-disclosure"><summary><?php esc_html_e( 'About evidence freshness', 'scalyn-mail-relay' ); ?></summary>
				<p><?php esc_html_e( 'Evidence is stale after the configured cadence plus five minutes, or 24 hours plus five minutes when monitoring is disabled.', 'scalyn-mail-relay' ); ?></p>
			</details>
		</section>
		<?php require SCALYN_MAIL_RELAY_PATH . 'admin/views/monitoring-status.php'; ?>

		<section class="scalyn-card" aria-labelledby="scalyn-provider-heading">
			<h2 id="scalyn-provider-heading"><?php esc_html_e( 'Mail Provider', 'scalyn-mail-relay' ); ?></h2>
			<?php if ( $provider_configured ) : ?>
				<?php StatusBadge::render( 'connected', __( 'Configured', 'scalyn-mail-relay' ) ); ?>
				<dl>
					<dt><?php esc_html_e( 'Active provider', 'scalyn-mail-relay' ); ?></dt>
					<dd><?php echo esc_html( $active_provider_label ); ?></dd>
					<dt><?php esc_html_e( 'Transport', 'scalyn-mail-relay' ); ?></dt>
					<dd><?php echo esc_html( $provider_transport ); ?></dd>
					<dt><?php esc_html_e( 'Verification', 'scalyn-mail-relay' ); ?></dt>
					<dd><?php echo esc_html( $provider_verified ? __( 'Successful verification recorded', 'scalyn-mail-relay' ) : __( 'Not verified for the current configuration', 'scalyn-mail-relay' ) ); ?></dd>
					<?php if ( $provider_verified ) : ?>
						<dt><?php esc_html_e( 'Last successful verification (site time)', 'scalyn-mail-relay' ); ?></dt>
						<dd><?php echo esc_html( $provider_verified_at ); ?></dd>
					<?php endif; ?>
				</dl>
				<p class="description"><?php esc_html_e( 'Verification records a successful connection check or accepted send, not current availability or inbox delivery. It is not the latest attempt result; later failures may exist.', 'scalyn-mail-relay' ); ?></p>
				<?php if ( 'sendgrid' === $active_provider_id ) : ?>
					<p class="description"><?php esc_html_e( 'SendGrid connection checks use sandbox mode and do not prove real-send permission.', 'scalyn-mail-relay' ); ?></p>
				<?php endif; ?>
				<div class="scalyn-actions">
					<?php if ( current_user_can( \Scalyn\MailRelay\Core\Capabilities::MANAGE_MAIL ) ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-providers' ) ); ?>"><?php esc_html_e( 'Manage provider', 'scalyn-mail-relay' ); ?></a>
					<?php endif; ?>
					<?php if ( current_user_can( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS ) ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'step', 4, $wizard_url ) ); ?>"><?php esc_html_e( 'Verify connection', 'scalyn-mail-relay' ); ?></a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php StatusBadge::render( 'disconnected', __( 'Not configured', 'scalyn-mail-relay' ) ); ?>
				<p class="scalyn-card__note"><?php esc_html_e( 'No mail provider has been configured. Use the Setup Wizard to get started.', 'scalyn-mail-relay' ); ?></p>
				<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary"><?php esc_html_e( 'Start Setup Wizard', 'scalyn-mail-relay' ); ?></a>
			<?php endif; ?>
		</section>

		<section class="scalyn-card" aria-labelledby="scalyn-activity-heading">
			<h2 id="scalyn-activity-heading"><?php esc_html_e( 'Recent Email Activity', 'scalyn-mail-relay' ); ?></h2>
			<?php if ( null === $latest_log ) : ?>
				<?php
				EmptyState::render(
					__( 'No email activity has been recorded yet. Send a test email after configuring a provider.', 'scalyn-mail-relay' ),
					__( 'View Email Logs', 'scalyn-mail-relay' ),
					$logs_url
				);
				?>
			<?php else : ?>
				<?php
				$log_status   = (string) ( $latest_log['status'] ?? '' );
				$log_label    = $status_labels[ $log_status ] ?? ucfirst( $log_status );
				$log_provider = (string) ( $latest_log['provider'] ?? '' );
				$log_src_type = (string) ( $latest_log['source_type'] ?? '' );
				$log_src_name = (string) ( $latest_log['source_name'] ?? '' );
				$log_created  = (string) ( $latest_log['created_at'] ?? '' );

				$source_display = '' !== $log_src_type ? $log_src_type : '';
				if ( '' !== $log_src_name ) {
					$source_display = '' !== $source_display ? $source_display . ' / ' . $log_src_name : $log_src_name;
				}
				?>
				<dl class="scalyn-log-summary">
					<dt><?php esc_html_e( 'Status', 'scalyn-mail-relay' ); ?></dt>
					<dd><?php StatusBadge::render( $log_status, $log_label ); ?></dd>
					<?php if ( '' !== $log_provider ) : ?>
						<dt><?php esc_html_e( 'Provider', 'scalyn-mail-relay' ); ?></dt>
						<dd><?php echo esc_html( $log_provider ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $source_display ) : ?>
						<dt><?php esc_html_e( 'Source', 'scalyn-mail-relay' ); ?></dt>
						<dd><?php echo esc_html( $source_display ); ?></dd>
					<?php endif; ?>
					<?php if ( '' !== $log_created ) : ?>
						<dt><?php esc_html_e( 'Date', 'scalyn-mail-relay' ); ?></dt>
						<dd>
							<?php
							$timestamp = strtotime( $log_created );
							if ( false !== $timestamp ) {
								$date_fmt = get_option( 'date_format' );
								$time_fmt = get_option( 'time_format' );
								$format   = ( $date_fmt && $time_fmt ) ? $date_fmt . ' ' . $time_fmt : 'Y-m-d H:i:s';
								echo '<time datetime="' . esc_attr( wp_date( 'c', $timestamp ) ) . '">';
								echo esc_html( wp_date( $format, $timestamp ) );
								echo '</time>';
							} else {
								echo '<span>—</span>';
							}
							?>
						</dd>
					<?php endif; ?>
				</dl>
				<?php if ( 'accepted' === $log_status ) : ?>
					<p class="description scalyn-log-note">
						<?php esc_html_e( 'Accepted means the configured provider acknowledged the message. Accepted does not guarantee inbox delivery.', 'scalyn-mail-relay' ); ?>
					</p>
				<?php endif; ?>
				<div class="scalyn-activity-links">
					<?php if ( '' !== $timeline_url ) : ?>
						<a href="<?php echo esc_url( $timeline_url ); ?>" class="button button-small">
							<?php esc_html_e( 'View Timeline', 'scalyn-mail-relay' ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( $logs_url ); ?>" class="button button-small">
						<?php esc_html_e( 'View All Logs', 'scalyn-mail-relay' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</section>

		<section class="scalyn-card" aria-labelledby="scalyn-progress-heading">
			<h2 id="scalyn-progress-heading"><?php esc_html_e( 'Setup Progress', 'scalyn-mail-relay' ); ?></h2>
			<?php SetupStepIndicator::render( $setup_steps ); ?>
		</section>

	</div>

	<?php \Scalyn\MailRelay\Admin\Components\VerificationScope::render(); ?>

</div>
