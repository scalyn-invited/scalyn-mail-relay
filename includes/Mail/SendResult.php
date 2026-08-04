<?php
/**
 * Normalized provider send result.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

final class SendResult {
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
