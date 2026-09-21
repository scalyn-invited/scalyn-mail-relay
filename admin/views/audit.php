<?php
/**
 * Safe audit history read model view.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Audit History', 'scalyn-mail-relay' ); ?></h1>
	<p><?php esc_html_e( 'Administrative activity, newest records first. Times use the site timezone. User IDs refer to WordPress accounts; deleted accounts retain their numeric ID. Unknown means attribution was not recorded. Manual, REST, scheduled, CLI and application describe execution context, not proof of human intent.', 'scalyn-mail-relay' ); ?></p>
	<p><?php esc_html_e( 'Accepted means the provider acknowledged a test email, not confirmed delivery. Completed diagnostics may contain failed checks. A started operation without a result may have been interrupted or its audit write may have failed. Audit history follows the Data Controls retention period; individual expired records are removed, so part of a correlated operation may expire before another part.', 'scalyn-mail-relay' ); ?></p>
	<?php if ( $error ) : ?>
		<div class="notice notice-error" role="status"><p><?php esc_html_e( 'Audit history is unavailable. Check database availability and try again.', 'scalyn-mail-relay' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Audit events, up to 50 per page', 'scalyn-mail-relay' ); ?></caption>
			<thead><tr>
				<?php foreach ( array( __( 'Time', 'scalyn-mail-relay' ), __( 'Action', 'scalyn-mail-relay' ), __( 'Outcome', 'scalyn-mail-relay' ), __( 'Actor', 'scalyn-mail-relay' ), __( 'Source', 'scalyn-mail-relay' ), __( 'Correlation', 'scalyn-mail-relay' ), __( 'Changed fields', 'scalyn-mail-relay' ) ) as $heading ) : ?>
					<th scope="col"><?php echo esc_html( $heading ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php if ( array() === $page['rows'] ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No audit events on this page.', 'scalyn-mail-relay' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $page['rows'] as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['created_at'] ); ?></td>
					<td><?php echo esc_html( str_replace( '_', ' ', $row['action'] ) ); ?></td>
					<td><?php echo esc_html( $row['outcome'] ); ?></td>
					<td><?php echo esc_html( $row['user_id'] > 0 ? '#' . $row['user_id'] : __( 'Unattributed', 'scalyn-mail-relay' ) ); ?></td>
					<td><?php echo esc_html( $row['source'] ); ?></td>
					<td><code><?php echo esc_html( $row['correlation_id'] ); ?></code></td>
					<td><?php echo esc_html( implode( ', ', $row['changed_fields'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<nav aria-label="<?php esc_attr_e( 'Audit pagination', 'scalyn-mail-relay' ); ?>">
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-audit' ) ); ?>"><?php esc_html_e( 'Newest events', 'scalyn-mail-relay' ); ?></a>
			<?php if ( $page['next'] > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'before', $page['next'], admin_url( 'admin.php?page=scalyn-mail-relay-audit' ) ) ); ?>"><?php esc_html_e( 'Older events', 'scalyn-mail-relay' ); ?></a>
			<?php endif; ?></p>
		</nav>
	<?php endif; ?>
</div>
