<?php
/**
 * Conservative discovery of local-key ciphertext.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/** Checks all owned retained ciphertext stores without loading table payloads. */
final class LocalKeyReferenceRepository {
	private const ERROR = 'Secure credential storage is unavailable. Restore the site key or check private storage permissions.';
	/**
	 * Search complete option trees, failing closed on unknown structures.
	 *
	 * @throws \RuntimeException If the option cannot be inspected safely.
	 *
	 * @param mixed $value Stored option subtree.
	 * @param int   $depth Current recursion depth.
	 */
	private function contains( #[\SensitiveParameter] $value, int $depth = 0 ): bool {
		if ( $depth > 128 || is_object( $value ) || is_resource( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
			throw new \RuntimeException( self::ERROR );
		}
		if ( is_string( $value ) ) {
			return strncmp( $value, 'v2:', 3 ) === 0;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( $this->contains( $child, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}
	/**
	 * Read a fixed owned option authoritatively, never through WordPress caches.
	 *
	 * @param string $option Fixed owned option name.
	 * @return ?array Null only for proven absence; otherwise safely decoded data.
	 * @throws \RuntimeException If the read or stored representation is unknown.
	 */
	public function read_option( string $option ): ?array {
		global $wpdb;
		$previous = null;
		try {
			if ( ! in_array( $option, array( 'scalyn_mail_relay_settings', 'scalyn_mail_relay_postmark_webhook', 'scalyn_mail_relay_smtp2go_webhook', 'scalyn_mail_relay_brevo_webhook', 'scalyn_mail_relay_ai', LocalCredentialKeyStore::STATE_OPTION ), true ) || ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'suppress_errors' ) ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'query' ) ) || ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			$previous = $wpdb->suppress_errors( true );
			if ( ! is_bool( $previous ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 2', $wpdb->options, $option );
			if ( ! is_string( $sql ) || '' === $sql ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; absence must bypass all option caches and filters.
			$count = $wpdb->query( $sql );
			$rows  = $wpdb->last_result;
			if ( ! isset( $wpdb->last_error ) || '' !== $wpdb->last_error || ! is_int( $count ) || $count < 0 || $count > 1 || ! is_array( $rows ) || array_keys( $rows ) !== ( 0 === $count ? array() : array( 0 ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			if ( 0 === $count ) {
				return null;
			}
			if ( ! $rows[0] instanceof \stdClass || array_keys( get_object_vars( $rows[0] ) ) !== array( 'option_value' ) || ! is_string( $rows[0]->option_value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			$raw = $rows[0]->option_value;
			// Never instantiate stored objects. Reject warnings, trailing data and non-array options.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors -- Non-instantiating, bounded parser; canonical representation and tree checked below.
			$value = @unserialize(
				$raw,
				array(
					'allowed_classes' => false,
					'max_depth'       => 128,
				)
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact format validation, not persistence.
			if ( ! is_array( $value ) || serialize( $value ) !== $raw ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			$this->contains( $value );
			return $value;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
			throw new \RuntimeException( self::ERROR );
		} finally {
			if ( is_bool( $previous ) ) {
				try {
					$wpdb->suppress_errors( $previous );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
					throw new \RuntimeException( self::ERROR );
				}
			}
		}
	}
	/**
	 * Return false only after checked empty reads of all owned stores.
	 *
	 * @throws \RuntimeException If any authoritative read is unavailable.
	 */
	public function has_references(): bool {
		global $wpdb;
		$previous = null;
		try {
			if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'suppress_errors' ) ) || ! is_callable( array( $wpdb, 'query' ) ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			$previous = $wpdb->suppress_errors( true );
			if ( ! is_bool( $previous ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
				throw new \RuntimeException( self::ERROR );
			}
			foreach ( array( 'scalyn_mail_relay_settings', 'scalyn_mail_relay_postmark_webhook', 'scalyn_mail_relay_smtp2go_webhook', 'scalyn_mail_relay_brevo_webhook', 'scalyn_mail_relay_ai' ) as $option ) {
				$value = $this->read_option( $option );
				if ( $this->contains( $value ) ) {
					return true;
				}
			}
			foreach ( array(
				'scalyn_recovery_queue' => 'payload',
				'scalyn_delivery_keys'  => 'key_envelope',
				'scalyn_ai_sessions'    => 'payload',
			) as $table => $column ) {
				$sql = $wpdb->prepare( 'SELECT 1 AS has_local_cipher FROM %i WHERE %i LIKE %s LIMIT 1', $wpdb->prefix . $table, $column, 'v2:%' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; authoritative checked read must bypass caching.
				$count = $wpdb->query( $sql );
				$rows  = $wpdb->last_result;
				if ( ! isset( $wpdb->last_error ) || '' !== $wpdb->last_error || ! is_int( $count ) || $count < 0 || $count > 1 || ! is_array( $rows ) || array_keys( $rows ) !== ( 0 === $count ? array() : array( 0 ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
					throw new \RuntimeException( self::ERROR );
				}
				if ( 1 === $count ) {
					if ( ! $rows[0] instanceof \stdClass || array_keys( get_object_vars( $rows[0] ) ) !== array( 'has_local_cipher' ) || ! in_array( $rows[0]->has_local_cipher, array( 1, '1' ), true ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
						throw new \RuntimeException( self::ERROR );
					}
					return true;
				}
			}
			return false;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
			throw new \RuntimeException( self::ERROR );
		} finally {
			if ( is_bool( $previous ) ) {
				try {
					$wpdb->suppress_errors( $previous );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
					throw new \RuntimeException( self::ERROR );
				}
			}
		}
	}
}
