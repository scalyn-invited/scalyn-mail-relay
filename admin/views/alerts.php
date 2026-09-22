<?php
/**
 * Credential-free alert history.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Alerts & Recovery', 'scalyn-mail-relay' ); ?></h1>
	<?php if ( $notice ) : ?>
		<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Incidents are evaluated every five minutes when WordPress cron runs. Notifications use an independent webhook, not your email provider. A stopped website or cron runner cannot alert about its own outage; use an external uptime monitor.', 'scalyn-mail-relay' ); ?></p>
	<p><?php echo esc_html( $next ? __( 'An alert tick is scheduled. This is not proof that the cron runner is working.', 'scalyn-mail-relay' ) : __( 'No alert tick is scheduled. Check WordPress cron before relying on alerts.', 'scalyn-mail-relay' ) ); ?></p>
	<h2><?php esc_html_e( 'Webhook notifications', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Last alert evaluation:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $execution['state'] ); ?> — <?php echo esc_html( $execution['at'] ? gmdate( 'Y-m-d H:i:s', $execution['at'] ) . ' UTC' : __( 'not recorded', 'scalyn-mail-relay' ) ); ?></p>
	<?php if ( 'completed' !== $execution['state'] || $execution['at'] < time() - 900 ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Alert evaluation is unconfirmed, failed, or overdue. Check the database upgrade, InnoDB table support, and cron runner; an empty history does not prove the site is healthy.', 'scalyn-mail-relay' ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Set SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL and optionally SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN in protected server configuration. Only public HTTPS destinations are allowed. Values are never displayed here.', 'scalyn-mail-relay' ); ?></p>
	<p><?php echo esc_html( $configured ? __( 'Webhook configuration is present; connectivity has not been verified.', 'scalyn-mail-relay' ) : __( 'Webhook configuration is missing or invalid.', 'scalyn-mail-relay' ) ); ?></p>
	<form method="post">
		<?php wp_nonce_field( 'scalyn_alert_settings' ); ?>
		<label><input type="checkbox" name="alert_webhook_enabled" value="1" <?php checked( $enabled ); ?> /> <?php esc_html_e( 'Enable webhook notifications for new incident transitions', 'scalyn-mail-relay' ); ?></label>
		<?php submit_button( __( 'Save alert preference', 'scalyn-mail-relay' ) ); ?>
	</form>
	<p><?php esc_html_e( 'Disabled notifications are skipped, not replayed later. Failed HTTP attempts retry up to three times. A 2xx response means the webhook acknowledged the request, not that a person read it. Receivers should deduplicate by notification UUID. Recovery cancels an unsent opening notification.', 'scalyn-mail-relay' ); ?></p>
	<h2><?php esc_html_e( 'Incident rules', 'scalyn-mail-relay' ); ?></h2>
	<ul>
	<?php foreach ( \Scalyn\MailRelay\Alerts\IncidentRules::TYPES as $incident_type ) : ?>
		<li><?php echo esc_html( \Scalyn\MailRelay\Alerts\IncidentRules::description( $incident_type ) ); ?></li>
	<?php endforeach; ?>
	</ul>
	<p><?php esc_html_e( 'Missing or stale observations hold existing incidents open; disabling scheduled diagnostics does not resolve a monitoring incident. Health evidence expires after 24 hours. Reopened incidents have a 15-minute notification cooldown.', 'scalyn-mail-relay' ); ?></p>
	<h2><?php esc_html_e( 'Incident history (UTC)', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Active incidents are retained. Resolved incidents and their notifications expire together under the retention period configured in Settings.', 'scalyn-mail-relay' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th scope="col"><?php esc_html_e( 'Incident', 'scalyn-mail-relay' ); ?></th><th scope="col"><?php esc_html_e( 'Status / UTC times', 'scalyn-mail-relay' ); ?></th><th scope="col"><?php esc_html_e( 'Webhook attempts', 'scalyn-mail-relay' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $history['rows'] ) : ?>
			<tr><td colspan="3"><?php esc_html_e( 'No incidents recorded.', 'scalyn-mail-relay' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $history['rows'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['alert_type'] ); ?><br /><code><?php echo esc_html( $row['alert_uuid'] ); ?></code><p><?php echo esc_html( \Scalyn\MailRelay\Alerts\IncidentRules::description( $row['alert_type'], 'resolved' === $row['status'] ) ); ?></p></td>
				<td><?php echo esc_html( $row['status'] ); ?><br /><?php echo esc_html( $row['created_at'] ); ?><br /><?php echo esc_html( $row['resolved_at'] ); ?></td>
				<td>
				<?php
				foreach ( $row['notifications'] as $job ) :
					?>
					<p><?php echo esc_html( $job['event'] . ': ' . $job['status'] . ' — ' . $job['attempts'] . '/3; HTTP ' . $job['response_code'] ); ?></p><?php endforeach; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $history['next'] ) : ?>
		<p><a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page'   => 'scalyn-mail-relay-alerts',
					'before' => $history['next'],
				),
				admin_url( 'admin.php' )
			)
		);
		?>
					"><?php esc_html_e( 'Older incidents', 'scalyn-mail-relay' ); ?></a></p>
	<?php endif; ?>
</div>
