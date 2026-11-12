<?php
/**
 * Offline, privacy-minimized Postmark event normalization.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Postmark;

defined( 'ABSPATH' ) || exit;

/** Does not authenticate, register a route, persist evidence or acknowledge requests. */
final class WebhookNormalizer {

	/** Maximum raw JSON bytes before parsing. */
	public const MAX_BYTES = 262144;

	/** Canonical provider and internal UUID syntax. */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

	/**
	 * Normalizes only after source authentication; resolver must use retained associations.
	 * The resolver receives provider message ID, optional UUID hint and transient recipient.
	 * It must return matching message_uuid and recipient_token, or null for no match.
	 * Never log resolver arguments; source binding, retention and key versions belong there.
	 *
	 * @param string             $body Authenticated raw body, never logged.
	 * @param string             $source_id Authenticated local source UUID.
	 * @param int                $server_id Configured Postmark server ID.
	 * @param string             $stream Configured stream identifier.
	 * @param \DateTimeImmutable $received_at Server receipt time.
	 * @param callable           $resolve Source-bound repository/recipient-token resolver.
	 * @return array|null Safe normalized fields, or null for unsupported/unmatched events.
	 * @throws \InvalidArgumentException For malformed or mismatched data; messages are fixed.
	 * @throws \RuntimeException When correlation storage is unavailable.
	 */
	public function normalize( #[\SensitiveParameter] string $body, string $source_id, int $server_id, string $stream, \DateTimeImmutable $received_at, #[\SensitiveParameter] callable $resolve ): ?array {
		if ( strlen( $body ) > self::MAX_BYTES || ! preg_match( self::UUID, $source_id ) || $server_id < 1 || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $stream ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook envelope.' );
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Postmark owns the case-sensitive JSON field names.
		// Decode as objects so arrays, objects and scalar types cannot be confused.
		$event = json_decode( $body, false, 16 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! $event instanceof \stdClass || ! isset( $event->RecordType ) || ! is_string( $event->RecordType ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook envelope.' );
		}
		if ( ! in_array( $event->RecordType, array( 'Delivery', 'Bounce' ), true ) ) {
			return null;
		}
		if ( ( $event->ServerID ?? null ) !== $server_id || ( $event->MessageStream ?? null ) !== $stream || ! is_string( $event->MessageID ?? null ) || ! preg_match( self::UUID, $event->MessageID ) ) {
			throw new \InvalidArgumentException( 'Webhook source or message is invalid.' );
		}
		$delivery  = 'Delivery' === $event->RecordType;
		$recipient = $delivery ? ( $event->Recipient ?? null ) : ( $event->Email ?? null );
		if ( ! is_string( $recipient ) || strlen( $recipient ) > 254 || ! filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook recipient.' );
		}
		$provider_time = $delivery ? ( $event->DeliveredAt ?? null ) : ( $event->BouncedAt ?? null );
		$occurred      = $this->timestamp( $provider_time );
		if ( $occurred > $received_at->modify( '+5 minutes' ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook event time.' );
		}
		$hint = null;
		if ( isset( $event->Metadata ) ) {
			if ( ! $event->Metadata instanceof \stdClass ) {
				throw new \InvalidArgumentException( 'Invalid webhook correlation.' );
			}
			if ( property_exists( $event->Metadata, 'scalyn_message_uuid' ) ) {
				$hint = $event->Metadata->scalyn_message_uuid;
				if ( ! is_string( $hint ) || ! preg_match( self::UUID, $hint ) ) {
					throw new \InvalidArgumentException( 'Invalid webhook correlation.' );
				}
				$hint = strtolower( $hint );
			}
		}
		if ( ! $delivery && ( ! is_int( $event->ID ?? null ) || $event->ID < 1 || ! is_string( $event->Type ?? null ) ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook bounce.' );
		}
		$message_id = strtolower( $event->MessageID );
		try {
			$match = $resolve( $message_id, $hint, $recipient );
		} catch ( \Throwable $error ) {
			// Do not chain an exception that may contain SQL, addresses or key material.
			throw new \RuntimeException( 'Webhook correlation is unavailable.' );
		}
		if ( null === $match ) {
			return null;
		}
		if ( ! is_array( $match ) || ! is_string( $match['message_uuid'] ?? null ) || ! preg_match( self::UUID, $match['message_uuid'] ) || ! is_string( $match['recipient_token'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $match['recipient_token'] ) || ( null !== $hint && strtolower( $match['message_uuid'] ) !== $hint ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook correlation.' );
		}
		$time = $occurred->format( 'Y-m-d\TH:i:s.u\Z' );
		// Preserve a provider's seventh fractional digit in identity, not the UI timestamp.
		preg_match( '/\.(\d{1,7})/', $provider_time, $fraction );
		$submicrosecond = substr( str_pad( $fraction[1] ?? '', 7, '0' ), 6, 1 );
		// Tuple encoding is unambiguous and contains no raw recipient address.
		$identity = $delivery ? array( 'v1', strtolower( $source_id ), $message_id, $match['recipient_token'], 'delivery', $time, $submicrosecond ) : array( 'v1', strtolower( $source_id ), 'bounce', (string) $event->ID );
		return array(
			'schema_version'        => 1,
			'source_id'             => strtolower( $source_id ),
			'provider'              => 'postmark',
			'message_uuid'          => strtolower( $match['message_uuid'] ),
			'provider_message_id'   => $message_id,
			'event_key'             => hash( 'sha256', wp_json_encode( $identity ) ),
			'kind'                  => $delivery ? 'delivery' : 'bounce',
			'recipient_token'       => $match['recipient_token'],
			'occurred_at'           => $time,
			'received_at'           => $received_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.u\Z' ),
			'authentication_method' => 'postmark_basic_tls',
			'reason_code'           => $delivery ? null : ( array(
				'HardBounce' => 'hard_bounce',
				'SoftBounce' => 'soft_bounce',
			)[ $event->Type ] ?? 'unknown_bounce' ),
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Strict ISO date validation, normalized to UTC; no relative dates or silent overflow.
	 *
	 * @param mixed $value Provider date.
	 * @return \DateTimeImmutable UTC timestamp.
	 * @throws \InvalidArgumentException For malformed dates.
	 */
	private function timestamp( mixed $value ): \DateTimeImmutable {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,7}))?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value, $parts ) || ( str_starts_with( substr( $value, -6 ), '+14:' ) && ! str_ends_with( $value, '+14:00' ) ) || ( str_starts_with( substr( $value, -6 ), '-14:' ) && ! str_ends_with( $value, '-14:00' ) ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook event time.' );
		}
		$normalized = $parts[1] . '.' . str_pad( substr( $parts[2], 0, 6 ), 6, '0' ) . ( 'Z' === $parts[3] ? '+00:00' : $parts[3] );
		$date       = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s.uP', $normalized );
		$errors     = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || (int) substr( $value, 0, 4 ) < 1970 ) {
			throw new \InvalidArgumentException( 'Invalid webhook event time.' );
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
