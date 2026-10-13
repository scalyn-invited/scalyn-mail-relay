<?php
/** Recovery controls, public variables from RecoveryPanel only.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card" id="scalyn-recovery">
	<h2><?php esc_html_e( 'Email recovery', 'scalyn-mail-relay' ); ?></h2>
	<p><span class="scalyn-badge"><?php echo esc_html( $policy['enabled'] ? __( 'Enabled', 'scalyn-mail-relay' ) : __( 'Disabled by default', 'scalyn-mail-relay' ) ); ?></span></p>
	<?php
	if ( $notice ) :
		?>
		<p role="status"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
	<?php
	if ( $unavailable ) :
		?>
		<p class="notice notice-warning"><?php esc_html_e( 'Recovery storage is unavailable or not upgraded. An empty history does not mean there are no queued messages.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
	<p><?php esc_html_e( 'When enabled, WordPress mail returns success after this plugin durably queues the message. Queued is not Accepted or Delivered. Messages are submitted later by the worker. Setup Wizard test sends remain synchronous.', 'scalyn-mail-relay' ); ?></p>
	<p><?php esc_html_e( 'Only proven transient nonacceptance can retry: at most three submissions, with delays of at least one then five minutes. Routes and revisions are pinned. Accepted, permanent, partial and unconfirmed outcomes stop. Needs review must be checked in provider activity before any separate resend.', 'scalyn-mail-relay' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'scalyn_recovery' ); ?>
		<input type="hidden" name="action" value="scalyn_recovery">
		<input type="hidden" name="operation" value="policy">
		<input type="hidden" name="policy_token" value="<?php echo esc_attr( $policy['token'] ); ?>">
		<fieldset <?php disabled( \Scalyn\MailRelay\Agency\ManagedAccess::is_locked( 'critical' ) ); ?>>
			<legend><?php esc_html_e( 'Recovery and temporary storage', 'scalyn-mail-relay' ); ?></legend>
			<p><label><input type="checkbox" name="recovery_enabled" value="1" <?php checked( true, $policy['enabled'] ); ?>> <?php esc_html_e( 'Enable asynchronous email recovery', 'scalyn-mail-relay' ); ?></label></p>
			<p><label><input type="checkbox" name="storage_consent" value="1"> <?php esc_html_e( 'I consent to encrypted temporary storage of message bodies, recipients, headers and attachment copies, expiring within 24 hours. I understand the changed WordPress success behavior.', 'scalyn-mail-relay' ); ?></label></p>
			<p><?php esc_html_e( 'Consent is required each time you enable or save enabled recovery. Disabling pauses pending sends; it does not recall submissions already in progress. Cleanup continues.', 'scalyn-mail-relay' ); ?></p>
			<button class="button button-primary"><?php esc_html_e( 'Save recovery preference', 'scalyn-mail-relay' ); ?></button>
		</fieldset>
	</form>
	<p><?php esc_html_e( 'Maximum 5 MiB of body and attachments per message, ten attachments, 100 retained payloads and 20 MiB encrypted storage. New ownership fails safely when capacity or encryption is unavailable. No plaintext fallback.', 'scalyn-mail-relay' ); ?></p>
	<p><?php esc_html_e( 'WordPress cron depends on traffic. Expired content is never sent; physical cleanup runs on the next worker or retention tick. Use reliable system cron if deletion must occur by a strict wall-clock deadline.', 'scalyn-mail-relay' ); ?></p>
	<p><?php echo esc_html( wp_next_scheduled( \Scalyn\MailRelay\Core\ScheduledHooks::RECOVERY ) ? __( 'A worker tick is scheduled; this is not proof it ran.', 'scalyn-mail-relay' ) : __( 'No worker tick is scheduled. Check WordPress cron.', 'scalyn-mail-relay' ) ); ?></p>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-providers#email-routing' ) ); ?>"><?php esc_html_e( 'Manage primary and alternate routes', 'scalyn-mail-relay' ); ?></a></p>
</section>
<section class="scalyn-card">
	<h2><?php esc_html_e( 'Recent recovery work', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Most recent 50 logical messages. Content and recipients are never displayed here. Times are UTC. Review is not an invitation to retry automatically.', 'scalyn-mail-relay' ); ?></p>
	<?php
	if ( array() === $rows && ! $unavailable ) :
		?>
		<p><?php esc_html_e( 'No retained recovery work. Recovery does not backfill earlier mail.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
	<div class="scalyn-table-scroll"><table class="widefat striped">
		<thead><tr><th scope="col"><?php esc_html_e( 'Logical message', 'scalyn-mail-relay' ); ?></th><th scope="col"><?php esc_html_e( 'State', 'scalyn-mail-relay' ); ?></th><th scope="col"><?php esc_html_e( 'Submissions', 'scalyn-mail-relay' ); ?></th><th scope="col"><?php esc_html_e( 'Next eligible / expiry (UTC)', 'scalyn-mail-relay' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'scalyn-mail-relay' ); ?></th></tr></thead>
		<tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr>
			<td><code><?php echo esc_html( $row['logical_uuid'] ); ?></code></td>
			<td><?php echo esc_html( $labels[ $row['state'] ] ?? __( 'Unknown — needs review', 'scalyn-mail-relay' ) ); ?><br><small><?php echo esc_html( $row['reason'] ); ?></small></td>
			<td><?php echo esc_html( (string) $row['attempt_count'] ); ?> / 3</td>
			<td><?php echo esc_html( $row['next_at'] . ' / ' . $row['expires_at'] ); ?></td>
			<td><a class="button" href="<?php echo esc_url( add_query_arg( 'recovery_message', $row['logical_uuid'], admin_url( 'admin.php?page=scalyn-mail-relay-alerts' ) ) . '#scalyn-submission-history' ); ?>"><?php esc_html_e( 'Attempts', 'scalyn-mail-relay' ); ?></a>
			<?php if ( in_array( $row['state'], array( 'pending', 'running', 'review' ), true ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scalyn_recovery' ); ?><input type="hidden" name="action" value="scalyn_recovery"><input type="hidden" name="operation" value="cancel"><input type="hidden" name="logical_uuid" value="<?php echo esc_attr( $row['logical_uuid'] ); ?>"><button class="button" <?php disabled( \Scalyn\MailRelay\Agency\ManagedAccess::is_locked( 'critical' ) ); ?>><?php esc_html_e( 'Cancel and erase', 'scalyn-mail-relay' ); ?></button>
			</form><?php endif; ?></td>
		</tr><?php endforeach; ?></tbody>
	</table></div>
</section>
<?php if ( array() !== $history ) : ?>
<section class="scalyn-card" id="scalyn-submission-history">
	<h2><?php esc_html_e( 'Submission attempts', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Prepared is not confirmed acceptance or proof of failure. Every submission has a distinct attempt identity. Any collected delivery evidence belongs only to that exact attempt.', 'scalyn-mail-relay' ); ?></p>
	<ol>
	<?php
	foreach ( $history as $attempt ) :
		?>
		<li>
		<p><?php echo esc_html( $attempt['provider'] . ' — ' . $attempt['status'] . ' — ' . $attempt['created_at'] . ' UTC' ); ?></p>
		<p><code><?php echo esc_html( $attempt['configuration_id'] ?? __( 'Revision unknown', 'scalyn-mail-relay' ) ); ?></code></p>
		<a href="<?php echo esc_url( add_query_arg( 'message_uuid', $attempt['attempt_uuid'], admin_url( 'admin.php?page=scalyn-mail-relay-logs' ) ) ); ?>"><?php esc_html_e( 'View attempt timeline', 'scalyn-mail-relay' ); ?></a>
	</li><?php endforeach; ?></ol>
</section>
<?php endif; ?>
