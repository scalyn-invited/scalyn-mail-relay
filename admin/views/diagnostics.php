<?php
/**
 * Diagnostics page placeholder view.
 *
 * Diagnostic engine, health scoring, SPF/DKIM/DMARC checks, and remediation
 * UI will be implemented once Yaj delivers the diagnostics module
 * (includes/Diagnostics/).
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Diagnostics', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Email deliverability health checks and remediation guidance.', 'scalyn-mail-relay' ); ?></p>

	<div class="scalyn-card">
		<h2><?php esc_html_e( 'Diagnostics Not Yet Available', 'scalyn-mail-relay' ); ?></h2>
		<p><?php esc_html_e( 'The diagnostics engine is not yet available. Once configured, this page will show SPF, DKIM, and DMARC verification results alongside your email health score and remediation recommendations.', 'scalyn-mail-relay' ); ?></p>
		<p class="description"><?php esc_html_e( 'Configure a mail provider first using the Setup Wizard, then return here to run your first diagnostics check.', 'scalyn-mail-relay' ); ?></p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-wizard' ) ); ?>" class="button">
			<?php esc_html_e( 'Open Setup Wizard', 'scalyn-mail-relay' ); ?>
		</a>
	</div>
</div>
