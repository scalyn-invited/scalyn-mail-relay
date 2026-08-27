<?php
/**
 * Diagnostics page view.
 *
 * Variables injected by DiagnosticsPage::render():
 *   bool   $provider_configured  Whether a provider is configured and registered.
 *   string $wizard_url           URL to Setup Wizard.
 *   string $diagnostics_run_url  URL to trigger diagnostics (empty if endpoint not ready).
 *
 * Diagnostic results, findings, and health scores are shown in Unknown/empty state
 * until Yaj's diagnostics runner and REST contracts are available.
 *
 * Privacy: Do not render credentials, SMTP transcripts, provider metadata,
 * email bodies, or recipient information. Render only sanitized evidence and status.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\ActionButton;
use Scalyn\MailRelay\Admin\Components\DiagnosticResultCard;
use Scalyn\MailRelay\Admin\Components\EmptyState;
use Scalyn\MailRelay\Admin\Components\StatusBadge;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Diagnostics', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Email deliverability health checks and remediation guidance.', 'scalyn-mail-relay' ); ?></p>

	<?php if ( ! $provider_configured ) : ?>
		<div class="scalyn-card">
			<?php
			EmptyState::render(
				__( 'Configure a mail provider first using the Setup Wizard, then return here to run diagnostics.', 'scalyn-mail-relay' ),
				__( 'Open Setup Wizard', 'scalyn-mail-relay' ),
				$wizard_url
			);
			?>
		</div>
	<?php else : ?>

		<div class="scalyn-diagnostics-grid">

			<?php
			DiagnosticResultCard::render(
				__( 'SPF Record', 'scalyn-mail-relay' ),
				'unknown',
				__( 'Unknown', 'scalyn-mail-relay' ),
				function () {
					EmptyState::render(
						__( 'SPF record status has not yet been checked. Run diagnostics to verify your SPF configuration.', 'scalyn-mail-relay' )
					);
				},
				'scalyn-diagnostics-spf-heading'
			);
			?>

			<?php
			DiagnosticResultCard::render(
				__( 'DKIM Records', 'scalyn-mail-relay' ),
				'unknown',
				__( 'Unknown', 'scalyn-mail-relay' ),
				function () {
					EmptyState::render(
						__( 'DKIM record status has not yet been checked. Run diagnostics to verify your DKIM configuration.', 'scalyn-mail-relay' )
					);
				},
				'scalyn-diagnostics-dkim-heading'
			);
			?>

			<?php
			DiagnosticResultCard::render(
				__( 'DMARC Policy', 'scalyn-mail-relay' ),
				'unknown',
				__( 'Unknown', 'scalyn-mail-relay' ),
				function () {
					EmptyState::render(
						__( 'DMARC policy status has not yet been checked. Run diagnostics to verify your DMARC configuration.', 'scalyn-mail-relay' )
					);
				},
				'scalyn-diagnostics-dmarc-heading'
			);
			?>

			<?php
			DiagnosticResultCard::render(
				__( 'Overall Email Health', 'scalyn-mail-relay' ),
				'unknown',
				__( 'Unknown', 'scalyn-mail-relay' ),
				function () {
					echo '<strong class="scalyn-score" aria-label="' . esc_attr__( 'Health score not yet assessed', 'scalyn-mail-relay' ) . '">—</strong>';
					echo '<p class="scalyn-card__note">' . esc_html__( 'Run diagnostics to generate your email health score based on SPF, DKIM, and DMARC verification.', 'scalyn-mail-relay' ) . '</p>';
				},
				'scalyn-diagnostics-health-heading'
			);
			?>

		</div>

		<section class="scalyn-card scalyn-actions-card" aria-labelledby="scalyn-diagnostics-actions-heading">
			<h2 id="scalyn-diagnostics-actions-heading"><?php esc_html_e( 'Run Diagnostics', 'scalyn-mail-relay' ); ?></h2>
			<p class="scalyn-actions__note"><?php esc_html_e( 'Click the button below to run a complete email deliverability diagnostic check. Results will appear in the sections above.', 'scalyn-mail-relay' ); ?></p>
			<div class="scalyn-actions">
				<?php
				ActionButton::render(
					__( 'Run Diagnostics Now', 'scalyn-mail-relay' ),
					$diagnostics_run_url,
					false
				);
				?>
			</div>
		</section>

	<?php endif; ?>
</div>
