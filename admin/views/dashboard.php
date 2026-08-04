<?php
/**
 * Dashboard foundation view.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Scalyn Mail Relay', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Email delivery, diagnostics, monitoring and remediation.', 'scalyn-mail-relay' ); ?></p>

	<div class="scalyn-grid">
		<section class="scalyn-card">
			<h2><?php esc_html_e( 'Email Health', 'scalyn-mail-relay' ); ?></h2>
			<strong class="scalyn-score">—</strong>
			<p><?php esc_html_e( 'Run the initial diagnostics after configuring a provider.', 'scalyn-mail-relay' ); ?></p>
		</section>
		<section class="scalyn-card">
			<h2><?php esc_html_e( 'Setup Journey', 'scalyn-mail-relay' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Configure mail provider', 'scalyn-mail-relay' ); ?></li>
				<li><?php esc_html_e( 'Verify connection', 'scalyn-mail-relay' ); ?></li>
				<li><?php esc_html_e( 'Send test email', 'scalyn-mail-relay' ); ?></li>
				<li><?php esc_html_e( 'Verify SPF, DKIM and DMARC', 'scalyn-mail-relay' ); ?></li>
				<li><?php esc_html_e( 'Run diagnostics and generate health score', 'scalyn-mail-relay' ); ?></li>
			</ol>
		</section>
	</div>
</div>
