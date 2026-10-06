<?php
/**
 * Setup Wizard view.
 *
 * Variables injected by WizardPage::render():
 *   int                   $current_step           Current step number (1–7).
 *   int                   $total_steps            Total number of wizard steps.
 *   array<int,string>     $step_labels            Step labels keyed by step number.
 *   array<string,string>  $registered_providers   Provider id => display label.
 *   string                $active_provider_id     Currently active provider ID.
 *   array<string,mixed>   $smtp_config            Safe SMTP fields (no password).
 *   bool                  $smtp_has_password      Whether a stored password exists (never the value).
 *   array                 $sendgrid_settings      Public sender and key presence only.
 *   array|null            $step3_errors           Field-name keys with validation errors, or null.
 *   array|null            $conn_result            Connection test result ['success'=>bool,'message'=>string], or null.
 *   array|null            $email_result           Test email result ['success'=>bool,'message'=>string], or null.
 *
 * @security Never render the SMTP password. The $smtp_config array intentionally
 *           excludes the 'password' key. All dynamic output must be escaped.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;

$wizard_base_url = admin_url( 'admin.php?page=scalyn-mail-relay-wizard' );
?>
<div class="wrap scalyn-mail-relay scalyn-wizard">

	<h1><?php esc_html_e( 'Setup Wizard', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Configure your mail provider in a few simple steps.', 'scalyn-mail-relay' ); ?></p>
	<p class="scalyn-wizard-progress">
		<?php
		/* translators: 1: current step, 2: total steps, 3: step label. */
		echo esc_html( sprintf( __( 'Step %1$d of %2$d · %3$s', 'scalyn-mail-relay' ), $current_step, $total_steps, $step_labels[ $current_step ] ) );
		?>
	</p>

	<nav class="scalyn-wizard-nav" aria-label="<?php esc_attr_e( 'Setup Wizard steps', 'scalyn-mail-relay' ); ?>">
		<ol class="scalyn-wizard-steps">
			<?php foreach ( $step_labels as $num => $label ) : ?>
				<?php
				if ( $num < $current_step ) {
					$step_class  = 'scalyn-wizard-step scalyn-wizard-step--complete';
					$aria_status = __( 'previous step', 'scalyn-mail-relay' );
				} elseif ( $num === $current_step ) {
					$step_class  = 'scalyn-wizard-step scalyn-wizard-step--active';
					$aria_status = __( 'current step', 'scalyn-mail-relay' );
				} else {
					$step_class  = 'scalyn-wizard-step scalyn-wizard-step--pending';
					$aria_status = __( 'not yet started', 'scalyn-mail-relay' );
				}
				?>
				<li class="<?php echo esc_attr( $step_class ); ?>"<?php echo $num === $current_step ? ' aria-current="step"' : ''; ?>>
					<span class="scalyn-wizard-step__number" aria-hidden="true"><?php echo esc_html( (string) $num ); ?></span>
					<span class="scalyn-wizard-step__label"><?php echo esc_html( $label ); ?></span>
					<span class="screen-reader-text"><?php echo esc_html( $aria_status ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<div class="scalyn-wizard-layout">
	<div class="scalyn-wizard-body scalyn-card">
		<?php
		switch ( $current_step ) {
			// -----------------------------------------------------------------
			case 1:
				?>
				<h2><?php esc_html_e( 'Welcome to Scalyn Mail Relay', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Connect your mail provider, verify the connection, send a test email, and run an email health check. Finish with a configuration summary and recommended next steps.', 'scalyn-mail-relay' ); ?></p>
				<ol class="scalyn-wizard-checklist">
					<li><?php esc_html_e( 'Choose a mail provider', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Enter provider credentials', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Verify the connection', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Send a test email', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Run a health check and review next steps', 'scalyn-mail-relay' ); ?></li>
						</ol>
				<p class="description"><?php esc_html_e( 'Provider acceptance does not guarantee inbox delivery. Scalyn Mail Relay will help you identify and resolve deliverability issues.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			// -----------------------------------------------------------------
			case 2:
				?>
				<h2><?php esc_html_e( 'Choose a Mail Provider', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Select the mail provider you want to use with this site.', 'scalyn-mail-relay' ); ?></p>
				<p class="description"><?php esc_html_e( 'Saving changes the active provider immediately. Configure its credentials in the next step; email cannot be sent through an unconfigured provider.', 'scalyn-mail-relay' ); ?></p>

				<?php if ( empty( $registered_providers ) ) : ?>
					<div class="scalyn-empty-state">
						<p class="scalyn-empty-state__message"><?php esc_html_e( 'No mail providers are registered yet. Provider modules will appear here once they are activated.', 'scalyn-mail-relay' ); ?></p>
					</div>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $wizard_base_url ); ?>">
						<?php wp_nonce_field( 'scalyn_wizard_step2' ); ?>
						<input type="hidden" name="wizard_step" value="2" />

						<fieldset class="scalyn-wizard-providers">
							<legend class="screen-reader-text"><?php esc_html_e( 'Available mail providers', 'scalyn-mail-relay' ); ?></legend>
							<?php foreach ( $registered_providers as $provider_key => $provider_label ) : ?>
								<label class="scalyn-provider-option">
									<input
										type="radio"
										name="provider_id"
										value="<?php echo esc_attr( $provider_key ); ?>"
										<?php checked( $active_provider_id, $provider_key ); ?>
									/>
									<span>
										<strong><?php echo esc_html( $provider_label ); ?></strong>
										<span class="scalyn-provider-option__description">
											<?php
											echo esc_html(
												match ( $provider_key ) {
													'smtp' => __( 'Use an existing mail server with a host, port and login supplied by your provider.', 'scalyn-mail-relay' ),
													'brevo' => __( 'Send transactional email through the Brevo HTTPS API using an API key and an authorized sender.', 'scalyn-mail-relay' ),
													'smtp2go' => __( 'Send through the SMTP2GO HTTPS API using an API key and an authorized sender.', 'scalyn-mail-relay' ),
													'sendgrid' => __( 'Send through the SendGrid API using a Mail Send key and a verified sender.', 'scalyn-mail-relay' ),
													'postmark' => __( 'Send transactional email through Postmark using a Live Server API token and a verified sender.', 'scalyn-mail-relay' ),
													default => __( 'Use this registered mail provider for your site.', 'scalyn-mail-relay' ),
												}
											);
											?>
										</span>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>

						<p class="submit">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Save and Continue', 'scalyn-mail-relay' ); ?>
							</button>
						</p>
					</form>
				<?php endif; ?>
				<?php
				break;

			// -----------------------------------------------------------------
			case 3:
				if ( 'brevo' === $active_provider_id ) {
					$brevo_form->render( true );
					break;
				}
				if ( 'smtp2go' === $active_provider_id ) {
					$smtp2go_form->render( true );
					break;
				}
				if ( 'postmark' === $active_provider_id ) {
					if ( is_array( $step3_errors ) && in_array( 'postmark', $step3_errors, true ) ) {
						echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Postmark settings are incomplete or the stored token cannot be read. Check the sender and replace the token below.', 'scalyn-mail-relay' ) . '</p></div>';
					}
					$postmark_form->render( true );
					if ( isset( $postmark_webhook_form ) ) {
						$postmark_webhook_form->render();
					}
					break;
				}
				if ( 'sendgrid' === $active_provider_id ) :
					?>
					<?php if ( is_array( $step3_errors ) && in_array( 'sendgrid', $step3_errors, true ) ) : ?>
						<div class="notice notice-error inline"><p><?php esc_html_e( 'SendGrid settings are incomplete or the stored key cannot be read. Check the sender and replace the key below.', 'scalyn-mail-relay' ); ?></p></div>
					<?php endif; ?>
					<?php $sendgrid_form->render( true ); ?>
					<?php
					break;
				endif;
				$has_error = static function ( string $field ) use ( $step3_errors ): bool {
					return is_array( $step3_errors ) && in_array( $field, $step3_errors, true );
				};
				?>
				<h2><?php esc_html_e( 'Configure SMTP', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Use the connection details supplied by your mail provider. Saving updates the SMTP settings used by this site.', 'scalyn-mail-relay' ); ?></p>

				<?php if ( is_array( $step3_errors ) && ! empty( $step3_errors ) ) : ?>
					<div class="notice notice-error inline">
						<p><?php esc_html_e( 'Please correct the highlighted fields and try again.', 'scalyn-mail-relay' ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( $wizard_base_url ); ?>">
					<?php wp_nonce_field( 'scalyn_wizard_step3' ); ?>
					<input type="hidden" name="wizard_step" value="3" />

					<table class="form-table" role="presentation">
						<tr<?php echo $has_error( 'host' ) ? ' class="scalyn-field-error"' : ''; ?>>
							<th scope="row">
								<label for="smtp_host"><?php esc_html_e( 'SMTP Host', 'scalyn-mail-relay' ); ?> <span class="description">(<?php esc_html_e( 'required', 'scalyn-mail-relay' ); ?>)</span></label>
							</th>
							<td>
								<input
									type="text"
									id="smtp_host"
									name="smtp[host]"
									value="<?php echo esc_attr( $smtp_config['host'] ); ?>"
									class="regular-text"
									autocomplete="off"
								/>
								<?php if ( $has_error( 'host' ) ) : ?>
									<p class="description scalyn-error"><?php esc_html_e( 'SMTP host is required and must not contain invalid characters.', 'scalyn-mail-relay' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr<?php echo $has_error( 'port' ) ? ' class="scalyn-field-error"' : ''; ?>>
							<th scope="row">
								<label for="smtp_port"><?php esc_html_e( 'SMTP Port', 'scalyn-mail-relay' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="smtp_port"
									name="smtp[port]"
									value="<?php echo esc_attr( (string) $smtp_config['port'] ); ?>"
									class="small-text"
									min="1"
									max="65535"
								/>
								<?php if ( $has_error( 'port' ) ) : ?>
									<p class="description scalyn-error"><?php esc_html_e( 'Port must be between 1 and 65535.', 'scalyn-mail-relay' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="smtp_encryption"><?php esc_html_e( 'Encryption', 'scalyn-mail-relay' ); ?></label>
							</th>
							<td>
								<select id="smtp_encryption" name="smtp[encryption]">
									<option value="tls" <?php selected( $smtp_config['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS (STARTTLS) — Recommended', 'scalyn-mail-relay' ); ?></option>
									<option value="ssl" <?php selected( $smtp_config['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL / TLS (SMTPS)', 'scalyn-mail-relay' ); ?></option>
									<option value="none" <?php selected( $smtp_config['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'scalyn-mail-relay' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="smtp_username"><?php esc_html_e( 'SMTP Username', 'scalyn-mail-relay' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="smtp_username"
									name="smtp[username]"
									value="<?php echo esc_attr( $smtp_config['username'] ); ?>"
									class="regular-text"
									autocomplete="username"
								/>
							</td>
						</tr>
						<tr<?php echo $has_error( 'password' ) ? ' class="scalyn-field-error"' : ''; ?>>
							<th scope="row">
								<label for="smtp_password"><?php esc_html_e( 'SMTP Password', 'scalyn-mail-relay' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="smtp_password"
									name="smtp[password]"
									value=""
									class="regular-text"
									autocomplete="new-password"
								/>
								<p class="description">
									<?php if ( $smtp_has_password ) : ?>
										<?php esc_html_e( 'A password is currently stored. Leave blank to keep it unchanged.', 'scalyn-mail-relay' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Leave blank if no authentication is required.', 'scalyn-mail-relay' ); ?>
									<?php endif; ?>
								</p>
								<?php if ( $has_error( 'password' ) ) : ?>
									<p class="description scalyn-error"><?php esc_html_e( 'A password is required when a username is provided.', 'scalyn-mail-relay' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="smtp_from_name"><?php esc_html_e( 'From Name', 'scalyn-mail-relay' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="smtp_from_name"
									name="smtp[from_name]"
									value="<?php echo esc_attr( $smtp_config['from_name'] ); ?>"
									class="regular-text"
								/>
								<p class="description"><?php esc_html_e( 'Optional display name for outgoing emails.', 'scalyn-mail-relay' ); ?></p>
							</td>
						</tr>
						<tr<?php echo $has_error( 'from_email' ) ? ' class="scalyn-field-error"' : ''; ?>>
							<th scope="row">
								<label for="smtp_from_email"><?php esc_html_e( 'From Email Address', 'scalyn-mail-relay' ); ?> <span class="description">(<?php esc_html_e( 'required', 'scalyn-mail-relay' ); ?>)</span></label>
							</th>
							<td>
								<input
									type="email"
									id="smtp_from_email"
									name="smtp[from_email]"
									value="<?php echo esc_attr( $smtp_config['from_email'] ); ?>"
									class="regular-text"
								/>
								<?php if ( $has_error( 'from_email' ) ) : ?>
									<p class="description scalyn-error"><?php esc_html_e( 'A valid sender email address is required.', 'scalyn-mail-relay' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save SMTP Settings', 'scalyn-mail-relay' ); ?>
						</button>
					</p>
				</form>
				<?php
				break;

			// -----------------------------------------------------------------
			case 4:
				?>
				<h2><?php esc_html_e( 'Verify Connection', 'scalyn-mail-relay' ); ?></h2>
				<?php if ( 'brevo' === $active_provider_id ) : ?>
					<p><?php esc_html_e( 'Check Brevo API account access without sending mail. This does not verify transactional activation, sender authorization, sending allowance or delivery.', 'scalyn-mail-relay' ); ?></p>
				<?php elseif ( 'smtp2go' === $active_provider_id ) : ?>
					<p><?php esc_html_e( 'Check API read access without sending mail. Allow /stats/email_cycle on your SMTP2GO key. This does not verify sending permission, sender authorization or delivery.', 'scalyn-mail-relay' ); ?></p>
				<?php elseif ( 'postmark' === $active_provider_id ) : ?>
					<p><?php esc_html_e( 'Check the Postmark Server API token and Live server type without sending email. This does not verify sender authorization, sending allowance or delivery. Sandbox servers are not supported.', 'scalyn-mail-relay' ); ?></p>
				<?php elseif ( 'sendgrid' === $active_provider_id ) : ?>
					<p><?php esc_html_e( 'Validate the SendGrid key and request format in sandbox mode. No email is sent, and this does not prove real-send permission or recipient delivery.', 'scalyn-mail-relay' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Test the connection to your SMTP server. No email is sent during this step.', 'scalyn-mail-relay' ); ?></p>
				<?php endif; ?>

				<?php if ( is_array( $conn_result ) ) : ?>
					<?php if ( $conn_result['success'] ) : ?>
						<div class="notice notice-success inline">
							<p><?php echo esc_html( $conn_result['message'] ); ?></p>
						</div>
					<?php else : ?>
						<div class="notice notice-error inline">
							<p><?php echo esc_html( $conn_result['message'] ); ?></p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( $wizard_base_url ); ?>">
					<?php wp_nonce_field( 'scalyn_wizard_step4' ); ?>
					<input type="hidden" name="wizard_step" value="4" />
					<p>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Run Connection Test', 'scalyn-mail-relay' ); ?>
						</button>
					</p>
				</form>
				<?php
				break;

			// -----------------------------------------------------------------
			case 5:
				?>
				<h2><?php esc_html_e( 'Send Test Email', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Send one real test email to an inbox you control. Provider acceptance is not proof of recipient delivery; check your inbox and provider activity.', 'scalyn-mail-relay' ); ?></p>

				<?php if ( is_array( $email_result ) ) : ?>
					<?php if ( $email_result['success'] ) : ?>
						<div class="notice notice-success inline">
							<p><?php echo esc_html( $email_result['message'] ); ?></p>
						</div>
					<?php else : ?>
						<div class="notice notice-error inline">
							<p><?php echo esc_html( $email_result['message'] ); ?></p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( $wizard_base_url ); ?>">
					<?php wp_nonce_field( 'scalyn_wizard_step5' ); ?>
					<input type="hidden" name="wizard_step" value="5" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="test_recipient"><?php esc_html_e( 'Test Recipient Email', 'scalyn-mail-relay' ); ?></label>
							</th>
							<td>
								<input
									type="email"
									id="test_recipient"
									name="test_recipient"
									value=""
									class="regular-text"
									required
									autocomplete="email"
								/>
								<p class="description"><?php esc_html_e( 'Enter an email address you can check. The test email will be sent to this address.', 'scalyn-mail-relay' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Send Test Email', 'scalyn-mail-relay' ); ?>
						</button>
					</p>
				</form>
				<?php
				break;

			// -----------------------------------------------------------------
			case 6:
				?>
				<h2><?php esc_html_e( 'Health Check', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Run diagnostics for the selected provider and sending domain. Checks contact DNS and, only for SMTP, the configured SMTP server. No test email is sent.', 'scalyn-mail-relay' ); ?></p>
				<?php if ( current_user_can( \Scalyn\MailRelay\Core\Capabilities::RUN_DIAGNOSTICS ) ) : ?>
					<?php require __DIR__ . '/wizard-health.php'; ?>
					<p>
					<button type="button" class="button button-primary" id="scalyn-run-diagnostics" data-scalyn-action="run-diagnostics" data-endpoint="<?php echo esc_url( rest_url( 'scalyn-mail-relay/v1/diagnostics/run' ) ); ?>"><?php esc_html_e( 'Run Health Check', 'scalyn-mail-relay' ); ?></button>
					</p>
					<noscript><p><?php esc_html_e( 'Enable JavaScript to run the health check. No check runs automatically.', 'scalyn-mail-relay' ); ?></p></noscript>
				<?php else : ?>
					<p><?php esc_html_e( 'You do not have permission to run diagnostics or view health results. Ask an administrator to complete the health check.', 'scalyn-mail-relay' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'You may continue without running a check, but this does not mark email health as verified. Changing the provider or its configuration requires a fresh check; previous results remain historical.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			case 7:
				?>
				<h2><?php esc_html_e( 'Provider Configuration Complete', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Review the recorded state before relying on site email. Reaching this step does not confirm that a test email arrived.', 'scalyn-mail-relay' ); ?></p>
				<p><?php esc_html_e( 'Selected provider:', 'scalyn-mail-relay' ); ?> <strong><?php echo esc_html( $registered_providers[ $active_provider_id ] ?? __( 'Unknown', 'scalyn-mail-relay' ) ); ?></strong></p>
				<?php if ( current_user_can( \Scalyn\MailRelay\Core\Capabilities::RUN_DIAGNOSTICS ) ) : ?>
					<?php require __DIR__ . '/wizard-health.php'; ?>
				<?php endif; ?>

				<?php if ( '' !== $active_provider_id ) : ?>
					<p><?php esc_html_e( 'Your mail provider has been configured.', 'scalyn-mail-relay' ); ?></p>
					<p class="description">
						<?php esc_html_e( 'Visit the Diagnostics page to run email health checks, verify DNS configuration (SPF/DKIM/DMARC/MX), and view detailed findings with remediation guidance. Recent mail failures are automatically classified and monitored.', 'scalyn-mail-relay' ); ?>
					</p>
				<?php else : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No mail provider has been configured. Return to step 2 to select and configure a provider.', 'scalyn-mail-relay' ); ?></p>
					</div>
				<?php endif; ?>

				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Dashboard', 'scalyn-mail-relay' ); ?>
					</a>
				</p>
				<?php
				break;
		}
		?>
	<div class="scalyn-wizard-footer">
		<?php if ( $current_step > 1 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'step', $current_step - 1, $wizard_base_url ) ); ?>" class="button scalyn-wizard-btn scalyn-wizard-btn--back">
				&larr; <?php esc_html_e( 'Back', 'scalyn-mail-relay' ); ?>
			</a>
		<?php endif; ?>
		<?php
		// Show Next button only on non-form steps. Steps 2 and 3 have forms with submit buttons.
		$has_form = ( 2 === $current_step || 3 === $current_step );
		?>
		<?php if ( $current_step < $total_steps && ! $has_form ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'step', $current_step + 1, $wizard_base_url ) ); ?>" class="button button-primary scalyn-wizard-btn scalyn-wizard-btn--next">
				<?php echo esc_html( 1 === $current_step ? __( 'Start setup', 'scalyn-mail-relay' ) : __( 'Continue', 'scalyn-mail-relay' ) ); ?> &rarr;
			</a>
		<?php endif; ?>
	</div>
	</div>
	<?php require __DIR__ . '/wizard-help.php'; ?>
	</div>

</div>
