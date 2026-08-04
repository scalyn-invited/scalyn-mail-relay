<?php
/**
 * Mail provider registry.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Contracts\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains the set of registered mail providers.
 *
 * Providers are registered during plugin boot (via the scalyn_mail_relay_booted
 * action or a dedicated registration hook) before any mail is dispatched.
 * Only one provider is active at a time; the active provider ID is determined
 * by SettingsRepository::get_active_provider_id().
 *
 * Ownership: Kim / Core.
 * Consumers: MailDispatcher (resolves active provider), Admin UI (lists providers).
 */
final class ProviderRegistry {

	/**
	 * Registered providers, keyed by provider ID.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Registers a mail provider. An existing registration for the same ID is replaced.
	 *
	 * @param ProviderInterface $provider The provider to register.
	 */
	public function register( ProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Returns whether a provider with the given ID is registered.
	 *
	 * @param string $id The provider identifier.
	 */
	public function has( string $id ): bool {
		return isset( $this->providers[ $id ] );
	}

	/**
	 * Returns a registered provider by its ID.
	 *
	 * @param string $id The provider identifier.
	 * @return ProviderInterface
	 * @throws \RuntimeException If no provider with the given ID is registered.
	 */
	public function get( string $id ): ProviderInterface {
		if ( ! isset( $this->providers[ $id ] ) ) {
			throw new \RuntimeException(
				sprintf( 'Mail provider "%s" is not registered.', $id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
			);
		}

		return $this->providers[ $id ];
	}

	/**
	 * Returns all registered providers, keyed by provider ID.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function all(): array {
		return $this->providers;
	}
}
