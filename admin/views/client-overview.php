<?php
/**
 * Client-safe health, site activity and support recommendation.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
$labels = array(
	'healthy'  => __( 'Healthy configuration', 'scalyn-mail-relay' ),
	'warning'  => __( 'Needs review', 'scalyn-mail-relay' ),
	'critical' => __( 'Action needed', 'scalyn-mail-relay' ),
	'unknown'  => __( 'Unknown', 'scalyn-mail-relay' ),
	'stale'    => __( 'Stale evidence', 'scalyn-mail-relay' ),
);
?>
<div class="wrap scalyn-mail-relay scalyn-client-overview">
	<h1><?php esc_html_e( 'Email Overview', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'A read-only summary of configuration health and retained site-wide email activity.', 'scalyn-mail-relay' ); ?></p>
	<div class="scalyn-grid">
		<section class="scalyn-card" aria-labelledby="scalyn-client-health">
			<h2 id="scalyn-client-health"><?php esc_html_e( 'Current configuration health', 'scalyn-mail-relay' ); ?></h2>
			<p class="scalyn-status-value"><?php echo esc_html( $labels[ $summary['health'] ] ); ?></p>
			<?php
			if ( null !== $summary['score'] ) :
				?>
				<strong class="scalyn-score"><?php echo esc_html( (string) $summary['score'] ); ?>/100</strong><?php endif; ?>
			<?php
			if ( null !== $summary['recorded_at'] ) :
				?>
				<p><?php esc_html_e( 'Recorded (site time):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $summary['recorded_at'] ); ?></p><?php endif; ?>
			<p><?php esc_html_e( 'This is a configuration-check score, not a delivery rate. Unknown or stale evidence is not a failure or a pass. Even 100/100 does not confirm inbox delivery.', 'scalyn-mail-relay' ); ?></p>
		</section>
		<section class="scalyn-card" aria-labelledby="scalyn-client-activity">
			<h2 id="scalyn-client-activity"><?php esc_html_e( 'Site-wide activity report — last seven days', 'scalyn-mail-relay' ); ?></h2>
			<?php if ( $summary['activity']['available'] ) : ?>
				<dl class="scalyn-metrics">
					<?php
					foreach ( array(
						'total'    => __( 'Total retained', 'scalyn-mail-relay' ),
						'accepted' => __( 'Accepted', 'scalyn-mail-relay' ),
						'failed'   => __( 'Failed', 'scalyn-mail-relay' ),
						'other'    => __( 'Other statuses', 'scalyn-mail-relay' ),
					) as $key => $label ) :
						?>
						<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( (string) $summary['activity'][ $key ] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<p><?php echo esc_html( $summary['activity']['start'] . ' — ' . $summary['activity']['end'] ); ?> <?php esc_html_e( '(site time, end exclusive)', 'scalyn-mail-relay' ); ?></p>
				<?php
			else :
				?>
				<p><?php esc_html_e( 'Activity data is unavailable. This does not mean zero messages.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
			<p><?php esc_html_e( 'Accepted means the sending provider acknowledged the message, not that it reached the inbox. Counts include all retained site configurations and are not a provider-health assessment.', 'scalyn-mail-relay' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scalyn_client_report">
				<?php wp_nonce_field( 'scalyn_client_report' ); ?>
				<?php submit_button( __( 'Download summary CSV', 'scalyn-mail-relay' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
	</div>
	<section class="scalyn-card" aria-labelledby="scalyn-client-next">
		<h2 id="scalyn-client-next"><?php esc_html_e( 'Recommended next step', 'scalyn-mail-relay' ); ?></h2>
		<p>
		<?php
		echo esc_html(
			match ( $summary['recommendation'] ) {
			'monitor' => __( 'Continue monitoring. Contact support if a recipient reports a missing message.', 'scalyn-mail-relay' ),
			'contact_support' => __( 'Ask your administrator or support contact to review the current configuration findings.', 'scalyn-mail-relay' ),
			default => __( 'Ask your administrator or support contact to run a fresh configuration health check.', 'scalyn-mail-relay' ),
			}
		);
		?>
		</p>
	</section>
</div>
