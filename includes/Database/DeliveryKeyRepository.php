<?php
/**
 * Immutable encrypted recipient-matching key versions.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Delivery\RecipientTokens;

defined( 'ABSPATH' ) || exit;

/** Internal service: explicit provisioning only; no UI returns or automatic recovery. */
final class DeliveryKeyRepository {

	/**
	 * Uses the existing server encryption root, with a separate authenticated context.
	 *
	 * @param CredentialCipher $cipher Server-bound encryption.
	 */
	public function __construct( private readonly CredentialCipher $cipher ) {}

	/**
	 * Creates a new immutable version; does not select it for any source.
	 * Existing versions remain available for retained attempts during rotation.
	 *
	 * @return string Key version only, never plaintext key or envelope.
	 * @throws \RuntimeException When encryption or persistence fails.
	 */
	public function provision(): string {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$version  = strtolower( wp_generate_uuid4() );
			$payload  = wp_json_encode(
				array(
					'version' => $version,
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Encode random key bytes inside authenticated encryption only.
					'key'     => base64_encode( random_bytes( 32 ) ),
				)
			);
			$envelope = $this->cipher->encrypt( $payload, 'delivery-matching' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned immutable key INSERT; no plaintext persists.
			$written = $wpdb->insert(
				$wpdb->prefix . 'scalyn_delivery_keys',
				array(
					'key_version'  => $version,
					'key_envelope' => $envelope,
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( 1 !== $written ) {
				throw new \RuntimeException();
			}
			return $version;
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Recipient matching key could not be provisioned.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Derives a token using the exact retained version; never replaces missing keys.
	 *
	 * @param string $version Retained key UUID.
	 * @param string $source Source UUID.
	 * @param string $attempt Attempt UUID.
	 * @param string $address Transient parsed recipient.
	 * @return string Internal pseudonymous token.
	 * @throws \RuntimeException When the version or matching inputs are unavailable.
	 */
	public function token( string $version, string $source, string $attempt, #[\SensitiveParameter] string $address ): string {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $version ) ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh key lookup; never expose or cache plaintext.
			$envelope = $wpdb->get_var( $wpdb->prepare( 'SELECT key_envelope FROM %i WHERE key_version=%s', $wpdb->prefix . 'scalyn_delivery_keys', $version ) );
			if ( ! is_string( $envelope ) ) {
				throw new \RuntimeException();
			}
			$payload = json_decode( $this->cipher->decrypt( $envelope, 'delivery-matching' ), true );
			if ( ! is_array( $payload ) || ( $payload['version'] ?? null ) !== $version || ! is_string( $payload['key'] ?? null ) ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Decode authenticated key material, never executable code.
			$key = base64_decode( $payload['key'], true );
			if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
				throw new \RuntimeException();
			}
			return RecipientTokens::create( $key, $version, $source, $attempt, $address );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Recipient matching is unavailable.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Stops new references without removing the key required by historical attempts.
	 * Source management must stop selecting this version before invoking retirement.
	 *
	 * @param string $version Immutable key UUID.
	 * @throws \RuntimeException When retirement cannot be confirmed.
	 */
	public function retire( string $version ): void {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $version ) ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Row update serializes against preparation's key lock; retirement is irreversible.
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET retired_at=%s WHERE key_version=%s AND retired_at IS NULL', $wpdb->prefix . 'scalyn_delivery_keys', gmdate( 'Y-m-d H:i:s' ), $version ) );
			if ( false === $result ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Distinguish already retired from missing or failed writes.
			$at = $wpdb->get_var( $wpdb->prepare( 'SELECT retired_at FROM %i WHERE key_version=%s', $wpdb->prefix . 'scalyn_delivery_keys', $version ) );
			if ( ! is_string( $at ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $at ) ) {
				throw new \RuntimeException();
			}
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Recipient matching key could not be retired.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Removes only explicitly retired versions with no retained attempt references.
	 *
	 * @param int $limit Maximum key versions, clamped to 1..100.
	 * @return int Deleted key versions.
	 * @throws \RuntimeException When atomic retirement cleanup fails.
	 */
	public function prune_retired( int $limit = 100 ): int {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$started    = false;
		try {
			$key_table     = $wpdb->prefix . 'scalyn_delivery_keys';
			$attempt_table = $wpdb->prefix . 'scalyn_delivery_attempts';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Key lifecycle must be serialized with new associations.
			$engines = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN (%s,%s)", $key_table, $attempt_table ) );
			if ( '2' !== (string) $engines ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned bounded key retirement transaction.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException();
			}
			$started = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded unreferenced candidates; lock keys before rechecking attempts like preparation does.
			$versions = $wpdb->get_col( $wpdb->prepare( 'SELECT k.key_version FROM %i k WHERE k.retired_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM %i a WHERE a.key_version=k.key_version) ORDER BY k.retired_at,k.key_version LIMIT %d FOR UPDATE', $key_table, $attempt_table, min( 100, max( 1, $limit ) ) ) );
			if ( ! is_array( $versions ) || ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			$deleted = 0;
			foreach ( $versions as $version ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A locking read observes references committed while waiting for the key lock.
				$reference = $wpdb->get_var( $wpdb->prepare( 'SELECT message_uuid FROM %i WHERE key_version=%s LIMIT 1 FOR UPDATE', $attempt_table, $version ) );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new \RuntimeException();
				}
				if ( null !== $reference ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Delete only the locked, explicitly retired and unreferenced version.
				if ( 1 !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE key_version=%s AND retired_at IS NOT NULL', $key_table, $version ) ) ) {
					throw new \RuntimeException();
				}
				++$deleted;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No partial cleanup success on uncertain commit.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException();
			}
			return $deleted;
		} catch ( \Throwable $error ) {
			if ( $started ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restore key versions after any cleanup failure.
				$wpdb->query( 'ROLLBACK' );
			}
			throw new \RuntimeException( 'Recipient matching key cleanup failed.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}
}
