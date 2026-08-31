<?php
/**
 * Diagnostic check registry and management.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registry for diagnostic checks.
 *
 * Holds DiagnosticCheckInterface implementations for organized retrieval and
 * execution. Checks are registered during boot or via the scalyn_mail_relay_booted
 * action, allowing third-party code (especially provider modules) to register
 * checks without modifying Core.
 *
 * Checks are indexed by their get_id() for O(1) lookup by ID and returned
 * as an array for bulk operations (runner iteration).
 *
 * Thread-safety: Registry is mutable during boot and the scalyn_mail_relay_booted
 * action, but should be treated as immutable once DiagnosticRunner receives it.
 * Ownership: Kim / Core.
 */
final class DiagnosticCheckRegistry {

	/**
	 * Registered checks, keyed by ID.
	 *
	 * @var array<string, DiagnosticCheckInterface>
	 */
	private array $checks = array();

	/**
	 * Registers a diagnostic check.
	 *
	 * If a check with the same ID is already registered, it is replaced
	 * (allowing third-party overrides without requiring unregistration).
	 *
	 * @param DiagnosticCheckInterface $check The check to register.
	 */
	public function register( DiagnosticCheckInterface $check ): void {
		$this->checks[ $check->get_id() ] = $check;
	}

	/**
	 * Retrieves all registered checks as an array.
	 *
	 * Returned array is indexed by check ID for convenient keyed access or
	 * iteration. Order matches registration order.
	 *
	 * @return array<string, DiagnosticCheckInterface>
	 */
	public function get_all(): array {
		return $this->checks;
	}

	/**
	 * Retrieves a single check by ID.
	 *
	 * @param string $check_id The check's unique identifier.
	 * @return DiagnosticCheckInterface|null The check, or null if not registered.
	 */
	public function get( string $check_id ): ?DiagnosticCheckInterface {
		return $this->checks[ $check_id ] ?? null;
	}

	/**
	 * Returns whether a check with the given ID is registered.
	 *
	 * @param string $check_id The check's unique identifier.
	 * @return bool True if registered; false otherwise.
	 */
	public function has( string $check_id ): bool {
		return isset( $this->checks[ $check_id ] );
	}

	/**
	 * Returns the number of registered checks.
	 *
	 * @return int The count of registered checks.
	 */
	public function count(): int {
		return count( $this->checks );
	}
}
