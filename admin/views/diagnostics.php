<?php
/**
 * Diagnostics page view.
 *
 * Variables injected by DiagnosticsPage::render():
 *   bool                         $provider_configured  Whether a provider is configured and registered.
 *   string                       $wizard_url           URL to Setup Wizard.
 *   string                       $diagnostics_run_url  URL to trigger diagnostics (empty if endpoint not ready).
 *   array<string, array|null>    $diagnostics          Results organized by check type (spf, dkim, dmarc), or null if no results.
 *   int|null                     $health_score         Overall health score (0-100) or null if no results.
 *   string                       $spf_ui_status        UI status for SPF (healthy, warning, critical, unknown).
 *   string                       $dkim_ui_status       UI status for DKIM (healthy, warning, critical, unknown).
 *   string                       $dmarc_ui_status      UI status for DMARC (healthy, warning, critical, unknown).
 *
 * Displays real diagnostic findings when available, or empty states when no diagnostics have been run.
 * Each finding includes: status, message, evidence, impact, and recommended action.
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
			$spf          = $diagnostics['spf'] ?? null;
			$spf_ui_label = $spf ? ucfirst( $spf['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

			DiagnosticResultCard::render(
				__( 'SPF Record', 'scalyn-mail-relay' ),
				$spf_ui_status,
				$spf_ui_label,
				function () use ( $spf ) {
					if ( null === $spf ) {
						EmptyState::render(
							__( 'SPF record status has not yet been checked. Run diagnostics to verify your SPF configuration.', 'scalyn-mail-relay' )
						);
						return;
					}

					echo '<p class="scalyn-finding__message">' . esc_html( $spf['result_message'] ) . '</p>';

					if ( $spf['raw_result'] ) {
						$decoded  = json_decode( $spf['raw_result'], true );
						$evidence = $decoded['evidence'] ?? '';

						if ( $evidence ) {
							echo '<details class="scalyn-finding__details">';
							echo '<summary>' . esc_html__( 'Evidence', 'scalyn-mail-relay' ) . '</summary>';
							echo '<pre class="scalyn-finding__evidence">' . esc_html( $evidence ) . '</pre>';
							echo '</details>';
						}
					}

					if ( $spf['raw_result'] ) {
						$decoded = json_decode( $spf['raw_result'], true );
						$impact  = $decoded['impact'] ?? '';

						if ( $impact ) {
							echo '<p class="scalyn-finding__impact">' . esc_html( $impact ) . '</p>';
						}
					}

					if ( $spf['recommended_action'] ) {
						echo '<p class="scalyn-finding__action">' . esc_html( $spf['recommended_action'] ) . '</p>';
					}
				},
				'scalyn-diagnostics-spf-heading'
			);
			?>

			<?php
			$dkim          = $diagnostics['dkim'] ?? null;
			$dkim_ui_label = $dkim ? ucfirst( $dkim['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

			DiagnosticResultCard::render(
				__( 'DKIM Records', 'scalyn-mail-relay' ),
				$dkim_ui_status,
				$dkim_ui_label,
				function () use ( $dkim ) {
					if ( null === $dkim ) {
						EmptyState::render(
							__( 'DKIM record status has not yet been checked. Run diagnostics to verify your DKIM configuration.', 'scalyn-mail-relay' )
						);
						return;
					}

					echo '<p class="scalyn-finding__message">' . esc_html( $dkim['result_message'] ) . '</p>';

					if ( $dkim['raw_result'] ) {
						$decoded  = json_decode( $dkim['raw_result'], true );
						$evidence = $decoded['evidence'] ?? '';

						if ( $evidence ) {
							echo '<details class="scalyn-finding__details">';
							echo '<summary>' . esc_html__( 'Evidence', 'scalyn-mail-relay' ) . '</summary>';
							echo '<pre class="scalyn-finding__evidence">' . esc_html( $evidence ) . '</pre>';
							echo '</details>';
						}
					}

					if ( $dkim['raw_result'] ) {
						$decoded = json_decode( $dkim['raw_result'], true );
						$impact  = $decoded['impact'] ?? '';

						if ( $impact ) {
							echo '<p class="scalyn-finding__impact">' . esc_html( $impact ) . '</p>';
						}
					}

					if ( $dkim['recommended_action'] ) {
						echo '<p class="scalyn-finding__action">' . esc_html( $dkim['recommended_action'] ) . '</p>';
					}
				},
				'scalyn-diagnostics-dkim-heading'
			);
			?>

			<?php
			$dmarc          = $diagnostics['dmarc'] ?? null;
			$dmarc_ui_label = $dmarc ? ucfirst( $dmarc['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

			DiagnosticResultCard::render(
				__( 'DMARC Policy', 'scalyn-mail-relay' ),
				$dmarc_ui_status,
				$dmarc_ui_label,
				function () use ( $dmarc ) {
					if ( null === $dmarc ) {
						EmptyState::render(
							__( 'DMARC policy status has not yet been checked. Run diagnostics to verify your DMARC configuration.', 'scalyn-mail-relay' )
						);
						return;
					}

					echo '<p class="scalyn-finding__message">' . esc_html( $dmarc['result_message'] ) . '</p>';

					if ( $dmarc['raw_result'] ) {
						$decoded  = json_decode( $dmarc['raw_result'], true );
						$evidence = $decoded['evidence'] ?? '';

						if ( $evidence ) {
							echo '<details class="scalyn-finding__details">';
							echo '<summary>' . esc_html__( 'Evidence', 'scalyn-mail-relay' ) . '</summary>';
							echo '<pre class="scalyn-finding__evidence">' . esc_html( $evidence ) . '</pre>';
							echo '</details>';
						}
					}

					if ( $dmarc['raw_result'] ) {
						$decoded = json_decode( $dmarc['raw_result'], true );
						$impact  = $decoded['impact'] ?? '';

						if ( $impact ) {
							echo '<p class="scalyn-finding__impact">' . esc_html( $impact ) . '</p>';
						}
					}

					if ( $dmarc['recommended_action'] ) {
						echo '<p class="scalyn-finding__action">' . esc_html( $dmarc['recommended_action'] ) . '</p>';
					}
				},
				'scalyn-diagnostics-dmarc-heading'
			);
			?>

			<?php
			$health_ui_status = null !== $health_score ? ( $health_score >= 80 ? 'healthy' : ( $health_score >= 60 ? 'warning' : 'critical' ) ) : 'unknown';
			/* translators: %d is the health score number (0-100) */
			$health_ui_label = null !== $health_score ? sprintf( __( '%d/100', 'scalyn-mail-relay' ), $health_score ) : __( 'Unknown', 'scalyn-mail-relay' );

			DiagnosticResultCard::render(
				__( 'Overall Email Health', 'scalyn-mail-relay' ),
				$health_ui_status,
				$health_ui_label,
				function () use ( $health_score ) {
					if ( null === $health_score ) {
						echo '<strong class="scalyn-score" aria-label="' . esc_attr__( 'Health score not yet assessed', 'scalyn-mail-relay' ) . '">—</strong>';
						echo '<p class="scalyn-card__note">' . esc_html__( 'Run diagnostics to generate your email health score based on SPF, DKIM, and DMARC verification.', 'scalyn-mail-relay' ) . '</p>';
						return;
					}

					/* translators: %d is the health score number (0-100) */
					echo '<strong class="scalyn-score" aria-label="' . esc_attr( sprintf( __( 'Health score: %d out of 100', 'scalyn-mail-relay' ), $health_score ) ) . '">' . esc_html( $health_score ) . '</strong>';
					echo '<p class="scalyn-card__note">' . esc_html__( 'Your email health score is based on the results of SPF, DKIM, and DMARC verification checks.', 'scalyn-mail-relay' ) . '</p>';
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
