<?php
/**
 * Normalized provider send result.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result returned by ProviderInterface::send().
 *
 * @security The $metadata array must never contain SMTP credentials, API keys,
 * OAuth tokens, email message bodies, or recipient addresses.
 */
final class SendResult {

	/**
	 * Creates a new normalized send result.
	 *
	 * @param bool        $success             Whether the provider accepted the message.
	 * @param string      $provider            The provider ID that handled the send.
	 * @param string|null $provider_message_id Message ID assigned by the provider, if available.
	 * @param string|null $response_code       SMTP response code or HTTP status code.
	 * @param string|null $response_message    Human-readable response from the provider.
	 * @param bool        $retryable           Whether a transient failure may succeed on retry.
	 * @param string|null $failure_category    Failure classification: 'auth', 'config', 'network', 'bounce', etc.
	 * @param array       $metadata            Optional supplemental data. Must not contain credentials or secrets.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $provider,
		public readonly ?string $provider_message_id = null,
		public readonly ?string $response_code = null,
		public readonly ?string $response_message = null,
		public readonly bool $retryable = false,
		public readonly ?string $failure_category = null,
		public readonly array $metadata = array()
	) {}
}
