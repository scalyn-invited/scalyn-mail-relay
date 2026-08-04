<?php
/**
 * Normalized mail message value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a mail message prepared for transport.
 *
 * Recipient / sender address formats accepted by $from and each $to element:
 *   - Plain address:        'user@example.com'
 *   - With display name:   'Display Name <user@example.com>'
 *
 * Both formats are valid for $from and every element of the $to array.
 * The transport provider is responsible for parsing the chosen format.
 *
 * Content type values:
 *   - 'text/html'  (default) — body is an HTML document.
 *   - 'text/plain'           — body is unformatted plain text.
 */
final class MailMessage {

	/**
	 * Creates a new immutable mail message.
	 *
	 * @param string $uuid         Unique message identifier (UUID v4 recommended).
	 * @param string $from         Sender address ('email@example.com' or 'Name <email>').
	 * @param array  $to           Recipients. Each element: 'email@example.com' or 'Name <email>'.
	 * @param string $subject      Email subject line.
	 * @param string $body         Email body content.
	 * @param string $content_type Content type: 'text/html' or 'text/plain'. Default 'text/html'.
	 * @param array  $headers      Additional headers as [ 'Header-Name: value' ].
	 * @param array  $attachments  Absolute paths to attachment files.
	 * @param array  $context      Source-tracking metadata (plugin name, hook, post ID, etc.).
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $from,
		public readonly array $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $content_type = 'text/html',
		public readonly array $headers = array(),
		public readonly array $attachments = array(),
		public readonly array $context = array()
	) {}
}
