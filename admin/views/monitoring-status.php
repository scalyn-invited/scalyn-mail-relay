<?php
/**
 * Escaped read-only monitoring panel, included after page capability checks.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card" aria-labelledby="scalyn-monitoring-heading">
	<h2 id="scalyn-monitoring-heading"><?php esc_html_e( 'Scheduled health monitoring', 'scalyn-mail-relay' ); ?></h2>
	<?php if ( $monitoring['unavailable'] ) : ?>
		<p role="status"><?php esc_html_e( 'Monitoring status is unavailable. Check database availability and try again.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<dl>
			<dt><?php esc_html_e( 'Cadence', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['cadence'] ); ?></dd>
			<dt><?php esc_html_e( 'Schedule', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['schedule'] ); ?></dd>
			<dt><?php esc_html_e( 'Scheduled freshness', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['freshness'] ); ?></dd>
			<dt><?php esc_html_e( 'Next event (UTC)', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['next'] ? gmdate( 'Y-m-d H:i:s', $monitoring['next'] ) : __( 'None', 'scalyn-mail-relay' ) ); ?></dd>
			<dt><?php esc_html_e( 'Last scheduled completion (UTC)', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['last_success'] ? gmdate( 'Y-m-d H:i:s', $monitoring['last_success'] ) : __( 'None recorded', 'scalyn-mail-relay' ) ); ?></dd>
			<dt><?php esc_html_e( 'Latest scheduled attempt', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['scheduled'] ); ?></dd>
			<dt><?php esc_html_e( 'Latest attempt (any source)', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $monitoring['latest'] ); ?></dd>
		</dl>
		<?php if ( $monitoring['unfinished'] ) : ?>
			<p><?php esc_html_e( 'An earlier attempt has no confirmed completion. This does not prove it failed.', 'scalyn-mail-relay' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<p><?php esc_html_e( 'Overdue and stale warnings allow five minutes of grace. Manual runs do not refresh scheduled monitoring. Execution status may outlive retained evidence; it is not an email-delivery guarantee.', 'scalyn-mail-relay' ); ?></p>
	<p><?php esc_html_e( 'WP-Cron relies on site traffic. Low-traffic sites need an external cron trigger. A disabled automatic trigger requires server cron; otherwise scheduled work will not run.', 'scalyn-mail-relay' ); ?></p>
	<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
		<p><?php esc_html_e( 'Traffic-triggered WP-Cron is disabled on this site. Verify that a server cron job calls WordPress cron.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
	<?php if ( current_user_can( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS ) ) : ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-data-controls' ) ); ?>"><?php esc_html_e( 'Configure monitoring in Settings', 'scalyn-mail-relay' ); ?></a></p>
	<?php endif; ?>
</section>
