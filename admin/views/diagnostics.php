<?php
/**
 * Diagnostics page view.
 *
 * Variables injected by DiagnosticsPage::render():
 *   bool                         $provider_configured   Whether a provider is configured and registered.
 *   string                       $wizard_url            URL to Setup Wizard.
 *   string                       $diagnostics_run_url   URL to trigger diagnostics (empty if endpoint not ready).
 *   array<string, array|null>    $diagnostics           Results organized by check type, or null if no results.
 *   int|null                     $health_score          Overall health score (0-100) or null if no results.
 *   string                       $spf_ui_status         UI status for SPF (healthy, warning, critical, unknown).
 *   string                       $spf_severity          CSS class for SPF severity (scalyn-severity-*).
 *   string                       $mx_ui_status          UI status for MX (healthy, warning, critical, unknown).
 *   string                       $mx_severity           CSS class for MX severity.
 *   string                       $dkim_ui_status        UI status for DKIM (healthy, warning, critical, unknown).
 *   string                       $dkim_severity         CSS class for DKIM severity.
 *   string                       $dmarc_ui_status       UI status for DMARC (healthy, warning, critical, unknown).
 *   string                       $dmarc_severity        CSS class for DMARC severity.
 *   string                       $smtp_tls_ui_status    UI status for SMTP/TLS (healthy, warning, critical, unknown).
 *   string                       $smtp_tls_severity     CSS class for SMTP/TLS severity.
 *   array<int, array>            $recent_failures       Recent failed sends with classified category and remediation.
 *
 * Displays real diagnostic findings organized by category (DNS vs Provider checks).
 * Each finding includes: status, message, evidence, impact, severity, and recommended action.
 * Also displays recent mail send failures with deterministic failure classification and remediation.
 *
 * Privacy: Do not render credentials, SMTP transcripts, provider metadata,
 * email bodies, or recipient information. Render only sanitized evidence and status.
 *
 * @package ScalynMailRelay
 */

use Scalyn\MailRelay\Admin\Components\ActionButton;
use Scalyn\MailRelay\Admin\Components\DiagnosticResultCard;
use Scalyn\MailRelay\Admin\Components\EvidenceDisplay;
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

		<!-- DNS Validation Checks -->
		<section class="scalyn-diagnostics-section" aria-labelledby="scalyn-diagnostics-dns-heading">
			<h2 id="scalyn-diagnostics-dns-heading" class="scalyn-diagnostics-section__title"><?php esc_html_e( 'DNS Configuration', 'scalyn-mail-relay' ); ?></h2>
			<p class="scalyn-diagnostics-section__description"><?php esc_html_e( 'Verify SPF, DKIM, DMARC, and MX records to ensure email deliverability.', 'scalyn-mail-relay' ); ?></p>

			<div class="scalyn-diagnostics-grid">

				<?php
				$spf          = $diagnostics['spf'] ?? null;
				$spf_ui_label = $spf ? ucfirst( $spf['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

				DiagnosticResultCard::render(
					__( 'SPF Record', 'scalyn-mail-relay' ),
					$spf_ui_status,
					$spf_ui_label,
					function () use ( $spf, $spf_severity ) {
						if ( null === $spf ) {
							EmptyState::render(
								__( 'SPF record status has not yet been checked. Run diagnostics to verify your SPF configuration.', 'scalyn-mail-relay' )
							);
							return;
						}

						echo '<p class="scalyn-finding__message">' . esc_html( $spf['result_message'] ) . '</p>';
						EvidenceDisplay::render( $spf, 'SPF Record', 'pass' !== $spf['status'] );

						if ( $spf['recommended_action'] ) {
							echo '<p class="scalyn-finding__action">' . esc_html( $spf['recommended_action'] ) . '</p>';
						}
					},
					'scalyn-diagnostics-spf-heading'
				);
				?>

				<?php
				$mx          = $diagnostics['mx'] ?? null;
				$mx_ui_label = $mx ? ucfirst( $mx['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

				DiagnosticResultCard::render(
					__( 'MX Records', 'scalyn-mail-relay' ),
					$mx_ui_status,
					$mx_ui_label,
					function () use ( $mx, $mx_severity ) {
						if ( null === $mx ) {
							EmptyState::render(
								__( 'MX record status has not yet been checked. Run diagnostics to verify your MX configuration.', 'scalyn-mail-relay' )
							);
							return;
						}

						echo '<p class="scalyn-finding__message">' . esc_html( $mx['result_message'] ) . '</p>';
						EvidenceDisplay::render( $mx, 'MX Records', 'pass' !== $mx['status'] );

						if ( $mx['recommended_action'] ) {
							echo '<p class="scalyn-finding__action">' . esc_html( $mx['recommended_action'] ) . '</p>';
						}
					},
					'scalyn-diagnostics-mx-heading'
				);
				?>

				<?php
				$dkim          = $diagnostics['dkim'] ?? null;
				$dkim_ui_label = $dkim ? ucfirst( $dkim['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

				DiagnosticResultCard::render(
					__( 'DKIM Records', 'scalyn-mail-relay' ),
					$dkim_ui_status,
					$dkim_ui_label,
					function () use ( $dkim, $dkim_severity ) {
						if ( null === $dkim ) {
							EmptyState::render(
								__( 'DKIM record status has not yet been checked. Run diagnostics to verify your DKIM configuration.', 'scalyn-mail-relay' )
							);
							return;
						}

						echo '<p class="scalyn-finding__message">' . esc_html( $dkim['result_message'] ) . '</p>';
						EvidenceDisplay::render( $dkim, 'DKIM Records', 'pass' !== $dkim['status'] );

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
					function () use ( $dmarc, $dmarc_severity ) {
						if ( null === $dmarc ) {
							EmptyState::render(
								__( 'DMARC policy status has not yet been checked. Run diagnostics to verify your DMARC configuration.', 'scalyn-mail-relay' )
							);
							return;
						}

						echo '<p class="scalyn-finding__message">' . esc_html( $dmarc['result_message'] ) . '</p>';
						EvidenceDisplay::render( $dmarc, 'DMARC Policy', 'pass' !== $dmarc['status'] );

						if ( $dmarc['recommended_action'] ) {
							echo '<p class="scalyn-finding__action">' . esc_html( $dmarc['recommended_action'] ) . '</p>';
						}
					},
					'scalyn-diagnostics-dmarc-heading'
				);
				?>

			</div>
		</section>

		<!-- Provider Health Checks -->
		<section class="scalyn-diagnostics-section" aria-labelledby="scalyn-diagnostics-provider-heading">
			<h2 id="scalyn-diagnostics-provider-heading" class="scalyn-diagnostics-section__title"><?php esc_html_e( 'Provider Health', 'scalyn-mail-relay' ); ?></h2>
			<p class="scalyn-diagnostics-section__description"><?php esc_html_e( 'Verify SMTP server reachability, TLS support, and certificate validity.', 'scalyn-mail-relay' ); ?></p>

			<div class="scalyn-diagnostics-grid">

				<?php
				$smtp_tls          = $diagnostics['smtp_tls'] ?? null;
				$smtp_tls_ui_label = $smtp_tls ? ucfirst( $smtp_tls['status'] ) : __( 'Unknown', 'scalyn-mail-relay' );

				DiagnosticResultCard::render(
					__( 'SMTP/TLS Configuration', 'scalyn-mail-relay' ),
					$smtp_tls_ui_status,
					$smtp_tls_ui_label,
					function () use ( $smtp_tls, $smtp_tls_severity ) {
						if ( null === $smtp_tls ) {
							EmptyState::render(
								__( 'SMTP/TLS server status has not yet been checked. Run diagnostics to verify your SMTP provider configuration.', 'scalyn-mail-relay' )
							);
							return;
						}

						echo '<p class="scalyn-finding__message">' . esc_html( $smtp_tls['result_message'] ) . '</p>';
						EvidenceDisplay::render( $smtp_tls, 'SMTP/TLS Configuration', 'pass' !== $smtp_tls['status'] );

						if ( $smtp_tls['recommended_action'] ) {
							echo '<p class="scalyn-finding__action">' . esc_html( $smtp_tls['recommended_action'] ) . '</p>';
						}
					},
					'scalyn-diagnostics-smtp-tls-heading'
				);
				?>

			</div>
		</section>

		<!-- Overall Health Score -->
		<section class="scalyn-diagnostics-section" aria-labelledby="scalyn-diagnostics-health-section-heading">
			<h2 id="scalyn-diagnostics-health-section-heading" class="scalyn-diagnostics-section__title"><?php esc_html_e( 'Overall Email Health', 'scalyn-mail-relay' ); ?></h2>

			<div class="scalyn-diagnostics-grid">
				<?php
				$health_ui_status = null !== $health_score ? ( $health_score >= 80 ? 'healthy' : ( $health_score >= 60 ? 'warning' : 'critical' ) ) : 'unknown';
				/* translators: %d is the health score number (0-100) */
				$health_ui_label = null !== $health_score ? sprintf( __( '%d/100', 'scalyn-mail-relay' ), $health_score ) : __( 'Unknown', 'scalyn-mail-relay' );

				DiagnosticResultCard::render(
					__( 'Health Score', 'scalyn-mail-relay' ),
					$health_ui_status,
					$health_ui_label,
					function () use ( $health_score ) {
						if ( null === $health_score ) {
							echo '<strong class="scalyn-score" aria-label="' . esc_attr__( 'Health score not yet assessed', 'scalyn-mail-relay' ) . '">—</strong>';
							echo '<p class="scalyn-card__note">' . esc_html__( 'Run diagnostics to generate your email health score based on SPF, DKIM, DMARC, MX, and SMTP/TLS verification.', 'scalyn-mail-relay' ) . '</p>';
							return;
						}

						/* translators: %d is the health score number (0-100) */
						echo '<strong class="scalyn-score" aria-label="' . esc_attr( sprintf( __( 'Health score: %d out of 100', 'scalyn-mail-relay' ), $health_score ) ) . '">' . esc_html( $health_score ) . '</strong>';
						echo '<p class="scalyn-card__note">' . esc_html__( 'Your email health score is based on the results of all diagnostic checks (SPF, DKIM, DMARC, MX, and SMTP/TLS).', 'scalyn-mail-relay' ) . '</p>';
					},
					'scalyn-diagnostics-health-card-heading'
				);
				?>
			</div>
		</section>

		<!-- Recent Failure Classifications -->
		<?php if ( ! empty( $recent_failures ) ) : ?>
			<section class="scalyn-diagnostics-section" aria-labelledby="scalyn-diagnostics-failures-heading">
				<h2 id="scalyn-diagnostics-failures-heading" class="scalyn-diagnostics-section__title"><?php esc_html_e( 'Recent Send Failures', 'scalyn-mail-relay' ); ?></h2>
				<p class="scalyn-diagnostics-section__description"><?php esc_html_e( 'Recent mail delivery failures classified by type with remediation guidance.', 'scalyn-mail-relay' ); ?></p>

				<div class="scalyn-failures-list">
					<?php foreach ( $recent_failures as $failure ) : ?>
						<div class="scalyn-failure-item scalyn-card">
							<div class="scalyn-failure-header">
								<span class="scalyn-failure-category scalyn-badge scalyn-badge--<?php echo esc_attr( $failure['category'] ); ?>">
									<?php echo esc_html( ucfirst( str_replace( '-', ' ', $failure['category'] ) ) ); ?>
								</span>
								<span class="scalyn-failure-provider"><?php echo esc_html( $failure['provider'] ); ?></span>
								<?php if ( $failure['failed_at'] ) : ?>
									<span class="scalyn-failure-time" title="<?php echo esc_attr( $failure['failed_at'] ); ?>">
										<?php echo esc_html( $failure['failed_at'] ); ?>
									</span>
								<?php endif; ?>
							</div>

							<div class="scalyn-failure-content">
								<p class="scalyn-failure-remediation">
									<strong><?php esc_html_e( 'Remediation:', 'scalyn-mail-relay' ); ?></strong> <?php echo esc_html( $failure['remediation'] ); ?>
								</p>
								<?php if ( $failure['evidence'] ) : ?>
									<p class="scalyn-failure-evidence">
										<small><?php echo esc_html( $failure['evidence'] ); ?></small>
									</p>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<!-- Run Diagnostics Action -->
		<section class="scalyn-card scalyn-actions-card" aria-labelledby="scalyn-diagnostics-actions-heading">
			<h2 id="scalyn-diagnostics-actions-heading"><?php esc_html_e( 'Run Diagnostics', 'scalyn-mail-relay' ); ?></h2>
			<p class="scalyn-actions__note"><?php esc_html_e( 'Click the button below to run a complete email deliverability diagnostic check. This includes DNS validation (SPF, DKIM, DMARC, MX) and provider health checks (SMTP/TLS). Results will appear in the sections above.', 'scalyn-mail-relay' ); ?></p>
			<div class="scalyn-actions">
				<?php
				ActionButton::render(
					__( 'Run Diagnostics Now', 'scalyn-mail-relay' ),
					$diagnostics_run_url,
					false,
					'scalyn-run-diagnostics',
					array( 'scalyn-action' => 'run-diagnostics', 'endpoint' => $diagnostics_run_url )
				);
				?>
			</div>
		</section>

	<?php endif; ?>
</div>
