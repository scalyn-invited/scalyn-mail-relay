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

	<nav class="scalyn-wizard-nav" aria-label="<?php esc_attr_e( 'Setup Wizard steps', 'scalyn-mail-relay' ); ?>">
		<ol class="scalyn-wizard-steps">
			<?php foreach ( $step_labels as $num => $label ) : ?>
				<?php
				if ( $num < $current_step ) {
					$step_class  = 'scalyn-wizard-step scalyn-wizard-step--complete';
					$aria_status = __( 'complete', 'scalyn-mail-relay' );
				} elseif ( $num === $current_step ) {
					$step_class  = 'scalyn-wizard-step scalyn-wizard-step--active';
					$aria_status = __( 'current step', 'scalyn-mail-relay' );
				} else {
					$step_class  = 'scalyn-wizard-step scalyn-wizard-step--pending';
					$aria_status = __( 'not yet started', 'scalyn-mail-relay' );
				}
				?>
				<li class="<?php echo esc_attr( $step_class ); ?>">
					<span class="scalyn-wizard-step__number" aria-hidden="true"><?php echo esc_html( (string) $num ); ?></span>
					<span class="scalyn-wizard-step__label"><?php echo esc_html( $label ); ?></span>
					<span class="screen-reader-text"><?php echo esc_html( $aria_status ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<div class="scalyn-wizard-body scalyn-card">
		<?php
		switch ( $current_step ) {
			// -----------------------------------------------------------------
			case 1:
				?>
				<h2><?php esc_html_e( 'Welcome to Scalyn Mail Relay', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'This wizard will guide you through connecting a mail provider, verifying delivery, and generating your first email health score.', 'scalyn-mail-relay' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Choose a mail provider', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Enter provider credentials', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Verify the connection', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Send a test email', 'scalyn-mail-relay' ); ?></li>
					<li><?php esc_html_e( 'Run an initial health check', 'scalyn-mail-relay' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'SMTP acceptance by a provider does not guarantee inbox delivery. Scalyn Mail Relay will help you identify and resolve deliverability issues.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			// -----------------------------------------------------------------
			case 2:
				?>
				<h2><?php esc_html_e( 'Choose a Mail Provider', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Select the mail provider you want to use with this site.', 'scalyn-mail-relay' ); ?></p>

				<?php if ( empty( $registered_providers ) ) : ?>
					<div class="scalyn-empty-state">
						<p class="scalyn-empty-state__message"><?php esc_html_e( 'No mail providers are registered yet. Provider modules will appear here once they are activated.', 'scalyn-mail-relay' ); ?></p>
					</div>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $wizard_base_url ); ?>">
						<?php wp_nonce_field( 'scalyn_wizard_step2' ); ?>
						<input type="hidden" name="wizard_step" value="2" />

						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Available mail providers', 'scalyn-mail-relay' ); ?></legend>
							<?php foreach ( $registered_providers as $provider_key => $provider_label ) : ?>
								<label class="scalyn-provider-option">
									<input
										type="radio"
										name="provider_id"
										value="<?php echo esc_attr( $provider_key ); ?>"
										<?php checked( $active_provider_id, $provider_key ); ?>
									/>
									<?php echo esc_html( $provider_label ); ?>
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
				$has_error = static function ( string $field ) use ( $step3_errors ): bool {
					return is_array( $step3_errors ) && in_array( $field, $step3_errors, true );
				};
				?>
				<h2><?php esc_html_e( 'Configure SMTP', 'scalyn-mail-relay' ); ?></h2>

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
				<p><?php esc_html_e( 'Test the connection to your SMTP server. No email is sent during this step.', 'scalyn-mail-relay' ); ?></p>

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
				<p><?php esc_html_e( 'Send a test email to confirm end-to-end delivery. SMTP server acceptance is not the same as inbox delivery — check your inbox to confirm.', 'scalyn-mail-relay' ); ?></p>

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
				<h2><?php esc_html_e( 'Initial Health Check', 'scalyn-mail-relay' ); ?></h2>
				<div class="scalyn-empty-state">
					<p class="scalyn-empty-state__message"><?php esc_html_e( 'Health check is not yet available. The health check module will verify SPF, DKIM, and DMARC records and produce your first email health score once it is implemented.', 'scalyn-mail-relay' ); ?></p>
				</div>
				<?php
				break;

			// -----------------------------------------------------------------
			case 7:
				?>
				<h2><?php esc_html_e( 'SMTP Configuration Complete', 'scalyn-mail-relay' ); ?></h2>

				<?php if ( '' !== $active_provider_id ) : ?>
					<p><?php esc_html_e( 'Your SMTP mail provider has been configured.', 'scalyn-mail-relay' ); ?></p>
					<p class="description">
						<?php esc_html_e( 'Connection test and test email results are action-based and are not persisted here. Use the connection test and test email steps to verify your SMTP settings. Email health diagnostics are not yet implemented.', 'scalyn-mail-relay' ); ?>
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
	</div>

	<div class="scalyn-wizard-footer">
		<?php if ( $current_step > 1 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'step', $current_step - 1, $wizard_base_url ) ); ?>" class="button scalyn-wizard-btn scalyn-wizard-btn--back">
				&larr; <?php esc_html_e( 'Back', 'scalyn-mail-relay' ); ?>
			</a>
		<?php endif; ?>
		<?php if ( $current_step < $total_steps ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'step', $current_step + 1, $wizard_base_url ) ); ?>" class="button button-primary scalyn-wizard-btn scalyn-wizard-btn--next">
				<?php esc_html_e( 'Next', 'scalyn-mail-relay' ); ?> &rarr;
			</a>
		<?php endif; ?>
	</div>

</div>
