<?php
/**
 * Current-source delivery evidence aggregates for provider health.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Returns counts only; source, recipient and event identifiers stay in SQL. */
final class ProviderDeliveryRepository {
	/**
	 * Reads a capped current-revision cohort and authenticated evidence in UTC.
	 *
	 * @param string             $revision Configuration UUID.
	 * @param string             $provider Supported provider.
	 * @param string             $source Source UUID.
	 * @param int                $days Retained window, at most seven days.
	 * @param \DateTimeImmutable $now Evaluation instant.
	 * @return array|null Null for unreadable, malformed or over-limit evidence.
	 */
	public function counts( string $revision, string $provider, string $source, int $days, \DateTimeImmutable $now ): ?array {
		global $wpdb;
		$uuid = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D';
		if ( ! preg_match( $uuid, $revision ) || ! preg_match( $uuid, $source ) || ! in_array( $provider, array( 'postmark', 'smtp2go', 'brevo' ), true ) || $days < 1 || $days > 7 ) {
			return null;
		}
		$now        = $now->setTimezone( new \DateTimeZone( 'UTC' ) );
		$end        = $now->format( 'Y-m-d H:i:s.u' );
		$start      = $now->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s.u' );
		$suppressed = $wpdb->suppress_errors( true );
		try {
			// One statement gives a consistent cohort snapshot. LIMIT bounds attempts;
			// 1001 is an overflow sentinel, never a silently truncated sample.
			// Events use the existing message_receipt index and retained membership.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned, source/revision-scoped UTC aggregate; no token leaves SQL.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT message_uuid) AS attempts, COUNT(recipient_token) AS tracked, COALESCE(SUM(observed),0) AS observed, COALESCE(SUM(hard_bounced),0) AS hard_bounced
				FROM (
				 SELECT a.message_uuid, r.recipient_token,
				 MAX(e.id IS NOT NULL) AS observed,
				 MAX(CASE WHEN e.kind='bounce' AND e.reason_code='hard_bounce' THEN 1 ELSE 0 END) AS hard_bounced
				 FROM (SELECT message_uuid, source_id, provider FROM %i WHERE configuration_id=%s AND provider=%s AND source_id=%s AND created_at >= %s AND created_at <= %s ORDER BY created_at DESC, message_uuid DESC LIMIT 1001) a
				 LEFT JOIN %i r ON r.message_uuid=a.message_uuid
				 LEFT JOIN %i e ON e.message_uuid=a.message_uuid AND e.recipient_token=r.recipient_token AND e.source_id=a.source_id AND e.provider=a.provider
				 AND e.received_at >= %s AND e.received_at <= %s AND e.occurred_at >= %s AND e.occurred_at <= %s
				 AND e.kind IN ('delivery','bounce')
				 GROUP BY a.message_uuid, r.recipient_token
				) evidence",
					$wpdb->prefix . 'scalyn_delivery_attempts',
					$revision,
					$provider,
					$source,
					$start,
					$end,
					$wpdb->prefix . 'scalyn_delivery_recipients',
					$wpdb->prefix . 'scalyn_delivery_events',
					$start,
					$end,
					$start,
					$end
				),
				ARRAY_A
			);
			if ( ! empty( $wpdb->last_error ) || ! is_array( $row ) ) {
				return null; }
			foreach ( array( 'attempts', 'tracked', 'observed', 'hard_bounced' ) as $key ) {
				if ( ! isset( $row[ $key ] ) || ! preg_match( '/^[0-9]{1,6}$/D', (string) $row[ $key ] ) ) {
					return null; }
				$row[ $key ] = (int) $row[ $key ];
			}
			if ( $row['attempts'] > 1000 || $row['tracked'] > 50 * $row['attempts'] || $row['tracked'] < $row['attempts'] || $row['observed'] > $row['tracked'] || $row['hard_bounced'] > $row['observed'] ) {
				return null; }
			return array(
				'tracked'      => $row['tracked'],
				'observed'     => $row['observed'],
				'hard_bounced' => $row['hard_bounced'],
				'window_start' => $start,
				'window_end'   => $end,
				'days'         => $days,
			);
		} catch ( \Throwable $error ) {
			return null;
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}
}
