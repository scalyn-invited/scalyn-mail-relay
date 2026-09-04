<?php
/**
 * Setup Wizard POST action handler.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all wizard POST submissions (steps 2–5).
 *
 * Each handle_stepN() method follows the same sequence:
 *   1. Capability check — wp_die() on failure.
 *   2. Nonce verification — check_admin_referer() dies on invalid nonce.
 *   3. Input unslash and sanitize.
 *   4. Validate (where applicable).
 *   5. Call the appropriate Core service.
 *   6. Store a short-lived, per-user, credential-free result transient (steps 4–5 only).
 *   7. Redirect to the next or current step.
 *
 * @security SMTP credentials must never appear in transient values, redirect
 * URLs, output buffers, or exception messages. All result data stored or
 * rendered must be normalized safe strings only.
 *
 * Ownership: Kim / Admin.
 */
final class WizardController {

	/**
	 * Transient TTL in seconds (single page-redirect cycle).
	 */
	private const TRANSIENT_TTL = 60;

	/**
	 * Wizard admin page base URL slug.
	 */
	private const PAGE_SLUG = 'scalyn-mail-relay-wizard';

	/**
	 * Routes the current POST request to the correct step handler.
	 *
	 * Reads the submitted hidden 'wizard_step' field to determine which
	 * handler to invoke. Ignores GET requests silently.
	 *
	 * @return void
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_METHOD is a server-controlled value, not user input.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each handler verifies its own step-specific nonce.
		$step = isset( $_POST['wizard_step'] ) ? absint( wp_unslash( $_POST['wizard_step'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch ( $step ) {
			case 2:
				$this->handle_step2();
				break;
			case 3:
				$this->handle_step3();
				break;
			case 4:
				$this->handle_step4();
				break;
			case 5:
				$this->handle_step5();
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Step 2 — Provider selection
	// -------------------------------------------------------------------------

	/**
	 * Saves the selected mail provider as the active provider.
	 *
	 * On success, redirects to step 3. On capability or nonce failure, wp_die()
	 * is called before any state is changed.
	 */
	private function handle_step2(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change mail provider settings.', 'scalyn-mail-relay' ) );
		}

		check_admin_referer( 'scalyn_wizard_step2' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below.
		$raw_id = isset( $_POST['provider_id'] ) ? wp_unslash( $_POST['provider_id'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$provider_id = sanitize_text_field( (string) $raw_id );

		$registry = $this->get_registry();

		if ( '' === $provider_id || ! $registry->has( $provider_id ) ) {
			// Redirect back to step 2 — invalid or unregistered provider.
			wp_safe_redirect( $this->step_url( 2 ) );
			exit;
		}

		$this->get_settings()->save( array( 'provider' => array( 'active' => $provider_id ) ) );

		wp_safe_redirect( $this->step_url( 3 ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Step 3 — SMTP configuration
	// -------------------------------------------------------------------------

	/**
	 * Validates and saves the submitted SMTP configuration.
	 *
	 * A blank password field preserves the currently stored password.
	 * The stored password is never re-rendered or returned in validation errors.
	 *
	 * On validation failure, stores normalized error keys in a transient and
	 * redirects back to step 3. On success, redirects to step 4.
	 *
	 * @security The submitted config array (which may contain a raw password)
	 * is never stored in a transient, logged, or passed to output buffers.
	 */
	private function handle_step3(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change SMTP settings.', 'scalyn-mail-relay' ) );
		}

		check_admin_referer( 'scalyn_wizard_step3' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields are sanitized individually below.
		$smtp_post = isset( $_POST['smtp'] ) && is_array( $_POST['smtp'] ) ? wp_unslash( $_POST['smtp'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$settings = $this->get_settings();

		// Build the effective config for validation. When the submitted password
		// is blank, substitute the currently stored password so that
		// SmtpProvider::validate_config() tests the real authentication config.
		$submitted_password = (string) ( $smtp_post['password'] ?? '' );
		$stored_password    = (string) $settings->get_smtp_config()['password'];
		$effective_password = '' !== $submitted_password ? $submitted_password : $stored_password;

		$effective_config = array(
			'host'       => sanitize_text_field( (string) ( $smtp_post['host'] ?? '' ) ),
			'port'       => absint( $smtp_post['port'] ?? 587 ),
			'encryption' => (string) ( $smtp_post['encryption'] ?? 'tls' ),
			'username'   => sanitize_text_field( (string) ( $smtp_post['username'] ?? '' ) ),
			'password'   => $effective_password,
			'from_name'  => sanitize_text_field( (string) ( $smtp_post['from_name'] ?? '' ) ),
			'from_email' => sanitize_email( (string) ( $smtp_post['from_email'] ?? '' ) ),
		);

		$provider   = $this->get_registry()->get( 'smtp' );
		$validation = $provider->validate_config( $effective_config );

		if ( ! $validation->valid ) {
			// Store only field-name keys (no values, no credentials) so the
			// view can highlight which fields need correction.
			set_transient(
				$this->transient_key( 'step3_errors' ),
				array_keys( $validation->errors ),
				self::TRANSIENT_TTL
			);
			wp_safe_redirect( $this->step_url( 3 ) );
			exit;
		}

		// Save the raw submitted SMTP block. SettingsRepository::sanitize()
		// will preserve the existing stored password when the submitted
		// password field is blank.
		$settings->save( array( 'smtp' => $smtp_post ) );

		wp_safe_redirect( $this->step_url( 4 ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Step 4 — Connection verification
	// -------------------------------------------------------------------------

	/**
	 * Runs the provider connection test and stores a normalized, credential-free
	 * result in a short-lived per-user transient, then redirects to step 4 GET.
	 *
	 * @security The ConnectionResult message is a normalized safe string from
	 * SmtpProvider::normalize_connection_message(). Raw PHPMailer exceptions,
	 * debug output, and SMTP credentials are never stored.
	 */
	private function handle_step4(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to run a connection test.', 'scalyn-mail-relay' ) );
		}

		check_admin_referer( 'scalyn_wizard_step4' );

		$settings    = $this->get_settings();
		$registry    = $this->get_registry();
		$provider_id = $settings->get_active_provider_id();

		if ( '' === $provider_id || ! $registry->has( $provider_id ) ) {
			set_transient(
				$this->transient_key( 'conn' ),
				array(
					'success' => false,
					'message' => __( 'No mail provider is configured. Complete steps 2 and 3 first.', 'scalyn-mail-relay' ),
				),
				self::TRANSIENT_TTL
			);
			wp_safe_redirect( $this->step_url( 4 ) );
			exit;
		}

		$provider = $registry->get( $provider_id );
		$config   = $settings->get_provider_config( $provider_id );
		$result   = $provider->test_connection( $config );

		// A successful connection test verifies the provider. WizardPage clamps
		// navigation to step 4 until the provider is verified, so without this
		// the wizard could never advance to step 5.
		if ( $result->success ) {
			$settings->mark_provider_verified();
		}

		// @security Store only the normalized boolean and message string.
		// Never store $config, $result->metadata, or any credential-bearing data.
		set_transient(
			$this->transient_key( 'conn' ),
			array(
				'success' => $result->success,
				'message' => $result->message,
			),
			self::TRANSIENT_TTL
		);

		wp_safe_redirect( $this->step_url( 4 ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Step 5 — Test email
	// -------------------------------------------------------------------------

	/**
	 * Dispatches a test email through the existing MailDispatcher orchestration
	 * path and stores a normalized result in a short-lived per-user transient.
	 *
	 * @security SendResult::response_message is a normalized safe string from
	 * SmtpProvider. The word "delivered" must never appear in the stored message.
	 */
	private function handle_step5(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to send a test email.', 'scalyn-mail-relay' ) );
		}

		check_admin_referer( 'scalyn_wizard_step5' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below.
		$raw_recipient = isset( $_POST['test_recipient'] ) ? wp_unslash( $_POST['test_recipient'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$recipient = sanitize_email( (string) $raw_recipient );

		if ( '' === $recipient || false === filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ) {
			set_transient(
				$this->transient_key( 'email' ),
				array(
					'success' => false,
					'message' => __( 'A valid recipient email address is required.', 'scalyn-mail-relay' ),
				),
				self::TRANSIENT_TTL
			);
			wp_safe_redirect( $this->step_url( 5 ) );
			exit;
		}

		$settings    = $this->get_settings();
		$smtp_config = $settings->get_smtp_config();
		$from_email  = (string) ( $smtp_config['from_email'] ?? '' );
		$from_name   = (string) ( $smtp_config['from_name'] ?? '' );
		$from        = '' !== $from_name
			? $from_name . ' <' . $from_email . '>'
			: $from_email;

		$message = new MailMessage(
			uuid:    wp_generate_uuid4(),
			from:    $from,
			to:      array( $recipient ),
			subject: __( 'Scalyn Mail Relay — Test Email', 'scalyn-mail-relay' ),
			body:    '<p>' . esc_html__( 'This is a test email sent from the Scalyn Mail Relay Setup Wizard.', 'scalyn-mail-relay' ) . '</p>'
				. '<p>' . esc_html__( 'If you received this message, your SMTP server accepted the test email.', 'scalyn-mail-relay' ) . '</p>',
			content_type: 'text/html',
			context: array(
				'source' => 'wizard_test',
				'step'   => 5,
			)
		);

		$dispatcher = $this->get_dispatcher();
		$result     = $dispatcher->dispatch( $message );

		// A test email accepted by the provider also verifies the provider
		// (see SettingsRepository::mark_provider_verified()).
		if ( $result->success ) {
			$settings->mark_provider_verified();
		}

		$safe_message = $result->success
			? __( 'The configured SMTP server accepted the test email. Check your inbox to confirm receipt.', 'scalyn-mail-relay' )
			: (string) ( $result->response_message ?? __( 'The test email could not be sent. Check your SMTP configuration.', 'scalyn-mail-relay' ) );

		// @security Store only normalized boolean and safe message string.
		set_transient(
			$this->transient_key( 'email' ),
			array(
				'success' => $result->success,
				'message' => $safe_message,
			),
			self::TRANSIENT_TTL
		);

		wp_safe_redirect( $this->step_url( 5 ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the per-user transient key for the given slot.
	 *
	 * @param string $slot Logical slot name (e.g. 'conn', 'email', 'step3_errors').
	 * @return string Transient key.
	 */
	private function transient_key( string $slot ): string {
		return 'scalyn_wizard_' . $slot . '_' . get_current_user_id();
	}

	/**
	 * Returns the admin URL for the given wizard step.
	 *
	 * @param int $step Step number.
	 * @return string
	 */
	private function step_url( int $step ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'step' => $step,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Returns the SettingsRepository from the plugin container.
	 *
	 * @return SettingsRepository
	 */
	private function get_settings(): SettingsRepository {
		return Plugin::instance()->container()->get( SettingsRepository::class );
	}

	/**
	 * Returns the ProviderRegistry from the plugin container.
	 *
	 * @return ProviderRegistry
	 */
	private function get_registry(): ProviderRegistry {
		return Plugin::instance()->container()->get( ProviderRegistry::class );
	}

	/**
	 * Returns the MailDispatcher from the plugin container.
	 *
	 * @return MailDispatcher
	 */
	private function get_dispatcher(): MailDispatcher {
		return Plugin::instance()->container()->get( MailDispatcher::class );
	}
}
