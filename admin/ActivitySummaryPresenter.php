<?php
/**
 * Dashboard activity summary read model.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Mail\MailStatus;
use Scalyn\MailRelay\Reporting\ReportPeriod;

defined( 'ABSPATH' ) || exit;

/** Keeps read failures distinct from zero retained activity. */
final class ActivitySummaryPresenter {

	/**
	 * Reads the seven days ending at the supplied site-local timestamp.
	 *
	 * @param MailLogRepository $repository Owned reporting read API.
	 * @param string            $as_of Site-local read cutoff, exclusive.
	 * @return array Safe counts and period metadata, or unavailable state.
	 */
	public static function load( MailLogRepository $repository, string $as_of ): array {
		try {
			$end = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $as_of, new \DateTimeZone( 'UTC' ) );
			if ( false === $end ) {
				return array( 'available' => false );
			}
			$period = new ReportPeriod( $end->modify( '-7 days' )->format( 'Y-m-d H:i:s' ), $as_of );
			$totals = $repository->activity_totals( $period );
			return array(
				'available' => true,
				'start'     => $period->start,
				'end'       => $period->end,
				'total'     => $totals['total'],
				'accepted'  => $totals['statuses'][ MailStatus::ACCEPTED ],
				'failed'    => $totals['statuses'][ MailStatus::FAILED ],
				'other'     => $totals['total'] - $totals['statuses'][ MailStatus::ACCEPTED ] - $totals['statuses'][ MailStatus::FAILED ],
			);
		} catch ( \Throwable $error ) {
			return array( 'available' => false );
		}
	}
}
