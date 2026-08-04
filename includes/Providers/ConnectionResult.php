<?php
/**
 * Provider connection test result value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result returned by ProviderInterface::test_connection().
 *
 * @security The $metadata array must never contain SMTP credentials, API keys,
 * OAuth tokens, or authorization headers — even in partial or masked form.
 */
final class ConnectionResult {

	/**
	 * Creates a new connection test result.
	 *
	 * @param bool   $success  Whether the connection test succeeded.
	 * @param string $message  Human-readable summary of the result.
	 * @param array  $metadata Optional supplemental data (latency, server banner, etc.).
	 *                         Must not contain credentials or secrets.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $message = '',
		public readonly array $metadata = array()
	) {}
}
