<?php
/**
 * Provider configuration validation result value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result returned by ProviderInterface::validate_config().
 *
 * Error array shape — each element is a string keyed by the invalid field name:
 *   array( 'host' => 'Host is required.', 'port' => 'Port must be a positive integer.' )
 *
 * When $valid is true the $errors array will be empty.
 */
final class ValidationResult {

	/**
	 * Creates a new configuration validation result.
	 *
	 * @param bool     $valid  Whether the configuration passed validation.
	 * @param string[] $errors Map of field name to human-readable error message.
	 *                         Empty when $valid is true.
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly array $errors = array()
	) {}
}
