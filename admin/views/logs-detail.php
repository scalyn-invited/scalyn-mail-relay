<?php
/**
 * Email Logs detail / timeline view.
 *
 * Variables injected by LogsPage::render_detail():
 *   string     $message_uuid Validated UUID string, or '' when $uuid_error is true.
 *   bool       $uuid_error   True when the supplied UUID failed format validation.
 *   array|null $log          Mail log row as associative array, or null when not found.
 *   array      $timeline     Timeline events as associative arrays, oldest first.
 *
 * Privacy: Do not render $log['response_message'] without explicit Kim approval.
 *          Do not render event_data. Do not render subject, recipient, or body
 *          (these fields do not exist in the schema but are listed as reminder).
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\EmptyState;
use Scalyn\MailRelay\Admin\Components\StatusBadge;

defined( 'ABSPATH' ) || exit;

$logs_base_url = admin_url( 'admin.php?page=scalyn-mail-relay-logs' );

$status_labels = array(
	'accepted' => __( 'Accepted', 'scalyn-mail-relay' ),
	'failed'   => __( 'Failed', 'scalyn-mail-relay' ),
);
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Email Timeline', 'scalyn-mail-relay' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( $logs_base_url ); ?>" class="button">
			&larr; <?php esc_html_e( 'Back to Email Logs', 'scalyn-mail-relay' ); ?>
		</a>
	</p>

	<?php if ( $uuid_error ) : ?>

		<div class="scalyn-card scalyn-error-state">
			<h2><?php esc_html_e( 'Invalid Message ID', 'scalyn-mail-relay' ); ?></h2>
			<p><?php esc_html_e( 'The message ID format is not valid.', 'scalyn-mail-relay' ); ?></p>
			<p class="description">
				<?php esc_html_e( 'Valid message IDs are UUIDs in the format:', 'scalyn-mail-relay' ); ?><br />
				<code>xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx</code><br />
				<?php esc_html_e( '(where x is a hexadecimal digit 0-9, a-f)', 'scalyn-mail-relay' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'To find a specific email:', 'scalyn-mail-relay' ); ?>
			</p>
			<ol>
				<li><?php esc_html_e( 'Go to the Email Logs page', 'scalyn-mail-relay' ); ?></li>
				<li><?php esc_html_e( 'Find the message in the table', 'scalyn-mail-relay' ); ?></li>
				<li><?php esc_html_e( 'Click the "View Timeline" link', 'scalyn-mail-relay' ); ?></li>
			</ol>
			<p>
				<a href="<?php echo esc_url( $logs_base_url ); ?>" class="button">
					<?php esc_html_e( 'Back to Email Logs', 'scalyn-mail-relay' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( null === $log ) : ?>

		<div class="scalyn-card">
			<?php
			EmptyState::render(
				__( 'No log record was found for this message ID.', 'scalyn-mail-relay' )
			);
			?>
		</div>

	<?php else : ?>

		<?php
		$log_status   = (string) ( $log['status'] ?? '' );
		$log_label    = $status_labels[ $log_status ] ?? ucfirst( $log_status );
		$log_provider = (string) ( $log['provider'] ?? '' );
		$log_src_type = (string) ( $log['source_type'] ?? '' );
		$log_src_name = (string) ( $log['source_name'] ?? '' );
		$log_att_cnt  = (int) ( $log['attachment_count'] ?? 0 );
		$log_created  = (string) ( $log['created_at'] ?? '' );
		$log_sent     = (string) ( $log['sent_at'] ?? '' );
		$log_failed   = (string) ( $log['failed_at'] ?? '' );

		$source_display = '' !== $log_src_type ? $log_src_type : '';
		if ( '' !== $log_src_name ) {
			$source_display = '' !== $source_display ? $source_display . ' / ' . $log_src_name : $log_src_name;
		}
		if ( '' === $source_display ) {
			$source_display = '—';
		}
		?>

		<div class="scalyn-card">
			<h2><?php esc_html_e( 'Message Summary', 'scalyn-mail-relay' ); ?></h2>
			<table class="form-table scalyn-log-summary">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Message ID', 'scalyn-mail-relay' ); ?></th>
						<td><code><?php echo esc_html( $message_uuid ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'scalyn-mail-relay' ); ?></th>
						<td><?php StatusBadge::render( $log_status, $log_label ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Provider', 'scalyn-mail-relay' ); ?></th>
						<td><?php echo '' !== $log_provider ? esc_html( $log_provider ) : '<span>—</span>'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'scalyn-mail-relay' ); ?></th>
						<td><?php echo '—' === $source_display ? '<span>—</span>' : esc_html( $source_display ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attachments', 'scalyn-mail-relay' ); ?></th>
						<td><?php echo esc_html( (string) $log_att_cnt ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Created', 'scalyn-mail-relay' ); ?></th>
						<td>
							<?php if ( '' !== $log_created ) : ?>
								<time datetime="<?php echo esc_attr( $log_created ); ?>"><?php echo esc_html( $log_created ); ?></time>
							<?php else : ?>
								<span>—</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $log_sent ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Accepted at', 'scalyn-mail-relay' ); ?></th>
							<td>
								<time datetime="<?php echo esc_attr( $log_sent ); ?>"><?php echo esc_html( $log_sent ); ?></time>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== $log_failed ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Failed at', 'scalyn-mail-relay' ); ?></th>
							<td>
								<time datetime="<?php echo esc_attr( $log_failed ); ?>"><?php echo esc_html( $log_failed ); ?></time>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

	<?php endif; ?>

	<div class="scalyn-card">
		<h2><?php esc_html_e( 'Timeline', 'scalyn-mail-relay' ); ?></h2>

		<?php if ( empty( $timeline ) ) : ?>
			<?php
			EmptyState::render(
				__( 'No timeline events are available for this message.', 'scalyn-mail-relay' )
			);
			?>
		<?php else : ?>
			<ol class="scalyn-timeline" aria-label="<?php esc_attr_e( 'Message timeline', 'scalyn-mail-relay' ); ?>">
				<?php foreach ( $timeline as $event ) : ?>
					<?php
					$ev_status  = (string) ( $event['event_status'] ?? '' );
					$ev_label   = (string) ( $event['event_label'] ?? '' );
					$ev_message = (string) ( $event['event_message'] ?? '' );
					$ev_created = (string) ( $event['created_at'] ?? '' );
					$ev_ui_lbl  = $status_labels[ $ev_status ] ?? ucfirst( $ev_status );
					// event_data is deliberately not rendered.
					?>
					<li class="scalyn-timeline__event scalyn-timeline__event--<?php echo esc_attr( $ev_status ); ?>">
						<div class="scalyn-timeline__header">
							<strong class="scalyn-timeline__label"><?php echo esc_html( $ev_label ); ?></strong>
							<?php StatusBadge::render( $ev_status, $ev_ui_lbl ); ?>
							<?php if ( '' !== $ev_created ) : ?>
								<time class="scalyn-timeline__time" datetime="<?php echo esc_attr( $ev_created ); ?>">
									<?php echo esc_html( $ev_created ); ?>
								</time>
							<?php endif; ?>
						</div>
						<?php if ( '' !== $ev_message ) : ?>
							<p class="scalyn-timeline__message"><?php echo esc_html( $ev_message ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</div>

	<?php if ( isset( $log_status ) && 'accepted' === $log_status ) : ?>
		<p class="description scalyn-log-note">
			<?php esc_html_e( 'Accepted means the configured provider acknowledged the message. Accepted does not guarantee inbox delivery.', 'scalyn-mail-relay' ); ?>
		</p>
	<?php endif; ?>
</div>
