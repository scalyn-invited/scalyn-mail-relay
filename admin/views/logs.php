<?php
/**
 * Email Logs list view.
 *
 * Variables injected by LogsPage::render_list():
 *   array $rows          Log rows as associative arrays, newest first. May be empty.
 *   int   $page          Current page number (≥ 1).
 *   bool  $has_next_page Whether additional rows may exist beyond this page.
 *
 * Privacy: Do not add columns for recipient, subject, body, response_message,
 * or event_data. Only fields explicitly listed in this template are permitted.
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
	<h1><?php esc_html_e( 'Email Logs', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Recent email outcomes recorded by Scalyn Mail Relay.', 'scalyn-mail-relay' ); ?></p>

	<?php if ( ! empty( $rows ) ) : ?>
		<div class="scalyn-card scalyn-logs-filters">
			<form method="get" action="<?php echo esc_url( $logs_base_url ); ?>" class="scalyn-filter-form">
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Filter email logs', 'scalyn-mail-relay' ); ?></legend>
					<div class="scalyn-filter-controls">
						<div class="scalyn-filter-group">
							<label for="scalyn-filter-status" class="scalyn-filter-label">
								<?php esc_html_e( 'Status:', 'scalyn-mail-relay' ); ?>
							</label>
							<select id="scalyn-filter-status" name="status" class="scalyn-filter-select" aria-label="<?php esc_attr_e( 'Filter by status', 'scalyn-mail-relay' ); ?>">
								<option value=""><?php esc_html_e( 'All', 'scalyn-mail-relay' ); ?></option>
								<option value="accepted"><?php esc_html_e( 'Accepted', 'scalyn-mail-relay' ); ?></option>
								<option value="failed"><?php esc_html_e( 'Failed', 'scalyn-mail-relay' ); ?></option>
							</select>
						</div>
					</div>
					<button type="submit" class="button" aria-label="<?php esc_attr_e( 'Apply filters', 'scalyn-mail-relay' ); ?>">
						<?php esc_html_e( 'Filter', 'scalyn-mail-relay' ); ?>
					</button>
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET navigation; no state change. Value validated in LogsPage.
					if ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) :
						?>
						<a href="<?php echo esc_url( $logs_base_url ); ?>" class="button">
							<?php esc_html_e( 'Clear', 'scalyn-mail-relay' ); ?>
						</a>
					<?php endif; ?>
				</fieldset>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( empty( $rows ) ) : ?>

		<div class="scalyn-card">
			<?php
			EmptyState::render(
				__( 'No email activity has been recorded yet.', 'scalyn-mail-relay' )
			);
			?>
			<p class="scalyn-card__note description">
				<?php esc_html_e( 'Logs will appear here after Scalyn Mail Relay sends its first email.', 'scalyn-mail-relay' ); ?>
			</p>
		</div>

	<?php else : ?>

		<div class="scalyn-card">
			<div class="scalyn-log-table-wrapper">
				<table class="wp-list-table widefat fixed striped scalyn-log-table">
				<thead>
					<tr>
						<th scope="col" class="scalyn-log-col-status"><?php esc_html_e( 'Status', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-provider"><?php esc_html_e( 'Provider', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-source"><?php esc_html_e( 'Source', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-attachments"><?php esc_html_e( 'Attachments', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-timestamp"><?php esc_html_e( 'Timestamp', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-action"><?php esc_html_e( 'Timeline', 'scalyn-mail-relay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$row_status  = (string) ( $row['status'] ?? '' );
						$label       = $status_labels[ $row_status ] ?? ucfirst( $row_status );
						$provider    = (string) ( $row['provider'] ?? '' );
						$source_type = (string) ( $row['source_type'] ?? '' );
						$source_name = (string) ( $row['source_name'] ?? '' );
						$att_count   = (int) ( $row['attachment_count'] ?? 0 );
						$created_at  = (string) ( $row['created_at'] ?? '' );
						$uuid        = (string) ( $row['message_uuid'] ?? '' );

						$source_display = '' !== $source_type ? $source_type : '';
						if ( '' !== $source_name ) {
							$source_display = '' !== $source_display ? $source_display . ' / ' . $source_name : $source_name;
						}
						if ( '' === $source_display ) {
							$source_display = '—';
						}

						$timeline_url = add_query_arg(
							array( 'message_uuid' => $uuid ),
							$logs_base_url
						);
						?>
						<tr>
							<td class="scalyn-log-col-status">
								<?php StatusBadge::render( $row_status, $label ); ?>
							</td>
							<td class="scalyn-log-col-provider">
								<?php echo '' !== $provider ? esc_html( $provider ) : '<span aria-label="' . esc_attr__( 'Unknown provider', 'scalyn-mail-relay' ) . '">—</span>'; ?>
							</td>
							<td class="scalyn-log-col-source">
								<?php echo '—' === $source_display ? '<span>—</span>' : esc_html( $source_display ); ?>
							</td>
							<td class="scalyn-log-col-attachments">
								<?php echo esc_html( (string) $att_count ); ?>
							</td>
							<td class="scalyn-log-col-timestamp">
								<?php
								if ( ! empty( $created_at ) ) {
									$timestamp = strtotime( $created_at );
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
								} else {
									echo '<span>—</span>';
								}
								?>
							</td>
							<td class="scalyn-log-col-action">
								<?php if ( '' !== $uuid ) : ?>
									<a href="<?php echo esc_url( $timeline_url ); ?>" class="button button-small">
										<?php esc_html_e( 'View Timeline', 'scalyn-mail-relay' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<?php if ( $page > 1 || $has_next_page ) : ?>
				<div class="scalyn-log-pagination tablenav">
					<div class="tablenav-pages">
						<?php if ( $page > 1 ) : ?>
							<a class="button prev-page" href="<?php echo esc_url( add_query_arg( array( 'paged' => $page - 1 ), $logs_base_url ) ); ?>">
								&laquo; <?php esc_html_e( 'Previous', 'scalyn-mail-relay' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $has_next_page ) : ?>
							<a class="button next-page" href="<?php echo esc_url( add_query_arg( array( 'paged' => $page + 1 ), $logs_base_url ) ); ?>">
								<?php esc_html_e( 'Next', 'scalyn-mail-relay' ); ?> &raquo;
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<p class="description scalyn-log-note">
			<?php esc_html_e( 'Accepted means the configured provider acknowledged the message. Accepted does not guarantee inbox delivery.', 'scalyn-mail-relay' ); ?>
		</p>

	<?php endif; ?>
</div>
