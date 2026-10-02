<?php
/**
 * Email Logs list view.
 *
 * Variables injected by LogsPage::render_list():
 *   array $rows          Log rows as associative arrays, newest first. May be empty.
 *   int   $page          Current page number (≥ 1).
 *   bool  $has_next_page Whether additional rows may exist beyond this page.
 *   string $status       Validated status filter, or an empty string.
 *
 * Privacy: Only explicitly opted-in recipient/subject metadata is shown.
 * Bodies, raw response_message and event_data are never rendered.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\EmptyState;
use Scalyn\MailRelay\Admin\Components\StatusBadge;

defined( 'ABSPATH' ) || exit;

$logs_base_url = admin_url( 'admin.php?page=scalyn-mail-relay-logs' );
$logs_form_url = admin_url( 'admin.php' );

$status_labels = array(
	'accepted' => __( 'Accepted', 'scalyn-mail-relay' ),
	'failed'   => __( 'Failed', 'scalyn-mail-relay' ),
);

$previous_page_args = array_merge( $filters, array( 'paged' => $page - 1 ) );
$next_page_args     = array_merge( $filters, array( 'paged' => $page + 1 ) );
if ( '' !== $status ) {
	$previous_page_args['status'] = $status;
	$next_page_args['status']     = $status;
}
$previous_page_url = add_query_arg( $previous_page_args, $logs_base_url );
$next_page_url     = add_query_arg( $next_page_args, $logs_base_url );
?>
<div class="wrap scalyn-mail-relay scalyn-logs">
	<h1><?php esc_html_e( 'Email Logs', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Recent email outcomes recorded by Scalyn Mail Relay.', 'scalyn-mail-relay' ); ?></p>
	<p class="scalyn-log-context"><?php esc_html_e( 'Accepted means the sending provider acknowledged the message—not that it reached the inbox. Open a timeline to inspect the recorded events.', 'scalyn-mail-relay' ); ?></p>

	<div class="scalyn-card scalyn-logs-filters">
		<h2><?php esc_html_e( 'Find email activity', 'scalyn-mail-relay' ); ?></h2>
		<?php if ( $filter_error ) : ?>
			<p role="alert"><?php esc_html_e( 'Unable to apply these filters. Check the date range and filter lengths (maximum 255 characters), or ask an administrator to check the database upgrade.', 'scalyn-mail-relay' ); ?></p>
		<?php endif; ?>
		<form method="get" action="<?php echo esc_url( $logs_form_url ); ?>" class="scalyn-filter-form">
			<input type="hidden" name="page" value="scalyn-mail-relay-logs" />
			<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Filter email logs', 'scalyn-mail-relay' ); ?></legend>
					<div class="scalyn-filter-controls">
						<?php
						foreach ( array(
							'search'    => __( 'Search subject or exact message ID', 'scalyn-mail-relay' ),
							'start'     => __( 'From date', 'scalyn-mail-relay' ),
							'end'       => __( 'To date', 'scalyn-mail-relay' ),
							'provider'  => __( 'Provider ID (exact)', 'scalyn-mail-relay' ),
							'source'    => __( 'Plugin / source contains', 'scalyn-mail-relay' ),
							'recipient' => __( 'Recipient contains', 'scalyn-mail-relay' ),
						) as $key => $filter_label ) :
							?>
							<div class="scalyn-filter-group">
								<label for="scalyn-filter-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $filter_label ); ?></label>
								<input id="scalyn-filter-<?php echo esc_attr( $key ); ?>" type="<?php echo in_array( $key, array( 'start', 'end' ), true ) ? 'date' : 'text'; ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $filters[ $key ] ?? '' ); ?>" maxlength="255" />
							</div>
						<?php endforeach; ?>
						<div class="scalyn-filter-group">
							<label for="scalyn-filter-status" class="scalyn-filter-label">
								<?php esc_html_e( 'Status:', 'scalyn-mail-relay' ); ?>
							</label>
							<select id="scalyn-filter-status" name="status" class="scalyn-filter-select" aria-label="<?php esc_attr_e( 'Filter by status', 'scalyn-mail-relay' ); ?>">
								<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'All', 'scalyn-mail-relay' ); ?></option>
								<option value="accepted" <?php selected( $status, 'accepted' ); ?>><?php esc_html_e( 'Accepted', 'scalyn-mail-relay' ); ?></option>
								<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'scalyn-mail-relay' ); ?></option>
							</select>
						</div>
						<div class="scalyn-actions">
						<button type="submit" class="button button-primary" aria-label="<?php esc_attr_e( 'Apply filters', 'scalyn-mail-relay' ); ?>">
							<?php esc_html_e( 'Filter', 'scalyn-mail-relay' ); ?>
						</button>
						<?php
						if ( '' !== $status || $filters || $filter_error ) :
							?>
							<a href="<?php echo esc_url( $logs_base_url ); ?>" class="button">
								<?php esc_html_e( 'Clear', 'scalyn-mail-relay' ); ?>
							</a>
						<?php endif; ?>
						</div>
					</div>
			</fieldset>
		</form>
		<p class="description"><?php esc_html_e( 'Dates include both selected days in site time. Search and recipient filters cover only metadata retained with administrator consent; older or unrecorded values cannot match. Provider examples: smtp, sendgrid, postmark.', 'scalyn-mail-relay' ); ?></p>
		<details class="scalyn-disclosure scalyn-log-lookup">
			<summary><?php esc_html_e( 'Have a message ID?', 'scalyn-mail-relay' ); ?></summary>
			<form method="get" action="<?php echo esc_url( $logs_form_url ); ?>">
				<input type="hidden" name="page" value="scalyn-mail-relay-logs" />
				<label for="scalyn-message-lookup"><?php esc_html_e( 'Message ID', 'scalyn-mail-relay' ); ?></label>
				<div class="scalyn-filter-controls">
					<input type="text" id="scalyn-message-lookup" name="message_uuid" class="regular-text" maxlength="36" required pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}" aria-describedby="scalyn-message-lookup-help" autocomplete="off" />
					<button type="submit" class="button"><?php esc_html_e( 'Open timeline', 'scalyn-mail-relay' ); ?></button>
				</div>
				<p id="scalyn-message-lookup-help" class="description"><?php esc_html_e( 'Paste the full message UUID from a log or report. This opens its timeline; it does not search email content.', 'scalyn-mail-relay' ); ?></p>
			</form>
		</details>
	</div>

	<?php if ( $filter_error ) : ?>
		<p><?php esc_html_e( 'No results shown because the search could not be completed.', 'scalyn-mail-relay' ); ?></p>
	<?php elseif ( empty( $rows ) ) : ?>

		<div class="scalyn-card">
			<?php
			if ( $filters ) {
				EmptyState::render( __( 'No email activity matches these filters.', 'scalyn-mail-relay' ) );
			} elseif ( '' !== $status ) {
				EmptyState::render(
					__( 'No email activity matches the selected status.', 'scalyn-mail-relay' )
				);
			} else {
				EmptyState::render(
					__( 'No email activity has been recorded yet.', 'scalyn-mail-relay' )
				);
			}
			?>
			<p class="scalyn-card__note description">
				<?php
				if ( '' !== $status || $filters ) {
					esc_html_e( 'Choose another status or clear the filter.', 'scalyn-mail-relay' );
				} else {
					esc_html_e( 'Logs will appear here after Scalyn Mail Relay sends its first email.', 'scalyn-mail-relay' );
				}
				?>
			</p>
		</div>

	<?php else : ?>

		<div class="scalyn-card scalyn-log-results">
			<div class="scalyn-panel-heading">
				<h2 id="scalyn-log-results-heading"><?php esc_html_e( 'Recorded messages', 'scalyn-mail-relay' ); ?></h2>
				<p class="description">
				<?php
				/* translators: 1: displayed record count, 2: page number. */
				echo esc_html( sprintf( __( 'Records on this page: %1$d · Page %2$d', 'scalyn-mail-relay' ), count( $rows ), $page ) );
				?>
				</p>
			</div>
			<p class="description"><?php echo esc_html( '' !== $status ? sprintf( /* translators: %s: selected outcome. */ __( 'Filtered by: %s. Newest first; timestamps use site time.', 'scalyn-mail-relay' ), $status_labels[ $status ] ) : ( $filters ? __( 'Matching selected filters. Newest first; timestamps use site time.', 'scalyn-mail-relay' ) : __( 'All recorded outcomes. Newest first; timestamps use site time.', 'scalyn-mail-relay' ) ) ); ?></p>
			<div class="scalyn-log-table-wrapper" role="region" aria-labelledby="scalyn-log-results-heading" tabindex="0">
				<table class="wp-list-table widefat fixed striped scalyn-log-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Recorded email outcomes with site-time timestamp, optional recipient and subject, provider, source, status and actions.', 'scalyn-mail-relay' ); ?></caption>
				<thead>
					<tr>
						<th scope="col" class="scalyn-log-col-timestamp"><?php esc_html_e( 'Timestamp', 'scalyn-mail-relay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recipient', 'scalyn-mail-relay' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subject', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-provider" style="width: 80px;"><?php esc_html_e( 'Provider', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-source" style="width: 70px; white-space: nowrap;"><?php esc_html_e( 'Source', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-status"><?php esc_html_e( 'Status', 'scalyn-mail-relay' ); ?></th>
						<th scope="col" class="scalyn-log-col-action"><?php esc_html_e( 'Actions', 'scalyn-mail-relay' ); ?></th>
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
							<td class="scalyn-log-col-timestamp" data-label="<?php esc_attr_e( 'Timestamp', 'scalyn-mail-relay' ); ?>">
								<time datetime="<?php echo esc_attr( $created_at ); ?>"><?php echo esc_html( $created_at ); ?></time>
							</td>
							<?php
							foreach ( array(
								'logged_recipients' => __( 'Recipient', 'scalyn-mail-relay' ),
								'logged_subject'    => __( 'Subject', 'scalyn-mail-relay' ),
							) as $field => $field_label ) :
								?>
								<td data-label="<?php echo esc_attr( $field_label ); ?>"><?php echo esc_html( isset( $row[ $field ] ) ? ( '' !== $row[ $field ] ? $row[ $field ] : __( '(Empty)', 'scalyn-mail-relay' ) ) : __( 'Not recorded', 'scalyn-mail-relay' ) ); ?></td>
							<?php endforeach; ?>
							<td class="scalyn-log-col-provider" data-label="<?php esc_attr_e( 'Provider', 'scalyn-mail-relay' ); ?>">
								<?php echo '' !== $provider ? esc_html( $provider ) : '<span aria-label="' . esc_attr__( 'Unknown provider', 'scalyn-mail-relay' ) . '">—</span>'; ?>
							</td>
							<td class="scalyn-log-col-source" data-label="<?php esc_attr_e( 'Source', 'scalyn-mail-relay' ); ?>">
								<?php echo '—' === $source_display ? '<span>—</span>' : esc_html( $source_display ); ?>
							</td>
							<td class="scalyn-log-col-status" data-label="<?php esc_attr_e( 'Status', 'scalyn-mail-relay' ); ?>">
								<?php StatusBadge::render( $row_status, $label ); ?>
							</td>
							<td class="scalyn-log-col-action" data-label="<?php esc_attr_e( 'Timeline', 'scalyn-mail-relay' ); ?>">
								<?php if ( '' !== $uuid ) : ?>
									<a href="<?php echo esc_url( $timeline_url ); ?>" class="button button-small" data-scalyn-timeline>
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
				<nav class="scalyn-log-pagination tablenav" aria-label="<?php esc_attr_e( 'Email log pages', 'scalyn-mail-relay' ); ?>">
					<div class="tablenav-pages">
						<?php if ( $page > 1 ) : ?>
							<a class="button prev-page" href="<?php echo esc_url( $previous_page_url ); ?>">
								&laquo; <?php esc_html_e( 'Previous', 'scalyn-mail-relay' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $has_next_page ) : ?>
							<a class="button next-page" href="<?php echo esc_url( $next_page_url ); ?>">
								<?php esc_html_e( 'Next', 'scalyn-mail-relay' ); ?> &raquo;
							</a>
						<?php endif; ?>
					</div>
				</nav>
			<?php endif; ?>
		</div>

		<p class="description scalyn-log-note">
			<?php esc_html_e( 'Accepted means the configured provider acknowledged the message. Accepted does not guarantee inbox delivery. The recipient server may reject it later; check provider delivery logs and bounce reports. Later bounces are not automatically reflected in this status.', 'scalyn-mail-relay' ); ?>
		</p>

	<?php endif; ?>
	<details class="scalyn-disclosure scalyn-log-privacy"><summary><?php esc_html_e( 'Log privacy and retention', 'scalyn-mail-relay' ); ?></summary>
		<p><?php esc_html_e( 'Recipient and subject capture is optional and off by default in Settings. Not recorded means no retained value is available. Bodies, raw headers and raw provider responses are excluded. Metadata expires with the log under your retention settings; disabling capture does not delete existing values. Counts describe this page, not lifetime totals.', 'scalyn-mail-relay' ); ?></p>
	</details>
	<dialog class="scalyn-timeline-drawer" aria-labelledby="scalyn-drawer-heading">
		<div class="scalyn-panel-heading">
			<h2 id="scalyn-drawer-heading"><?php esc_html_e( 'Message timeline', 'scalyn-mail-relay' ); ?></h2>
			<button class="button" type="button" data-scalyn-close><?php esc_html_e( 'Close', 'scalyn-mail-relay' ); ?></button>
		</div>
		<p data-scalyn-loading role="status"><?php esc_html_e( 'Loading timeline…', 'scalyn-mail-relay' ); ?></p>
		<p data-scalyn-load-error role="alert" hidden><?php esc_html_e( 'The timeline could not be loaded. Open the full page to sign in or try again.', 'scalyn-mail-relay' ); ?></p>
		<p><a class="button" data-scalyn-full-page><?php esc_html_e( 'Open full timeline', 'scalyn-mail-relay' ); ?></a></p>
		<div data-scalyn-timeline-content></div>
	</dialog>
</div>
