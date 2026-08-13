<?php
/**
 * Setup Wizard view.
 *
 * Variables injected by WizardPage::render():
 *   int            $current_step  Current step number (1–7).
 *   int            $total_steps   Total number of wizard steps.
 *   array<int,string> $step_labels Step labels keyed by step number.
 *
 * Steps 3–6 (Configure, Verify, Test Email, Health Check) display explicit
 * placeholder content until Saturn's provider contracts and Yaj's REST
 * endpoints are available. Do not fake successful operations.
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

			case 2:
				?>
				<h2><?php esc_html_e( 'Choose a Mail Provider', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'Select the mail provider you want to use with this site.', 'scalyn-mail-relay' ); ?></p>
				<div class="scalyn-empty-state">
					<p class="scalyn-empty-state__message"><?php esc_html_e( 'No mail providers are registered yet. Provider modules will appear here once they are activated.', 'scalyn-mail-relay' ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'SMTP provider support is coming in the next release.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			case 3:
				?>
				<h2><?php esc_html_e( 'Configure Provider', 'scalyn-mail-relay' ); ?></h2>
				<div class="scalyn-empty-state">
					<p class="scalyn-empty-state__message"><?php esc_html_e( 'Provider configuration is not yet available. Choose a provider in the previous step first.', 'scalyn-mail-relay' ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'This step will display the configuration form for your chosen provider.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			case 4:
				?>
				<h2><?php esc_html_e( 'Verify Connection', 'scalyn-mail-relay' ); ?></h2>
				<div class="scalyn-empty-state">
					<p class="scalyn-empty-state__message"><?php esc_html_e( 'Connection verification is not yet available. Configure your provider in the previous step first.', 'scalyn-mail-relay' ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'Scalyn Mail Relay will test the SMTP handshake and authentication without sending an email.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			case 5:
				?>
				<h2><?php esc_html_e( 'Send Test Email', 'scalyn-mail-relay' ); ?></h2>
				<div class="scalyn-empty-state">
					<p class="scalyn-empty-state__message"><?php esc_html_e( 'Test email sending is not yet available. Verify your connection in the previous step first.', 'scalyn-mail-relay' ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'A test email will confirm end-to-end delivery. Provider acceptance is not the same as inbox delivery.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			case 6:
				?>
				<h2><?php esc_html_e( 'Initial Health Check', 'scalyn-mail-relay' ); ?></h2>
				<div class="scalyn-empty-state">
					<p class="scalyn-empty-state__message"><?php esc_html_e( 'Health check is not yet available. Send a test email in the previous step first.', 'scalyn-mail-relay' ); ?></p>
				</div>
				<p class="description"><?php esc_html_e( 'The health check will verify SPF, DKIM, and DMARC records and produce your first email health score.', 'scalyn-mail-relay' ); ?></p>
				<?php
				break;

			case 7:
				?>
				<h2><?php esc_html_e( 'Setup Wizard Preview Complete', 'scalyn-mail-relay' ); ?></h2>
				<p><?php esc_html_e( 'You have reached the end of the setup wizard. Provider configuration, connection verification, test email, and health checks will become available as their modules are integrated.', 'scalyn-mail-relay' ); ?></p>
				<p class="description"><?php esc_html_e( 'No provider has been configured, no connection has been verified, no email has been sent, and no health score has been generated.', 'scalyn-mail-relay' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=scalyn-mail-relay' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Dashboard', 'scalyn-mail-relay' ); ?>
				</a>
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
