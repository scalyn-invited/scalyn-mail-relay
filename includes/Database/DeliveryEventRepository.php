<?php
/**
 * Atomic append-only delivery evidence and timeline projection.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Logging\TimelineRepository;

defined( 'ABSPATH' ) || exit;

/** No sending, lifecycle rewrites, raw addresses or payload retention. */
final class DeliveryEventRepository {

	/** Persisted contract field allowlist. */
	private const FIELDS = array( 'schema_version', 'source_id', 'provider', 'message_uuid', 'provider_message_id', 'event_key', 'kind', 'recipient_token', 'occurred_at', 'received_at', 'authentication_method', 'reason_code' );

	/**
	 * Commits one authenticated normalized event and its privacy-safe projection.
	 * Caller must hold source enablement coordination and authenticate before calling.
	 *
	 * @param array  $event Version-one normalized evidence; never a raw callback.
	 * @param string $cutoff UTC attempt-creation retention boundary.
	 * @return string stored, duplicate or ignored. Only committed duplicates succeed.
	 * @throws \RuntimeException On invalid input, conflicting identity or storage failure.
	 */
	public function append( #[\SensitiveParameter] array $event, string $cutoff ): string {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$started    = false;
		try {
			$event = $this->validate( $event );
			$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff, new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- All participating tables must support rollback.
			$engines = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN (%s,%s,%s,%s)", $wpdb->prefix . 'scalyn_delivery_attempts', $wpdb->prefix . 'scalyn_delivery_recipients', $wpdb->prefix . 'scalyn_delivery_events', $wpdb->prefix . 'scalyn_mail_timeline' ) );
			if ( '4' !== (string) $engines ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One transaction for evidence, provider binding and projection.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException();
			}
			$started = true;
			// Serialize on the retained attempt, including concurrent duplicate callbacks.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Retention deletion must take the same attempt lock.
			$attempt = $wpdb->get_row( $wpdb->prepare( "SELECT provider_message_id FROM %i WHERE message_uuid=%s AND source_id=%s AND provider='postmark' AND created_at >= %s FOR UPDATE", $wpdb->prefix . 'scalyn_delivery_attempts', $event['message_uuid'], $event['source_id'], $cutoff ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			if ( ! $attempt || ( null !== $attempt['provider_message_id'] && $attempt['provider_message_id'] !== $event['provider_message_id'] ) ) {
				$this->commit();
				return 'ignored';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Recheck membership inside the transaction, never trust a stale resolver result.
			$member = $wpdb->get_var( $wpdb->prepare( 'SELECT recipient_token FROM %i WHERE message_uuid=%s AND recipient_token=%s FOR UPDATE', $wpdb->prefix . 'scalyn_delivery_recipients', $event['message_uuid'], $event['recipient_token'] ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			if ( $member !== $event['recipient_token'] ) {
				$this->commit();
				return 'ignored';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Locking read sees committed duplicates, including after waiting on the attempt lock.
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_id=%s AND event_key=%s FOR UPDATE', $wpdb->prefix . 'scalyn_delivery_events', $event['source_id'], $event['event_key'] ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			if ( $existing ) {
				foreach ( self::FIELDS as $field ) {
					// Receipt time changes on retry; every semantic field must still agree.
					if ( 'received_at' !== $field && (string) $existing[ $field ] !== (string) $event[ $field ] ) {
						throw new \RuntimeException();
					}
				}
				$this->commit();
				return 'duplicate';
			}
			// The unique source_event index is an additional cross-attempt collision guard.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Persist only validated contract fields.
			if ( 1 !== $wpdb->insert( $wpdb->prefix . 'scalyn_delivery_events', $event ) ) {
				throw new \RuntimeException();
			}
			if ( null === $attempt['provider_message_id'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Early callbacks bind the ID while holding the attempt lock.
				if ( 1 !== $wpdb->update( $wpdb->prefix . 'scalyn_delivery_attempts', array( 'provider_message_id' => $event['provider_message_id'] ), array( 'message_uuid' => $event['message_uuid'] ) ) ) {
					throw new \RuntimeException();
				}
			}
			$delivery = 'delivery' === $event['kind'];
			( new TimelineRepository() )->insert_event(
				$event['message_uuid'],
				'delivery_evidence',
				'',
				$delivery ? 'Delivered (recipient server)' : 'Bounce reported',
				$delivery ? 'Postmark reported delivery to one recipient server. This does not prove inbox placement or delivery to all recipients.' : 'Postmark reported a bounce for one recipient. The original sending outcome is unchanged.',
				array(
					'schema_version' => 1,
					'kind'           => $event['kind'],
					'occurred_at'    => $event['occurred_at'],
					'received_at'    => $event['received_at'],
					'timezone'       => 'UTC',
					'reason_code'    => $event['reason_code'],
				)
			);
			$this->commit();
			return 'stored';
		} catch ( \Throwable $error ) {
			if ( $started ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No partial evidence or timeline acknowledgement.
				$wpdb->query( 'ROLLBACK' );
			}
			throw new \RuntimeException( 'Delivery evidence could not be committed.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Enforces the persistence allowlist even if a caller bypasses normalization.
	 *
	 * @param array $event Internal normalized contract.
	 * @return array Validated SQL-safe values, timestamps in UTC.
	 * @throws \RuntimeException On malformed fields.
	 */
	private function validate( #[\SensitiveParameter] array $event ): array {
		if ( count( $event ) !== count( self::FIELDS ) || array_diff( self::FIELDS, array_keys( $event ) ) || 1 !== $event['schema_version'] || 'postmark' !== $event['provider'] || 'postmark_basic_tls' !== $event['authentication_method'] || ! in_array( $event['kind'], array( 'delivery', 'bounce' ), true ) ) {
			throw new \RuntimeException();
		}
		foreach ( array( 'source_id', 'message_uuid', 'provider_message_id' ) as $field ) {
			if ( ! is_string( $event[ $field ] ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $event[ $field ] ) ) {
				throw new \RuntimeException();
			}
		}
		foreach ( array( 'event_key', 'recipient_token' ) as $field ) {
			if ( ! is_string( $event[ $field ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $event[ $field ] ) ) {
				throw new \RuntimeException();
			}
		}
		if ( ( 'delivery' === $event['kind'] && null !== $event['reason_code'] ) || ( 'bounce' === $event['kind'] && ! in_array( $event['reason_code'], array( 'hard_bounce', 'soft_bounce', 'unknown_bounce' ), true ) ) ) {
			throw new \RuntimeException();
		}
		foreach ( array( 'occurred_at', 'received_at' ) as $field ) {
			if ( ! is_string( $event[ $field ] ) ) {
				throw new \RuntimeException();
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s.u\Z', $event[ $field ], new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d\TH:i:s.u\Z' ) !== $event[ $field ] ) {
				throw new \RuntimeException();
			}
			$event[ $field ] = $date->format( 'Y-m-d H:i:s.u' );
		}
		if ( new \DateTimeImmutable( $event['occurred_at'], new \DateTimeZone( 'UTC' ) ) > ( new \DateTimeImmutable( $event['received_at'], new \DateTimeZone( 'UTC' ) ) )->modify( '+5 minutes' ) ) {
			throw new \RuntimeException();
		}
		return $event;
	}

	/**
	 * Commits before returning any success disposition.
	 *
	 * @throws \RuntimeException On uncertain commit.
	 */
	private function commit(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic evidence boundary.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new \RuntimeException();
		}
	}
}
