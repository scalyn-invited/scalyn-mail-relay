<?php
/**
 * Email Logs page placeholder view.
 *
 * Log data, timeline presentation, and filtering UI will be implemented
 * once Yaj delivers the logging module (includes/Logging/) and REST contracts.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Email Logs', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Review sent, failed, and queued email history.', 'scalyn-mail-relay' ); ?></p>

	<div class="scalyn-card">
		<h2><?php esc_html_e( 'No Logs Available', 'scalyn-mail-relay' ); ?></h2>
		<p><?php esc_html_e( 'Email log data is not yet available. Log records will appear here once a mail provider is configured and emails begin sending.', 'scalyn-mail-relay' ); ?></p>
		<p class="description"><?php esc_html_e( 'Scalyn Mail Relay distinguishes between SMTP acceptance and confirmed inbox delivery. Logs will reflect that distinction.', 'scalyn-mail-relay' ); ?></p>
	</div>
</div>
