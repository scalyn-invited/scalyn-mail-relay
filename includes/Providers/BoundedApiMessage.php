<?php
/**
 * Bounded message preparation shared by new JSON API adapters.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers;

use Scalyn\MailRelay\Mail\MailMessage;

defined( 'ABSPATH' ) || exit;

/** Validates locally before any submission; never stores message content. */
final class BoundedApiMessage {
	private const MAX_RECIPIENTS = 50;
	private const MAX_BODY_BYTES = 1048576;
	private const MAX_FILE_BYTES = 4194304;
	private const MAX_FILES      = 10;
	private const MAX_JSON_BYTES = 8388608;
	private const SAFE_HEADERS   = array( 'x-priority', 'x-mailer', 'list-unsubscribe' );
	private const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
	/**
	 * Constructs a bounded API payload. Rejected input never reaches HTTP.
	 *
	 * @param MailMessage $message Prepared message.
	 * @param array       $config Validated sender configuration.
	 * @return array Provider payload.
	 * @throws \InvalidArgumentException When the message exceeds supported formats or limits.
	 */
	public function prepare( MailMessage $message, array $config ): array {
		if ( ! preg_match( self::UUID_PATTERN, $message->uuid ) ) {
			throw new \InvalidArgumentException( 'The message identifier is invalid.' );
		}
		$from = $this->address( $message->from );
		if ( strtolower( $from['email'] ) !== strtolower( $config['from_email'] ) ) {
			throw new \InvalidArgumentException( 'The message sender does not match the configured API sender.' );
		}
		if ( ! isset( $from['name'] ) && '' !== ( $config['from_name'] ?? '' ) ) {
			$from['name'] = $config['from_name'];
		}
		if ( array() === $message->to || count( $message->to ) > self::MAX_RECIPIENTS ) {
			throw new \InvalidArgumentException( 'The recipient count is unsupported.' );
		}
		$recipients = array(
			'to'  => array(),
			'cc'  => array(),
			'bcc' => array(),
		);
		foreach ( $message->to as $raw ) {
			$recipients['to'][] = $this->address( $raw );
		}
		$reply_to = null;
		$headers  = array();
		$seen     = array();
		foreach ( $message->headers as $header ) {
			if ( ! is_string( $header ) || strlen( $header ) > 2048 || preg_match( '/[\r\n\x00]/', $header )
				|| ! preg_match( '/^([A-Za-z][A-Za-z0-9-]*):[ \t]*(.+)$/D', $header, $parts ) ) {
				throw new \InvalidArgumentException( 'A message header is unsupported or invalid.' );
			}
			$name  = strtolower( $parts[1] );
			$value = trim( $parts[2] );
			if ( in_array( $name, array( 'cc', 'bcc' ), true ) ) {
				foreach ( explode( ',', $value ) as $item ) {
					$recipients[ $name ][] = $this->address( trim( $item ) );
				}
			} elseif ( 'reply-to' === $name && null === $reply_to ) {
				$reply_to = $this->address( $value );
			} elseif ( in_array( $name, self::SAFE_HEADERS, true ) && ! isset( $seen[ $name ] ) && strlen( $value ) <= 998 ) {
				$headers[ $parts[1] ] = $value;
				$seen[ $name ]        = true;
			} else {
				throw new \InvalidArgumentException( 'A message header is unsupported or repeated.' );
			}
		}
		$count = count( $recipients['to'] ) + count( $recipients['cc'] ) + count( $recipients['bcc'] );
		if ( $count > self::MAX_RECIPIENTS ) {
			throw new \InvalidArgumentException( 'The recipient count is unsupported.' );
		}
		if ( ! in_array( $message->content_type, array( 'text/plain', 'text/html' ), true )
			|| '' === $message->body || strlen( $message->body ) > self::MAX_BODY_BYTES
			|| '' === $message->subject || strlen( $message->subject ) > 998
			|| preg_match( '/[\r\n\x00]/', $message->subject ) ) {
			throw new \InvalidArgumentException( 'The message content or subject is unsupported.' );
		}
		$payload = array(
			'uuid'         => strtolower( $message->uuid ),
			'sender'       => $from,
			'to'           => $recipients['to'],
			'cc'           => $recipients['cc'],
			'bcc'          => $recipients['bcc'],
			'reply_to'     => $reply_to,
			'headers'      => $headers,
			'subject'      => $message->subject,
			'body'         => $message->body,
			'content_type' => $message->content_type,
			'attachments'  => $this->attachments( $message->attachments ),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Strict JSON_THROW_ON_ERROR rejects malformed UTF-8 rather than replacing message content.
		$json = json_encode( $payload, JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new \InvalidArgumentException( 'The message exceeds the API adapter size limit.' );
		}
		return $payload;
	}

	/**
	 * Uses the exact same address subset for pre-send recipient membership.
	 *
	 * @param MailMessage $message Prepared message.
	 * @return array Transient recipient addresses.
	 * @throws \InvalidArgumentException When recipient input is unsupported.
	 */
	public function recipient_addresses( MailMessage $message ): array {
		if ( array() === $message->to || count( $message->to ) > self::MAX_RECIPIENTS ) {
			throw new \InvalidArgumentException( 'The recipient count is unsupported.' );
		}
		$addresses = array();
		foreach ( $message->to as $raw ) {
			$addresses[] = $this->address( $raw )['email'];
		}
		foreach ( $message->headers as $header ) {
			if ( is_string( $header ) && preg_match( '/^(cc|bcc):[ \t]*(.+)$/iD', $header, $parts ) ) {
				foreach ( explode( ',', trim( $parts[2] ) ) as $item ) {
					$addresses[] = $this->address( trim( $item ) )['email'];
				}
			}
		}
		if ( count( $addresses ) > self::MAX_RECIPIENTS ) {
			throw new \InvalidArgumentException( 'The recipient count is unsupported.' );
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Parses the explicit address subset supported by MailMessage.
	 *
	 * @param mixed $raw Address in plain or display-name format.
	 * @return array Email and optional display name.
	 * @throws \InvalidArgumentException When the address format is unsupported.
	 */
	private function address( mixed $raw ): array {
		if ( ! is_string( $raw ) || strlen( $raw ) > 454 || preg_match( '/[\r\n\x00",]/', $raw ) ) {
			throw new \InvalidArgumentException( 'An email address is invalid or unsupported.' );
		}
		$raw = trim( $raw );
		if ( preg_match( '/^([^<>]+?)\s*<([^<>\s]+)>$/D', $raw, $parts ) ) {
			$email = $parts[2];
			$name  = trim( $parts[1] );
		} else {
			$email = $raw;
			$name  = '';
		}
		if ( strlen( $email ) > 254 || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $name ) > 200 ) {
			throw new \InvalidArgumentException( 'An email address is invalid or unsupported.' );
		}
		return '' === $name ? array( 'email' => $email ) : array(
			'email' => $email,
			'name'  => $name,
		);
	}

	/**
	 * Reads bounded local attachments. Only filename and base64 content leave PHP.
	 *
	 * @param array $paths Local absolute paths.
	 * @return array Provider attachment records.
	 * @throws \InvalidArgumentException When a file is invalid, unreadable or oversized.
	 */
	private function attachments( array $paths ): array {
		if ( count( $paths ) > self::MAX_FILES ) {
			throw new \InvalidArgumentException( 'Too many attachments were provided.' );
		}
		$result = array();
		$total  = 0;
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || strlen( $path ) > 4096 || ! preg_match( '~^(?:[A-Za-z]:[\\\\/]|/)~', $path ) ) {
				throw new \InvalidArgumentException( 'An attachment path is unsupported.' );
			}
			$real = realpath( $path );
			$size = false !== $real && is_file( $real ) && is_readable( $real ) ? filesize( $real ) : false;
			if ( false === $size || 0 >= $size || $size > self::MAX_FILE_BYTES || $total + $size > self::MAX_FILE_BYTES ) {
				throw new \InvalidArgumentException( 'An attachment is unreadable or exceeds the size limit.' );
			}
			$filename = basename( $path );
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._ -]{0,199}$/D', $filename ) ) {
				throw new \InvalidArgumentException( 'An attachment filename is unsupported.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Read a validated local file with a strict byte cap; this is not an HTTP request.
			$bytes = file_get_contents( $real, false, null, 0, self::MAX_FILE_BYTES + 1 );
			if ( false === $bytes || strlen( $bytes ) !== $size ) {
				throw new \InvalidArgumentException( 'An attachment could not be read safely.' );
			}
			$total   += $size;
			$result[] = array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- API requires base64-encoded attachment bytes.
				'Content'     => base64_encode( $bytes ),
				'Name'        => $filename,
				'ContentType' => 'application/octet-stream',
			);
		}
		return $result;
	}
}
