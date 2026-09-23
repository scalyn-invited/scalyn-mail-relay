<?php
/**
 * Retained activity summary. Receives $activity_summary from the presenter.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card" aria-labelledby="scalyn-activity-totals-heading">
	<h2 id="scalyn-activity-totals-heading"><?php esc_html_e( 'Email activity — last 7 days', 'scalyn-mail-relay' ); ?></h2>
	<?php if ( ! $activity_summary['available'] ) : ?>
		<p role="status"><?php esc_html_e( 'Activity totals are unavailable. Refresh to retry; unavailable data is not zero activity.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'All providers. Period (site time, end exclusive):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $activity_summary['start'] . ' – ' . $activity_summary['end'] ); ?></p>
		<dl>
			<dt><?php esc_html_e( 'Accepted', 'scalyn-mail-relay' ); ?></dt><dd data-scalyn-count="accepted"><?php echo esc_html( $activity_summary['accepted'] ); ?></dd>
			<dt><?php esc_html_e( 'Failed', 'scalyn-mail-relay' ); ?></dt><dd data-scalyn-count="failed"><?php echo esc_html( $activity_summary['failed'] ); ?></dd>
			<dt><?php esc_html_e( 'Other recorded statuses', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $activity_summary['other'] ); ?></dd>
			<dt><?php esc_html_e( 'Total retained records', 'scalyn-mail-relay' ); ?></dt><dd><?php echo esc_html( $activity_summary['total'] ); ?></dd>
		</dl>
		<?php if ( 0 === $activity_summary['total'] ) : ?>
			<p><?php esc_html_e( 'No retained activity in this period. This does not establish email health.', 'scalyn-mail-relay' ); ?></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Counts read as of (site time):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $activity_summary['end'] ); ?>. <?php esc_html_e( 'Refresh this page for updated counts; this is not live monitoring.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Accepted means the configured provider acknowledged submission, not recipient acceptance or inbox delivery. Failed means the recorded send attempt failed; later bounces are not automatically included.', 'scalyn-mail-relay' ); ?></p>
	<p><?php esc_html_e( 'Counts use retained records by creation time and their current status. Retention may remove evidence. Other statuses are not confirmed successes. Health-snapshot freshness is reported separately above.', 'scalyn-mail-relay' ); ?></p>
</section>
