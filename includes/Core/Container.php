<?php
/**
 * Minimal service container.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy-resolving service locator.
 *
 * Services are registered as callables (factories) or pre-built objects.
 * Callable entries are resolved on first access and the result is cached —
 * every subsequent call to get() returns the same instance.
 */
final class Container {

	/**
	 * Registered service factories or objects, keyed by service ID.
	 *
	 * @var array<string, callable|object>
	 */
	private array $entries = array();

	/**
	 * Resolved service instances, keyed by service ID.
	 *
	 * @var array<string, object>
	 */
	private array $resolved = array();

	/**
	 * Registers a service factory or a pre-built object under the given ID.
	 *
	 * @param string          $id    The service identifier (typically a class name).
	 * @param callable|object $value A factory callable or an already-constructed object.
	 */
	public function set( string $id, callable|object $value ): void {
		$this->entries[ $id ] = $value;
	}

	/**
	 * Returns whether a service is registered under the given ID.
	 *
	 * @param string $id The service identifier.
	 */
	public function has( string $id ): bool {
		return isset( $this->entries[ $id ] ) || isset( $this->resolved[ $id ] );
	}

	/**
	 * Returns the resolved service for the given ID.
	 *
	 * If the registered value is a callable, it is invoked with this container
	 * as its argument, and the result is cached for subsequent calls.
	 *
	 * @param string $id The service identifier.
	 * @return object The resolved service instance.
	 * @throws \RuntimeException If no service is registered under $id.
	 * @throws \RuntimeException If the factory does not return an object.
	 */
	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->entries[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Service "%s" is not registered.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
		}

		$entry = $this->entries[ $id ];
		$value = is_callable( $entry ) ? $entry( $this ) : $entry;

		if ( ! is_object( $value ) ) {
			throw new \RuntimeException( 'Container services must resolve to objects.' );
		}

		$this->resolved[ $id ] = $value;
		return $value;
	}
}
