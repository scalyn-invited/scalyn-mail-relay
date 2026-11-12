<?php
/**
 * Latest retained health evidence for wizard review; never a wizard completion flag.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-wizard-health" aria-labelledby="scalyn-wizard-health-heading">
	<?php require SCALYN_MAIL_RELAY_PATH . 'admin/views/diagnostic-scope.php'; ?>
	<h3 id="scalyn-wizard-health-heading"><?php esc_html_e( 'Latest recorded configuration health', 'scalyn-mail-relay' ); ?></h3>
	<p class="scalyn-score"><?php echo esc_html( $wizard_health['label'] ); ?></p>
	<?php if ( null === $wizard_health['created_at'] ) : ?>
		<p><?php esc_html_e( 'No health snapshot is recorded. Run a health check to generate one.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<p><?php echo esc_html( $wizard_freshness ); ?></p>
	<?php endif; ?>
	<details class="scalyn-disclosure">
		<summary><?php esc_html_e( 'Score breakdown and limitations', 'scalyn-mail-relay' ); ?></summary>
		<?php \Scalyn\MailRelay\Admin\Components\HealthScoreBreakdown::render( $wizard_health['components'], $wizard_health['summary'] ); ?>
	</details>
	<p><?php esc_html_e( 'This is a configuration-check score, not a delivery success rate. Unknown checks are excluded. Even 100/100 does not verify authentication on an actual message or inbox placement.', 'scalyn-mail-relay' ); ?></p>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay-diagnostics' ) ); ?>"><?php esc_html_e( 'Review findings and recommendations', 'scalyn-mail-relay' ); ?></a></p>
</section>
