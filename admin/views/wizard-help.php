<?php
/**
 * Contextual, credential-free setup guidance.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;

$wizard_help = array(
	1 => array( __( 'Before you begin', 'scalyn-mail-relay' ), __( 'Have your provider credentials, sender email address, and an inbox you control ready. Setup time depends on whether your provider and sender are already configured.', 'scalyn-mail-relay' ) ),
	2 => array( __( 'Choose your sending route', 'scalyn-mail-relay' ), __( 'Use SMTP if you have mail-server login details, or SendGrid / Postmark / SMTP2GO / Brevo if you have the corresponding API credential. Only providers registered on this site appear here. Saving your selection changes the active route immediately.', 'scalyn-mail-relay' ) ),
	3 => array( __( 'Keep credentials private', 'scalyn-mail-relay' ), __( 'Copy settings from your provider, not from an example. Saved passwords and API keys are never displayed here. Use a sender address authorized by your provider.', 'scalyn-mail-relay' ) ),
	4 => array( __( 'What this check tells you', 'scalyn-mail-relay' ), __( 'A connection test checks the selected provider without sending email. For SendGrid, it validates a sandbox request; it does not prove real-send permission. For Postmark, it verifies token access to a Live server, not sender authorization or sending allowance. SMTP2GO and Brevo verify API read access, not sending permission. DNS authentication is checked separately in Diagnostics.', 'scalyn-mail-relay' ) ),
	5 => array( __( 'Check the receiving inbox', 'scalyn-mail-relay' ), __( 'This action sends a real email. Check Inbox and Spam or Junk. If the provider accepts it but it does not arrive, check provider activity or a bounce report for the next clue.', 'scalyn-mail-relay' ) ),
	6 => array( __( 'Understand your health score', 'scalyn-mail-relay' ), __( 'Run the check after saving your provider settings. Review warnings and unknown results in Diagnostics. A completed run can still contain failed or unavailable checks; the score is not proof of delivery.', 'scalyn-mail-relay' ) ),
	7 => array( __( 'Next: review recommendations', 'scalyn-mail-relay' ), __( 'Review diagnostic findings and address warnings with your provider. Check the receiving inbox for your test email and use Email Logs to investigate missing messages. Completing setup does not confirm recipient delivery or inbox placement.', 'scalyn-mail-relay' ) ),
);
?>
<aside class="scalyn-card scalyn-wizard-help" aria-labelledby="scalyn-wizard-help-title">
	<p class="scalyn-wizard-eyebrow"><?php esc_html_e( 'Setup guidance', 'scalyn-mail-relay' ); ?></p>
	<h2 id="scalyn-wizard-help-title"><?php echo esc_html( $wizard_help[ $current_step ][0] ); ?></h2>
	<p><?php echo esc_html( $wizard_help[ $current_step ][1] ); ?></p>
	<div class="scalyn-wizard-reminder">
		<h3><?php esc_html_e( 'Acceptance is not delivery', 'scalyn-mail-relay' ); ?></h3>
		<p><?php esc_html_e( 'A successful check or provider acceptance does not guarantee that an email reaches the recipient’s inbox.', 'scalyn-mail-relay' ); ?></p>
	</div>
	<?php if ( 7 === $current_step && current_user_can( \Scalyn\MailRelay\Core\Capabilities::RUN_DIAGNOSTICS ) ) : ?>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-diagnostics' ) ); ?>"><?php esc_html_e( 'Open Diagnostics', 'scalyn-mail-relay' ); ?></a>
	<?php endif; ?>
</aside>
