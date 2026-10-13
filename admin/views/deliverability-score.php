<?php
/** Shared Deliverability Score card. No inbox-placement or delivery promise.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

$labels           = array(
	'authentication'         => __( 'Authentication configuration', 'scalyn-mail-relay' ),
	'domain_alignment'       => __( 'Domain alignment configuration (not message verification)', 'scalyn-mail-relay' ),
	'dns_quality'            => __( 'DNS quality', 'scalyn-mail-relay' ),
	'historical_performance' => __( 'Historical performance', 'scalyn-mail-relay' ),
	'header_quality'         => __( 'Header quality — informational only', 'scalyn-mail-relay' ),
	'provider_reputation'    => __( 'Provider reputation — unavailable', 'scalyn-mail-relay' ),
);
$check_labels     = array(
	'spf_record'             => __( 'SPF record', 'scalyn-mail-relay' ),
	'dkim_record'            => __( 'DKIM record', 'scalyn-mail-relay' ),
	'dmarc_policy'           => __( 'DMARC policy', 'scalyn-mail-relay' ),
	'mx_record'              => __( 'MX record', 'scalyn-mail-relay' ),
	'reverse_dns'            => __( 'Reverse DNS', 'scalyn-mail-relay' ),
	'dmarc_configuration'    => __( 'DMARC alignment configuration', 'scalyn-mail-relay' ),
	'submission_reliability' => __( 'Submission reliability', 'scalyn-mail-relay' ),
	'hard_bounce_rate'       => __( 'Hard-bounce rate', 'scalyn-mail-relay' ),
	'message_headers'        => __( 'Message headers', 'scalyn-mail-relay' ),
	'reputation_source'      => __( 'Reputation source', 'scalyn-mail-relay' ),
);
$exclusion_labels = array(
	'unknown'                                     => __( 'Unknown — no usable evidence', 'scalyn-mail-relay' ),
	'error'                                       => __( 'Check could not complete', 'scalyn-mail-relay' ),
	'stale'                                       => __( 'Stale or invalid timestamp — rerun diagnostics', 'scalyn-mail-relay' ),
	'not_assessed'                                => __( 'Not assessed', 'scalyn-mail-relay' ),
	'unavailable'                                 => __( 'Evidence unavailable', 'scalyn-mail-relay' ),
	'not_applicable'                              => __( 'Not applicable to this provider', 'scalyn-mail-relay' ),
	'off'                                         => __( 'Delivery evidence collection is off', 'scalyn-mail-relay' ),
	'paused'                                      => __( 'Delivery evidence collection is paused', 'scalyn-mail-relay' ),
	'unsupported'                                 => __( 'Delivery evidence is not supported by this provider', 'scalyn-mail-relay' ),
	'informational_only'                          => __( 'Informational only — not scored', 'scalyn-mail-relay' ),
	'minimum_20_submissions'                      => __( 'Requires at least 20 Accepted/Failed submissions', 'scalyn-mail-relay' ),
	'minimum_20_observed_and_50_percent_coverage' => __( 'Requires 20 observed recipients, 50% evidence coverage and an eligible submission sample', 'scalyn-mail-relay' ),
);
?>
<section class="scalyn-card" aria-labelledby="scalyn-deliverability-heading">
	<h2 id="scalyn-deliverability-heading"><?php esc_html_e( 'Deliverability Score (evidence-based estimate)', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'This estimate is separate from Email Health (configuration). Provider acceptance is not confirmed delivery, and this score never proves inbox placement or authentication on an actual message.', 'scalyn-mail-relay' ); ?></p>
	<?php
	if ( '' !== $error ) :
		?>
		<p role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
	<strong class="scalyn-score"><?php echo esc_html( null === ( $assessment['score'] ?? null ) ? __( 'Not assessed', 'scalyn-mail-relay' ) : $assessment['score'] . '/100' ); ?></strong>
	<?php if ( null === $assessment ) : ?>
		<p><?php esc_html_e( 'No saved assessment for this configuration. Run diagnostics, then assess the retained evidence. At least two fresh authentication results, 20 submissions and 60% weight coverage are required.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'Coverage and freshness: unavailable until an assessment has been saved. Unknown evidence is excluded, not counted as a pass or failure.', 'scalyn-mail-relay' ); ?></p>
		<details class="scalyn-disclosure"><summary><?php esc_html_e( 'Components and exclusions', 'scalyn-mail-relay' ); ?></summary>
			<ul>
			<?php
			foreach ( $labels as $name => $label ) :
				?>
				<li><?php echo esc_html( $label . ' (' . \Scalyn\MailRelay\Diagnostics\DeliverabilityScorer::WEIGHTS[ $name ] . '%) — ' . __( 'Not assessed', 'scalyn-mail-relay' ) ); ?></li><?php endforeach; ?></ul>
		</details>
		<p><?php esc_html_e( 'Important: run diagnostics for this configuration and collect a representative submission sample. Optional: review received-message headers for informational verdicts.', 'scalyn-mail-relay' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Recorded assessment (UTC):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $assessment['inputs']['evaluated_at'] ) ); ?> · <?php echo esc_html( $assessment['weights_version'] ); ?></p>
		<p><?php esc_html_e( 'Historical window (UTC):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $assessment['inputs']['window_start'] ) . ' — ' . gmdate( 'Y-m-d H:i:s', $assessment['inputs']['window_end'] ) ); ?></p>
		<?php
		if ( $assessment['inputs']['days'] < 30 ) :
			?>
			<p><?php esc_html_e( 'The 30-day history window is shortened by your log-retention setting.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
		<p><?php esc_html_e( 'Freshness limit (hours):', 'scalyn-mail-relay' ); ?> <?php echo esc_html( round( $assessment['inputs']['fresh_for'] / 3600, 1 ) ); ?>. <?php esc_html_e( 'Fresh for seven days or twice the monitoring cadence, whichever is shorter. Refresh after new submissions or webhook events to include them.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'Component-weight coverage:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['weight_coverage'] . '%' ); ?> · <?php esc_html_e( 'Authentication checks:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['authentication_count'] . '/3' ); ?> · <?php esc_html_e( 'Submissions:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( null === $assessment['inputs']['submissions'] ? __( 'Unavailable', 'scalyn-mail-relay' ) : $assessment['inputs']['submissions']['accepted'] + $assessment['inputs']['submissions']['failed'] ); ?></p>
		<p><?php esc_html_e( 'Delivery evidence coverage:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( null === $assessment['delivery_coverage'] ? __( 'Unavailable', 'scalyn-mail-relay' ) : round( 100 * $assessment['delivery_coverage'], 1 ) . '%' ); ?>. <?php esc_html_e( 'Missing callbacks are not failures. Bounce scoring requires 20 observed recipients and at least 50% coverage.', 'scalyn-mail-relay' ); ?></p>
		<?php
		if ( ! isset( $assessment['components']['historical_performance']['included']['hard_bounce_rate'] ) ) :
			?>
			<p><?php esc_html_e( 'Only submission reliability is used for historical performance when eligible; hard-bounce scoring is excluded.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
		<?php
		if ( ! empty( $assessment['missing'] ) ) :
			?>
			<ul>
			<?php
			foreach ( $assessment['missing'] as $reason ) :
				?>
			<li><?php echo esc_html( $reason ); ?></li><?php endforeach; ?></ul><?php endif; ?>
		<?php
		if ( ! empty( $assessment['needs_refresh'] ) ) :
			?>
			<p><?php esc_html_e( 'The breakdown below describes the recorded assessment only; it is no longer current.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
		<details class="scalyn-disclosure">
			<summary><?php esc_html_e( 'Components, inputs and exclusions', 'scalyn-mail-relay' ); ?></summary>
			<div class="scalyn-helper-highlight">
			<p><?php esc_html_e( 'Accepted submissions:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['inputs']['submissions']['accepted'] ?? __( 'Unavailable', 'scalyn-mail-relay' ) ); ?> · <?php esc_html_e( 'Failed submissions:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['inputs']['submissions']['failed'] ?? __( 'Unavailable', 'scalyn-mail-relay' ) ); ?></p>
			<p><?php esc_html_e( 'Tracked recipients:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['inputs']['delivery']['tracked'] ?? __( 'Unavailable', 'scalyn-mail-relay' ) ); ?> · <?php esc_html_e( 'Recipients with evidence:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['inputs']['delivery']['observed'] ?? __( 'Unavailable', 'scalyn-mail-relay' ) ); ?> · <?php esc_html_e( 'Hard-bounced recipients:', 'scalyn-mail-relay' ); ?> <?php echo esc_html( $assessment['inputs']['delivery']['hard_bounced'] ?? __( 'Unavailable', 'scalyn-mail-relay' ) ); ?></p>
			<dl><?php foreach ( $assessment['components'] as $name => $component ) : ?>
				<dt><?php echo esc_html( $labels[ $name ] . ' (' . $component['weight'] . '%)' ); ?></dt>
				<dd><?php echo esc_html( null === $component['score'] ? __( 'Not assessed', 'scalyn-mail-relay' ) : round( $component['score'], 1 ) . '/100' ); ?>
					<ul>
					<?php
					foreach ( $component['included'] as $check => $points ) :
						?>
						<li><?php echo esc_html( $check_labels[ $check ] . ': ' . $points ); ?></li><?php endforeach; ?>
					<?php
					foreach ( $component['excluded'] as $check => $reason ) :
						?>
						<li><?php echo esc_html( $check_labels[ $check ] . ': ' . $exclusion_labels[ $reason ] ); ?></li><?php endforeach; ?></ul>
				</dd>
			<?php endforeach; ?></dl>
			</div>
		</details>
		<details class="scalyn-disclosure"><summary><?php esc_html_e( 'Ranked recommendations', 'scalyn-mail-relay' ); ?></summary>
			<div class="scalyn-helper-highlight">
			<ol>
			<?php
			foreach ( $assessment['recommendations'] as $recommendation ) :
				?>
				<li><?php echo esc_html( $recommendation['priority'] . ' — ' . $recommendation['reason'] ); ?></li><?php endforeach; ?></ol>
			</div>
		</details>
	<?php endif; ?>
	<?php if ( current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) : ?>
		<form method="post">
			<?php wp_nonce_field( 'scalyn_deliverability_assess' ); ?>
			<button type="submit" class="button button-secondary" name="scalyn_deliverability_assess" value="1"><?php esc_html_e( 'Refresh Deliverability Score', 'scalyn-mail-relay' ); ?></button>
		</form>
	<?php endif; ?>
</section>
