<?php
/**
 * Prioritised, evidence-bound recommendations. Receives $recommendations.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="scalyn-card" aria-labelledby="scalyn-recommendations-heading">
	<h2 id="scalyn-recommendations-heading"><?php esc_html_e( 'What to address next', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'Rule-based guidance: failures first, then warnings, then evidence gaps; severity orders each group. No settings are changed automatically.', 'scalyn-mail-relay' ); ?></p>
	<?php if ( $recommendations['refresh'] ) : ?>
		<p><?php esc_html_e( 'Refresh diagnostic evidence first. Results are missing, stale, future-dated or not consistently correlated. Run Diagnostics before acting on historical findings.', 'scalyn-mail-relay' ); ?></p>
	<?php elseif ( empty( $recommendations['items'] ) ) : ?>
		<p><?php esc_html_e( 'No recommendations from the supported record and transport checks. This is not proof of authenticated messages or inbox delivery.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<ol>
			<?php foreach ( $recommendations['items'] as $item ) : ?>
				<li>
					<h3><?php echo esc_html( $item['title'] ); ?></h3>
					<p><?php echo esc_html( $item['kind'] . ' — ' . $item['severity'] ); ?></p>
					<p><?php echo esc_html( $item['impact'] ); ?></p>
					<p><?php echo esc_html( $item['action'] ); ?></p>
					<p><?php esc_html_e( 'Evidence reference (check / run / site time):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $item['check'] . ' / ' . ( '' !== $item['run_uuid'] ? $item['run_uuid'] . ' / ' . $item['recorded_at'] : __( 'No retained result for this check', 'scalyn-mail-relay' ) ) ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	<p><?php esc_html_e( 'Review the matching diagnostic cards above for the observed details. Guidance covers SPF, DKIM, DMARC, MX and SMTP/TLS only; it cannot diagnose a later bounce without receiver evidence.', 'scalyn-mail-relay' ); ?></p>
</section>
