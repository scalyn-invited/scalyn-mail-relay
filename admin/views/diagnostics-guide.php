<?php
/**
 * In-page documentation for the supported diagnostic checks.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card" id="scalyn-diagnostic-guide" aria-labelledby="scalyn-diagnostic-guide-title">
	<h2 id="scalyn-diagnostic-guide-title"><?php esc_html_e( 'Diagnostic check documentation', 'scalyn-mail-relay' ); ?></h2>
	<dl>
		<dt><?php esc_html_e( 'SPF', 'scalyn-mail-relay' ); ?></dt><dd><?php esc_html_e( 'Reviews the published sender-policy record. It does not establish whether the actual outbound IP is authorized for a particular email.', 'scalyn-mail-relay' ); ?></dd>
		<dt><?php esc_html_e( 'DKIM', 'scalyn-mail-relay' ); ?></dt><dd><?php esc_html_e( 'Looks up the configured selector’s DNS record. A published key does not prove that the provider signs messages or that a signature passes at the recipient.', 'scalyn-mail-relay' ); ?></dd>
		<dt><?php esc_html_e( 'DMARC', 'scalyn-mail-relay' ); ?></dt><dd><?php esc_html_e( 'Reviews the published domain policy. Confirm legitimate sender alignment with your provider before enforcing a stricter policy.', 'scalyn-mail-relay' ); ?></dd>
		<dt><?php esc_html_e( 'MX', 'scalyn-mail-relay' ); ?></dt><dd><?php esc_html_e( 'Checks domain mail-routing records. MX findings alone do not determine outbound email delivery.', 'scalyn-mail-relay' ); ?></dd>
		<dt><?php esc_html_e( 'SMTP/TLS', 'scalyn-mail-relay' ); ?></dt><dd><?php esc_html_e( 'Checks the configured SMTP endpoint and available TLS/certificate evidence. This is not API-provider health, an authenticated send test, or inbox-placement evidence.', 'scalyn-mail-relay' ); ?></dd>
	</dl>
</section>
