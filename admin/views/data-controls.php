<?php
/**
 * Data controls view. Values supplied by DataControlsPage.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Data Controls', 'scalyn-mail-relay' ); ?></h1>
	<?php if ( '' !== $notice ) : ?>
		<div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?>" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-data-controls' ) ); ?>">
		<?php wp_nonce_field( 'scalyn_retention_settings' ); ?>
		<h2><?php esc_html_e( 'Operational history retention', 'scalyn-mail-relay' ); ?></h2>
		<p><label for="retention-days"><?php esc_html_e( 'Keep history for this many days', 'scalyn-mail-relay' ); ?></label></p>
		<input id="retention-days" name="retention_days" type="number" min="1" max="3650" step="1" required value="<?php echo esc_attr( (string) $days ); ?>" aria-describedby="retention-help" />
		<p id="retention-help"><?php esc_html_e( 'Choose 1–3650 days (default 30). This applies to email logs with their entire timelines, complete diagnostic runs, health snapshots and audit history. Cleanup runs hourly in batches of up to 100 messages, 100 diagnostic runs, 100 snapshots and 100 audit rows. Records exactly at the cutoff remain. A diagnostic run remains until all its results expire. Audit records expire individually, including actor attribution. Existing orphan timeline rows and alerts are outside this policy.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'Reducing the period makes older history eligible for permanent deletion at the next cleanup. Increasing it cannot restore deleted history. Export or back up history before reducing the period.', 'scalyn-mail-relay' ); ?></p>
		<h2><?php esc_html_e( 'Scheduled diagnostics', 'scalyn-mail-relay' ); ?></h2>
		<p><label for="diagnostic-schedule"><?php esc_html_e( 'Run diagnostics automatically', 'scalyn-mail-relay' ); ?></label></p>
		<select id="diagnostic-schedule" name="diagnostic_schedule" aria-describedby="diagnostic-schedule-help">
			<?php
			foreach ( array(
				'disabled'   => __( 'Disabled', 'scalyn-mail-relay' ),
				'hourly'     => __( 'Hourly', 'scalyn-mail-relay' ),
				'twicedaily' => __( 'Twice daily', 'scalyn-mail-relay' ),
				'daily'      => __( 'Daily', 'scalyn-mail-relay' ),
			) as $value => $label ) :
				?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php echo $cadence === $value ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p id="diagnostic-schedule-help"><?php esc_html_e( 'Off by default. Each tick attempts one run; overlapping manual or scheduled runs are skipped without catch-up. Checks perform DNS and SMTP connectivity probes, not test-email sending. A run allows at most 20 checks and a 20-second cooperative execution budget; an active network call cannot be forcibly interrupted. WP-Cron depends on site traffic; low-traffic sites need server-triggered cron. Changing cadence starts a new interval.', 'scalyn-mail-relay' ); ?></p>
		<h2><?php esc_html_e( 'Uninstall policy', 'scalyn-mail-relay' ); ?></h2>
		<p><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php echo $delete ? 'checked' : ''; ?> aria-describedby="uninstall-help" /> <?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled', 'scalyn-mail-relay' ); ?></label></p>
		<p id="uninstall-help"><?php esc_html_e( 'Default: retain data. Deactivation always preserves data and stops scheduled work. If enabled, uninstall permanently removes all plugin tables, settings, credentials, logs, timelines, diagnostics, health snapshots, alerts, audit history and plugin capabilities for this site. Recovery requires a backup. Multisite lifecycle is not supported.', 'scalyn-mail-relay' ); ?></p>
		<?php if ( ! $delete ) : ?>
			<p><label><input type="checkbox" name="confirm_delete" value="1" /> <?php esc_html_e( 'I understand that uninstall will permanently delete this site’s plugin data and confirm enabling this policy.', 'scalyn-mail-relay' ); ?></label></p>
		<?php endif; ?>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save data controls', 'scalyn-mail-relay' ); ?></button></p>
	</form>
	<h2><?php esc_html_e( 'Cleanup status', 'scalyn-mail-relay' ); ?></h2>
	<p><?php echo esc_html( $labels[ $status['state'] ] ); ?></p>
	<p><?php esc_html_e( 'Next scheduled run (UTC):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $next ? gmdate( 'Y-m-d H:i:s', $next ) : __( 'Not scheduled. Reload this page; if this persists, check WP-Cron scheduling.', 'scalyn-mail-relay' ) ); ?></p>
	<?php if ( $next && $next < time() - 3600 ) : ?>
		<p role="status"><?php esc_html_e( 'Cleanup is overdue. Check WP-Cron or your server cron configuration.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Last successful batch (UTC):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $status['last_success_at'] ? gmdate( 'Y-m-d H:i:s', $status['last_success_at'] ) : __( 'None recorded', 'scalyn-mail-relay' ) ); ?></p>
	<p><?php esc_html_e( 'Rows removed in the last attempt — email logs / timeline events / diagnostic results / health snapshots / audit records:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( implode( ' / ', array( $status['mail_logs'], $status['timeline_events'], $status['diagnostic_rows'], $status['health_scores'], $status['audit_rows'] ) ) ); ?></p>
	<p><?php esc_html_e( 'WordPress cron depends on site traffic. Low-traffic sites or sites with WP-Cron disabled need a server cron job to trigger due WordPress events. Large backlogs drain over multiple hourly runs. Stored timestamps use the site timezone; changing it can affect historical expiry boundaries.', 'scalyn-mail-relay' ); ?></p>
</div>
