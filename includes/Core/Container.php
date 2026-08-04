<?php
/**
 * Minimal service container.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

final class Container {
	/** @var array<string, callable|object> */
	private array $entries = array();

	/** @var array<string, object> */
	private array $resolved = array();

	public function set( string $id, callable|object $value ): void {
		$this->entries[ $id ] = $value;
	}

	public function has( string $id ): bool {
		return isset( $this->entries[ $id ] ) || isset( $this->resolved[ $id ] );
	}

	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->entries[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Service "%s" is not registered.', esc_html( $id ) ) );
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
