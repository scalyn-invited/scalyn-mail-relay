<?php
/**
 * Validated, bounded filters for retained email metadata.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Logging;

defined( 'ABSPATH' ) || exit;

/** Repository query inputs; date boundaries are inclusive site-local days. */
final class LogFilters {
	/**
	 * Validates untrusted filter values before a repository query.
	 *
	 * @param array $values String input values.
	 * @return array Validated values, excluding empty fields.
	 * @throws \InvalidArgumentException For invalid or oversized input.
	 */
	public static function validate( array $values ): array {
		$result = array();
		foreach ( array( 'start', 'end', 'provider', 'source', 'recipient', 'subject', 'message_id', 'search' ) as $key ) {
			$value = $values[ $key ] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 255 ) {
				throw new \InvalidArgumentException( 'Invalid log filter.' );
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			if ( 'message_id' === $key && 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
				throw new \InvalidArgumentException( 'Invalid message ID.' );
			}
			if ( in_array( $key, array( 'start', 'end' ), true ) ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value || (int) $date->format( 'Y' ) < 1000 ) {
					throw new \InvalidArgumentException( 'Invalid log date.' );
				}
			}
			$result[ $key ] = $value;
		}
		if ( isset( $result['start'], $result['end'] ) && $result['start'] > $result['end'] ) {
			throw new \InvalidArgumentException( 'Invalid date range.' );
		}
		return $result;
	}
}
