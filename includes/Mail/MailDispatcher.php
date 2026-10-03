<?php
/**
 * Core mail dispatch orchestrator.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Delivery\DeliveryTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the complete mail dispatch sequence.
 *
 * Responsibilities (in order):
 *   1. Verify that an active provider is configured.
 *   2. Resolve the active provider from the registry.
 *   3. Retrieve the provider configuration from settings.
 *   4. Delegate the send operation to the provider.
 *   5. Publish a lifecycle hook so other modules can respond without coupling.
 *   6. Return the normalized SendResult to the caller.
 *
 * MailDispatcher intentionally has no knowledge of database repositories,
 * timeline writers, or logging services. Those modules subscribe to
 * HookNames::MAIL_SENT and HookNames::MAIL_FAILED and act independently.
 *
 * Ownership: Bernie / Core.
 */
final class MailDispatcher {

	/**
	 * Creates a new mail dispatcher.
	 *
	 * @param ProviderRegistry     $registry The registry of available mail providers.
	 * @param SettingsRepository   $settings The repository for reading plugin settings.
	 * @param DeliveryTracker|null $tracker Optional opted-in delivery evidence associations.
	 */
	public function __construct(
		private readonly ProviderRegistry $registry,
		private readonly SettingsRepository $settings,
		private readonly ?DeliveryTracker $tracker = null
	) {}

	/**
	 * Dispatches a prepared mail message through the active provider.
	 *
	 * On provider acceptance, fires HookNames::MAIL_SENT.
	 * On provider failure or configuration error, fires HookNames::MAIL_FAILED.
	 *
	 * @param MailMessage $message The prepared message to send.
	 * @return SendResult The normalized result from the provider.
	 */
	public function dispatch( MailMessage $message ): SendResult {
		$provider_id = $this->settings->get_active_provider_id();

		if ( '' === $provider_id ) {
			$result = new SendResult( false, '', null, null, 'No mail provider is configured.', false, 'config' );
			$this->publish( HookNames::MAIL_FAILED, $result, $message );
			return $result;
		}

		if ( ! $this->registry->has( $provider_id ) ) {
			$result = new SendResult( false, $provider_id, null, null, 'Configured mail provider is not registered.', false, 'config' );
			$this->publish( HookNames::MAIL_FAILED, $result, $message );
			return $result;
		}

		$provider = $this->registry->get( $provider_id );
		try {
			$config = $this->settings->get_provider_config( $provider_id );
		} catch ( \Throwable $error ) {
			$result = new SendResult( false, $provider_id, null, null, 'Stored provider credentials are unavailable. Replace the key in Providers.', false, 'config' );
			$this->publish( HookNames::MAIL_FAILED, $result, $message );
			return $result;
		}
		// Optional tracking must be associated before submission and can never block it.
		$association = null;
		try {
			$association = $this->tracker?->prepare( $message, $provider_id );
		} catch ( \Throwable $error ) {
			$association = null;
		}
		try {
			$result = $provider->send( $message, $config );
		} catch ( \Throwable $error ) {
			$result = new SendResult( false, $provider_id, null, null, 'Provider acceptance is unconfirmed. Check provider activity before retrying.', false, 'unknown', array(), true );
		}
		try {
			$this->tracker?->acknowledge( $association, $result );
		} catch ( \Throwable $error ) {
			// Acknowledgement binding is evidence metadata; it never changes the outcome.
			unset( $error );
		}

		if ( $result->success ) {
			$this->publish( HookNames::MAIL_SENT, $result, $message );
		} elseif ( $result->acceptance_unconfirmed ) {
			$this->publish( HookNames::MAIL_OUTCOME_UNCONFIRMED, $result, $message );
		} else {
			$this->publish( HookNames::MAIL_FAILED, $result, $message );
		}

		return $result;
	}

	/**
	 * Observer errors cannot reverse an observed outcome or trigger another send.
	 *
	 * @param string      $hook Established lifecycle hook.
	 * @param SendResult  $result Normalized provider result.
	 * @param MailMessage $message Correlated message.
	 */
	private function publish( string $hook, SendResult $result, MailMessage $message ): void {
		try {
			do_action( $hook, $result, $message );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed text only; never include the observer exception or mail content.
			error_log( 'Scalyn Mail Relay: mail outcome observer failed.' );
		}
	}
}
