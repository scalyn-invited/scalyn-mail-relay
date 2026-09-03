<?php
/**
 * Setup Wizard admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Manages wizard step state and renders the setup wizard view.
 *
 * Prepares view variables from Core services and includes the wizard view
 * template. POST handling is delegated to AdminMenu::handle_wizard_post()
 * via the load-{hook} action, which fires before admin-header.php outputs HTML.
 *
 * Password security: the stored SMTP password is never passed to the view.
 * Only non-sensitive configuration fields are forwarded.
 *
 * Ownership: Kim / Admin.
 */
final class WizardPage {

	/**
	 * Total number of wizard steps.
	 */
	private const TOTAL_STEPS = 6;

	/**
	 * Performs a capability check then renders the wizard view.
	 *
	 * POST handling is done earlier via AdminMenu::handle_wizard_post() on the
	 * load-{hook} action, before admin-header.php outputs HTML.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access the Setup Wizard.', 'scalyn-mail-relay' ) );
		}

		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		$registry  = $container->get( ProviderRegistry::class );

		$current_step = $this->get_current_step();
		$total_steps  = self::TOTAL_STEPS;
		$step_labels  = $this->get_step_labels();

		// Provider data — safe to pass (labels only, no credentials).
		$registered_providers = array(); // id => label.
		foreach ( $registry->all() as $id => $provider ) {
			$registered_providers[ $id ] = $provider->get_label();
		}
		$active_provider_id = $settings->get_active_provider_id();

		// SMTP config for the form — password is intentionally excluded.
		$smtp_raw    = $settings->get_smtp_config();
		$smtp_config = array(
			'host'       => $smtp_raw['host'],
			'port'       => $smtp_raw['port'],
			'encryption' => $smtp_raw['encryption'],
			'username'   => $smtp_raw['username'],
			'from_name'  => $smtp_raw['from_name'],
			'from_email' => $smtp_raw['from_email'],
			// 'password' deliberately omitted — never passed to template.
		);
		$smtp_has_password = '' !== (string) $smtp_raw['password'];

		// Step 3 validation errors (from previous failed POST).
		$step3_errors = $this->consume_transient( 'step3_errors' );

		// Step 4 connection test result (from previous POST).
		$conn_result = $this->consume_transient( 'conn' );

		// Step 5 test email result (from previous POST).
		$email_result = $this->consume_transient( 'email' );

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/wizard.php';
	}

	/**
	 * Returns the current wizard step number, clamped to the valid range 1–TOTAL_STEPS.
	 *
	 * Clamps the URL step to the first incomplete step to prevent skipping ahead beyond
	 * configured state. For example, visiting &step=6 on a fresh install returns 1 because
	 * no steps have been completed.
	 *
	 * Read-only GET navigation — no state is modified; no nonce is required.
	 *
	 * @return int Step number between 1 and TOTAL_STEPS inclusive.
	 */
	private function get_current_step(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only step navigation; no state is changed by this parameter.
		$requested_step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$requested_step = max( 1, min( $requested_step, self::TOTAL_STEPS ) );

		// Get the first incomplete step; don't allow jumping ahead.
		$first_incomplete = $this->get_first_incomplete_step();
		return min( $requested_step, $first_incomplete );
	}

	/**
	 * Returns the first incomplete wizard step based on persisted configuration state.
	 *
	 * Derives completion from:
	 * - Step 1: Always complete (intro).
	 * - Step 2: Complete if a provider is chosen.
	 * - Step 3: Complete if SMTP credentials are saved (host + port + email).
	 * - Step 4: Complete if connection has been verified (mark_provider_verified called).
	 * - Step 5: Complete if provider is verified (test email or real send succeeded).
	 * - Step 6: Always reachable after step 5.
	 *
	 * @return int The first step that is not yet complete.
	 */
	private function get_first_incomplete_step(): int {
		$container = Plugin::instance()->container();
		$settings  = $container->get( SettingsRepository::class );
		$registry  = $container->get( ProviderRegistry::class );

		// Step 1 always complete.

		// Step 2: Check if a provider is chosen.
		$active_provider_id = $settings->get_active_provider_id();
		if ( '' === $active_provider_id || ! $registry->has( $active_provider_id ) ) {
			return 2;
		}

		// Step 3: Check if SMTP credentials are configured.
		$smtp = $settings->get_smtp_config();
		if ( '' === trim( $smtp['host'] ?? '' )
			|| 0 === absint( $smtp['port'] ?? 0 )
			|| '' === trim( $smtp['from_email'] ?? '' ) ) {
			return 3;
		}

		// Step 4: Check if connection has been verified.
		if ( ! $settings->is_provider_verified() ) {
			return 4;
		}

		// Steps 5–6 are reachable once verified.
		return 7; // Return beyond max step to allow all steps to be accessible.
	}

	/**
	 * Returns the ordered wizard step labels keyed by step number.
	 *
	 * @return array<int, string>
	 */
	private function get_step_labels(): array {
		return array(
			1 => __( 'Welcome', 'scalyn-mail-relay' ),
			2 => __( 'Choose Provider', 'scalyn-mail-relay' ),
			3 => __( 'Configure Provider', 'scalyn-mail-relay' ),
			4 => __( 'Verify Connection', 'scalyn-mail-relay' ),
			5 => __( 'Send Test Email', 'scalyn-mail-relay' ),
			6 => __( 'Complete', 'scalyn-mail-relay' ),
		);
	}

	/**
	 * Reads a per-user wizard transient and deletes it (consume-once).
	 *
	 * @param string $slot Transient slot name (e.g. 'conn', 'email', 'step3_errors').
	 * @return array|null The stored array, or null if no transient is set.
	 */
	private function consume_transient( string $slot ): ?array {
		$key   = 'scalyn_wizard_' . $slot . '_' . get_current_user_id();
		$value = get_transient( $key );
		if ( false !== $value ) {
			delete_transient( $key );
			return (array) $value;
		}
		return null;
	}
}
