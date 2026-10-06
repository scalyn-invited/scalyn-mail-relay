<?php
/**
 * Privacy-minimized SMTP2GO and Brevo delivery evidence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers;

use Scalyn\MailRelay\Delivery\ProviderMessageId;

defined( 'ABSPATH' ) || exit;

/** Only receives authenticated, bounded JSON; never stores raw provider fields. */
final class ApiWebhookNormalizer {
	public const MAX_BYTES = 262144;
	private const UUID     = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D';

	/**
	 * Projects a single event after exact source/attempt/recipient correlation.
	 *
	 * @param string             $body Transient JSON.
	 * @param string             $source_id Authenticated source.
	 * @param string             $provider Trusted provider, not payload data.
	 * @param \DateTimeImmutable $received_at Server time.
	 * @param callable           $resolve Retained membership resolver.
	 * @return array|null Safe event or unsupported/unmatched.
	 * @throws \InvalidArgumentException For malformed events.
	 */
	public function normalize( #[\SensitiveParameter] string $body, string $source_id, string $provider, \DateTimeImmutable $received_at, #[\SensitiveParameter] callable $resolve ): ?array {
		if ( strlen( $body ) > self::MAX_BYTES || ! preg_match( self::UUID, $source_id ) || ! in_array( $provider, array( 'smtp2go', 'brevo' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook envelope.' ); }
		$event = json_decode( $body, false, 16 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! $event instanceof \stdClass || ! is_string( $event->event ?? null ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook envelope.' ); }
		$kinds = 'smtp2go' === $provider ? array( 'delivered', 'bounce' ) : array( 'delivered', 'hard_bounce', 'soft_bounce' );
		if ( ! in_array( $event->event, $kinds, true ) ) {
			return null; }
		$id = 'smtp2go' === $provider ? ( $event->email_id ?? null ) : ( $event->{'message-id'} ?? null );
		// Brevo documents both bracketed API IDs and bare callback IDs.
		if ( 'brevo' === $provider && is_string( $id ) && ! str_starts_with( $id, '<' ) ) {
			$id = '<' . $id . '>'; }
		$recipient = 'smtp2go' === $provider ? ( $event->rcpt ?? null ) : ( $event->email ?? null );
		if ( ! ProviderMessageId::valid( $provider, $id ) || ! is_string( $recipient ) || strlen( $recipient ) > 254 || ! filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook message.' ); }
		$occurred = $this->timestamp( 'smtp2go' === $provider ? ( $event->time ?? null ) : ( $event->ts_event ?? null ) );
		if ( $occurred > $received_at->modify( '+5 minutes' ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook time.' ); }
		$hint  = null;
		$field = 'smtp2go' === $provider ? 'x-scalyn-message-uuid' : 'x-mailin-custom';
		foreach ( get_object_vars( $event ) as $name => $value ) {
			if ( strtolower( $name ) === $field ) {
				if ( null !== $hint || ! is_string( $value ) || ! preg_match( self::UUID, $value ) ) {
					throw new \InvalidArgumentException( 'Invalid webhook correlation.' ); }
				$hint = $value;
			}
		}
		$match = $resolve( $id, $hint, $recipient );
		if ( null === $match ) {
			return null; }
		if ( ! is_array( $match ) || ! is_string( $match['message_uuid'] ?? null ) || ! preg_match( self::UUID, $match['message_uuid'] ) || ! is_string( $match['recipient_token'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $match['recipient_token'] ) || ( null !== $hint && $hint !== $match['message_uuid'] ) ) {
			throw new \InvalidArgumentException( 'Invalid webhook correlation.' ); }
		$delivery = 'delivered' === $event->event;
		$reason   = $delivery ? null : ( 'smtp2go' === $provider ? ( array(
			'hard' => 'hard_bounce',
			'soft' => 'soft_bounce',
		)[ is_string( $event->bounce ?? null ) ? $event->bounce : '' ] ?? 'unknown_bounce' ) : $event->event );
		$time     = $occurred->format( 'Y-m-d\\TH:i:s.u\\Z' );
		return array(
			'schema_version'        => 1,
			'source_id'             => $source_id,
			'provider'              => $provider,
			'message_uuid'          => $match['message_uuid'],
			'provider_message_id'   => $id,
			'event_key'             => hash( 'sha256', wp_json_encode( array( 'v1', $provider, $source_id, $id, $match['recipient_token'], $event->event, $time, $reason ) ) ),
			'kind'                  => $delivery ? 'delivery' : 'bounce',
			'recipient_token'       => $match['recipient_token'],
			'occurred_at'           => $time,
			'received_at'           => $received_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\\TH:i:s.u\\Z' ),
			'authentication_method' => $provider . '_bearer_tls',
			'reason_code'           => $reason,
		);
	}

	/**
	 * Accepts integer Unix seconds or strict UTC calendar timestamps.
	 *
	 * @param mixed $value Provider event time.
	 * @throws \InvalidArgumentException For invalid timestamps.
	 */
	private function timestamp( mixed $value ): \DateTimeImmutable {
		if ( is_int( $value ) && $value >= 0 && $value <= 253402300799 ) {
			return ( new \DateTimeImmutable( '@' . $value ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		}
		if ( is_string( $value ) && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z)?$/D', $value ) ) {
			$value = str_replace( 'T', ' ', rtrim( $value, 'Z' ) );
			$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
			if ( $date && $date->format( 'Y-m-d H:i:s' ) === $value && $date->getTimestamp() >= 0 ) {
				return $date; }
		}
		throw new \InvalidArgumentException( 'Invalid webhook time.' );
	}
}
