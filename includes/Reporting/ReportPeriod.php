<?php
/**
 * Validated reporting boundaries in stored site-local time.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Reporting;

defined( 'ABSPATH' ) || exit;

/** Half-open period: start inclusive, end exclusive; at most 366 days. */
final class ReportPeriod {

	/**
	 * Validates strict MySQL datetime boundaries without implicit timezone conversion.
	 *
	 * @param string $start Inclusive site-local timestamp.
	 * @param string $end   Exclusive site-local timestamp.
	 * @throws \InvalidArgumentException For malformed or unbounded periods.
	 */
	public function __construct( public readonly string $start, public readonly string $end ) {
		$dates = array();
		foreach ( array( $start, $end ) as $value ) {
			if ( ! preg_match( '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
				throw new \InvalidArgumentException( 'Invalid reporting period.' );
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
			if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value || substr( $value, 0, 4 ) < '1000' ) {
				throw new \InvalidArgumentException( 'Invalid reporting period.' );
			}
			$dates[] = $date->getTimestamp();
		}
		if ( $dates[1] <= $dates[0] || $dates[1] - $dates[0] > 366 * 86400 ) {
			throw new \InvalidArgumentException( 'Invalid reporting period.' );
		}
	}
}
