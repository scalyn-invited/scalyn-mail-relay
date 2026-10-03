<?php
/**
 * Pre-submission delivery associations and exact recipient correlation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Internal repository; never derives historical source identity from live settings. */
final class DeliveryAttemptRepository {

	/** UUID syntax for retained internal and Postmark identifiers. */
	private const UUID = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D';

	/**
	 * Persists membership atomically before an opted-in send is submitted.
	 *
	 * @param array $association Exactly message_uuid, source_id, configuration_id, key_version.
	 * @param array $tokens Already derived To/Cc/Bcc tokens, maximum 50 unique recipients.
	 * @throws \RuntimeException When validation or persistence fails.
	 */
	public function prepare( array $association, #[\SensitiveParameter] array $tokens ): void {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$started    = false;
		try {
			$fields = array( 'message_uuid', 'source_id', 'configuration_id', 'key_version' );
			if ( count( $association ) !== 4 || array_diff( $fields, array_keys( $association ) ) || ! $tokens || count( $tokens ) > 50 ) {
				throw new \RuntimeException();
			}
			foreach ( $association as $value ) {
				if ( ! is_string( $value ) || ! preg_match( self::UUID, $value ) ) {
					throw new \RuntimeException();
				}
			}
			foreach ( $tokens as $token ) {
				if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
					throw new \RuntimeException();
				}
			}
			$tokens = array_values( array_unique( $tokens ) );
			$this->assert_transactional();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned atomic association and membership.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException();
			}
			$started = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Key deletion must serialize against new references.
			$key = $wpdb->get_var( $wpdb->prepare( 'SELECT key_version FROM %i WHERE key_version=%s AND retired_at IS NULL FOR UPDATE', $wpdb->prefix . 'scalyn_delivery_keys', $association['key_version'] ) );
			if ( $key !== $association['key_version'] ) {
				throw new \RuntimeException();
			}
			$row = array_merge(
				$association,
				array(
					'provider'            => 'postmark',
					'expected_recipients' => count( $tokens ),
					'created_at'          => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No upsert: an attempt cannot be rebound or backfilled.
			if ( 1 !== $wpdb->insert( $wpdb->prefix . 'scalyn_delivery_attempts', $row ) ) {
				throw new \RuntimeException();
			}
			foreach ( $tokens as $token ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Persist only keyed membership, never addresses or recipient roles.
				if ( 1 !== $wpdb->insert(
					$wpdb->prefix . 'scalyn_delivery_recipients',
					array(
						'message_uuid'    => $association['message_uuid'],
						'recipient_token' => $token,
					)
				) ) {
					throw new \RuntimeException();
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No send may follow an uncertain association commit as if tracking were ready.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException();
			}
		} catch ( \Throwable $error ) {
			if ( $started ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Discard partial recipient membership.
				$wpdb->query( 'ROLLBACK' );
			}
			throw new \RuntimeException( 'Delivery association could not be stored.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Appends a provider identifier without changing source, membership or lifecycle.
	 *
	 * @param string $source Original source UUID.
	 * @param string $attempt Attempt UUID.
	 * @param string $message_id Validated provider acknowledgement identifier.
	 * @throws \RuntimeException When binding fails or contradicts a previous identifier.
	 */
	public function acknowledge( string $source, string $attempt, string $message_id ): void {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			foreach ( array( $source, $attempt, $message_id ) as $id ) {
				if ( ! preg_match( self::UUID, $id ) ) {
					throw new \RuntimeException();
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-set never overwrites callback or acknowledgement identity.
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET provider_message_id=%s WHERE message_uuid=%s AND source_id=%s AND provider='postmark' AND (provider_message_id IS NULL OR provider_message_id=%s)", $wpdb->prefix . 'scalyn_delivery_attempts', $message_id, $attempt, $source, $message_id ) );
			if ( false === $result ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify no-op success; zero updates can also mean absent or contradictory identity.
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT provider_message_id FROM %i WHERE message_uuid=%s AND source_id=%s AND provider='postmark'", $wpdb->prefix . 'scalyn_delivery_attempts', $attempt, $source ) );
			if ( $stored !== $message_id ) {
				throw new \RuntimeException();
			}
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Delivery acknowledgement could not be stored.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Resolves only a retained, source-bound attempt with exact recipient membership.
	 *
	 * @param string                $source Authenticated source UUID.
	 * @param string                $message_id Provider message UUID.
	 * @param string|null           $hint Optional internal UUID from provider metadata.
	 * @param string                $address Transient parsed recipient.
	 * @param string                $cutoff UTC attempt-creation retention boundary.
	 * @param DeliveryKeyRepository $keys Version-aware matching service.
	 * @return array|null Internal match, or null when unknown, ambiguous, expired or mismatched.
	 * @throws \RuntimeException When database or key material is unavailable.
	 */
	public function resolve( string $source, string $message_id, ?string $hint, #[\SensitiveParameter] string $address, string $cutoff, DeliveryKeyRepository $keys ): ?array {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			foreach ( array_filter( array( $source, $message_id, $hint ), static fn( $id ) => null !== $id ) as $id ) {
				if ( ! preg_match( self::UUID, $id ) ) {
					throw new \RuntimeException();
				}
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $cutoff, new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $cutoff ) {
				throw new \RuntimeException();
			}
			$field = null !== $hint ? 'message_uuid' : 'provider_message_id';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Two rows suffice to reject ambiguous provider identifiers; no fuzzy matching.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT message_uuid,provider_message_id,key_version FROM %i WHERE source_id=%s AND provider='postmark' AND %i=%s AND created_at >= %s LIMIT 2", $wpdb->prefix . 'scalyn_delivery_attempts', $source, $field, $hint ?? $message_id, $cutoff ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
				throw new \RuntimeException();
			}
			if ( count( $rows ) !== 1 ) {
				return null;
			}
			$row = $rows[0];
			if ( null !== $row['provider_message_id'] && $row['provider_message_id'] !== $message_id ) {
				return null;
			}
			$token = $keys->token( $row['key_version'], $source, $row['message_uuid'], $address );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact membership only; raw callback address is never part of SQL.
			$member = $wpdb->get_var( $wpdb->prepare( 'SELECT recipient_token FROM %i WHERE message_uuid=%s AND recipient_token=%s', $wpdb->prefix . 'scalyn_delivery_recipients', $row['message_uuid'], $token ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			return $member === $token ? array(
				'message_uuid'    => $row['message_uuid'],
				'recipient_token' => $token,
			) : null;
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Delivery correlation is unavailable.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Refuses to promise atomic membership on nontransactional tables.
	 *
	 * @throws \RuntimeException When required InnoDB tables are absent.
	 */
	private function assert_transactional(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh storage readiness check before collection.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN (%s,%s,%s)", $wpdb->prefix . 'scalyn_delivery_keys', $wpdb->prefix . 'scalyn_delivery_attempts', $wpdb->prefix . 'scalyn_delivery_recipients' ) );
		if ( '3' !== (string) $count ) {
			throw new \RuntimeException();
		}
	}
}
