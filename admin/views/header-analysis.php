<?php
/**
 * Received test message analysis.
 *
 * Variables injected by HeaderAnalysisForm::render():
 *   array|null $result Analysis: status, findings and summary (domains and verdicts only).
 *   string     $error  Fixed error feedback.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\HeaderAnalysisForm;
use Scalyn\MailRelay\Admin\Components\StatusBadge;

defined( 'ABSPATH' ) || exit;

$ha_badges = array(
	'pass'    => 'healthy',
	'warn'    => 'warning',
	'fail'    => 'critical',
	'unknown' => 'unknown',
);
$ha_labels = array(
	'pass'    => __( 'Pass', 'scalyn-mail-relay' ),
	'warn'    => __( 'Warning', 'scalyn-mail-relay' ),
	'fail'    => __( 'Fail', 'scalyn-mail-relay' ),
	'unknown' => __( 'Not assessable', 'scalyn-mail-relay' ),
);
?>
<section class="scalyn-card scalyn-header-analysis" aria-labelledby="scalyn-header-analysis-title">
	<h2 id="scalyn-header-analysis-title"><?php esc_html_e( 'Check a received test message', 'scalyn-mail-relay' ); ?></h2>
	<p><?php esc_html_e( 'See how a real receiver authenticated your mail. Send a test email to a mailbox you control, open the received message, copy its original headers (for example "Show original" in Gmail or "View message source" in Outlook), and paste them below.', 'scalyn-mail-relay' ); ?></p>
	<details class="scalyn-disclosure">
		<summary><?php esc_html_e( 'Privacy and scope', 'scalyn-mail-relay' ); ?></summary>
		<p><?php esc_html_e( 'Headers are analyzed once in memory and are not saved, logged or shown again. Results show only domains and the receiver’s verdicts, never addresses, subjects or message IDs. Use only messages you sent to your own mailbox. Results describe one message at one receiver; they are not added to scores or history and do not prove inbox placement elsewhere.', 'scalyn-mail-relay' ); ?></p>
	</details>

	<?php if ( '' !== $error ) : ?>
		<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( is_array( $result ) ) : ?>
		<div class="scalyn-header-analysis__result" role="status">
			<p>
				<?php StatusBadge::render( $ha_badges[ $result['status'] ] ?? 'unknown', $ha_labels[ $result['status'] ] ?? $ha_labels['unknown'] ); ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: From domain, 2: receiving server. */
						__( 'Message from "%1$s" as judged by %2$s.', 'scalyn-mail-relay' ),
						'' !== $result['summary']['from_domain'] ? $result['summary']['from_domain'] : __( 'unknown domain', 'scalyn-mail-relay' ),
						'' !== $result['summary']['receiver'] ? $result['summary']['receiver'] : __( 'an unidentified receiver', 'scalyn-mail-relay' )
					)
				);
				?>
			</p>
			<dl class="scalyn-header-analysis__findings">
				<?php foreach ( $result['findings'] as $finding ) : ?>
					<dt><?php echo esc_html( $finding['label'] ); ?> <?php StatusBadge::render( $ha_badges[ $finding['status'] ] ?? 'unknown', $ha_labels[ $finding['status'] ] ?? $ha_labels['unknown'] ); ?></dt>
					<dd><?php echo esc_html( $finding['text'] ); ?></dd>
				<?php endforeach; ?>
			</dl>
			<?php if ( $result['summary']['signatures'] ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						__( 'DKIM signatures present: ', 'scalyn-mail-relay' ) . implode(
							', ',
							array_map( static fn( array $s ): string => $s['d'] . ( '' !== $s['s'] ? ' (selector ' . $s['s'] . ')' : '' ), $result['summary']['signatures'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" autocomplete="off">
		<?php wp_nonce_field( HeaderAnalysisForm::NONCE ); ?>
		<input type="hidden" name="scalyn_header_analysis" value="1" />
		<p><label for="scalyn-headers"><?php esc_html_e( 'Original message headers', 'scalyn-mail-relay' ); ?></label><br />
			<textarea id="scalyn-headers" name="scalyn_headers" class="large-text code" rows="8" maxlength="65536" spellcheck="false" aria-describedby="scalyn-headers-help"></textarea></p>
		<p id="scalyn-headers-help" class="description"><?php esc_html_e( 'Up to 64 KB. Any message body after the headers is ignored.', 'scalyn-mail-relay' ); ?></p>
		<?php submit_button( __( 'Analyze headers', 'scalyn-mail-relay' ), 'secondary', 'submit', false ); ?>
	</form>
</section>
