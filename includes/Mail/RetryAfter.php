<?php
/** Numeric retry hints only; never retain provider headers.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/** Excessive waits expire queued work rather than being shortened. */
final class RetryAfter {

	/**
	 * Parses a bounded HTTP Retry-After without copying any other header.
	 *
	 * @param mixed $response WordPress HTTP response, private adapter input.
	 * @return int Minimum wait in seconds, zero when absent.
	 */
	public static function seconds( mixed $response ): int {
		$headers = is_array( $response ) ? ( $response['headers'] ?? array() ) : array();
		if ( ! is_array( $headers ) && ! $headers instanceof \ArrayAccess ) {
			return 0;
		}
		$value = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null === $value ? 0 : 86401;
		}
		$value = trim( (string) $value );
		if ( strlen( $value ) > 100 ) {
			return 86401;
		}
		if ( preg_match( '/^[0-9]+$/D', $value ) ) {
			return strlen( $value ) > 5 ? 86401 : (int) $value;
		}
		$date = strtotime( $value );
		return false === $date ? 86401 : max( 0, min( 86401, $date - time() ) );
	}
}
