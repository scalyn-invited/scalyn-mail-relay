<?php
/**
 * Dashboard actions; preserves the existing permission and verification gates.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\ActionButton;

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card scalyn-actions-card" aria-labelledby="scalyn-actions-heading">
	<h2 id="scalyn-actions-heading" class="screen-reader-text"><?php esc_html_e( 'Quick Actions', 'scalyn-mail-relay' ); ?></h2>
	<div class="scalyn-actions">
		<?php
		ActionButton::render(
			__( 'Run Diagnostics', 'scalyn-mail-relay' ),
			$diagnostics_run_url,
			! $provider_verified,
			'scalyn-run-diagnostics',
			array(
				'scalyn-action' => 'run-diagnostics',
				'endpoint'      => $diagnostics_run_url,
				'redirect'      => $diagnostics_page_url,
			),
			true
		);
		ActionButton::render( __( 'Send Test Email', 'scalyn-mail-relay' ), $test_email_url, ! $provider_verified, '', array(), false );
		ActionButton::render( __( 'Configure Mailer', 'scalyn-mail-relay' ), $wizard_url, false, '', array(), false );
		ActionButton::render( __( 'View Logs', 'scalyn-mail-relay' ), $logs_url, false, '', array(), false );
		?>
	</div>
	<?php if ( ! $provider_verified ) : ?>
		<p class="scalyn-actions__note description"><?php esc_html_e( 'Run Diagnostics and Send Test Email will be enabled after a mail provider is configured and verified through a successful connection or test email.', 'scalyn-mail-relay' ); ?></p>
	<?php endif; ?>
</section>
