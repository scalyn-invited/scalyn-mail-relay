<?php
/**
 * Normalized mail message value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

final class MailMessage {
	public function __construct(
		public readonly string $uuid,
		public readonly array $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $headers = array(),
		public readonly array $attachments = array(),
		public readonly array $context = array()
	) {}
}
