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
 * Ownership: Kim / Core.
 * This class must NOT be modified by Saturn, Yaj or Mikko.
 */
final class MailDispatcher {

	/**
	 * Creates a new mail dispatcher.
	 *
	 * @param ProviderRegistry   $registry The registry of available mail providers.
	 * @param SettingsRepository $settings The repository for reading plugin settings.
	 */
	public function __construct(
		private readonly ProviderRegistry $registry,
		private readonly SettingsRepository $settings
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
			do_action( HookNames::MAIL_FAILED, $result, $message );
			return $result;
		}

		if ( ! $this->registry->has( $provider_id ) ) {
			$result = new SendResult( false, $provider_id, null, null, 'Configured mail provider is not registered.', false, 'config' );
			do_action( HookNames::MAIL_FAILED, $result, $message );
			return $result;
		}

		$provider = $this->registry->get( $provider_id );
		$config   = $this->settings->get_provider_config( $provider_id );
		$result   = $provider->send( $message, $config );

		if ( $result->success ) {
			do_action( HookNames::MAIL_SENT, $result, $message );
		} else {
			do_action( HookNames::MAIL_FAILED, $result, $message );
		}

		return $result;
	}
}
