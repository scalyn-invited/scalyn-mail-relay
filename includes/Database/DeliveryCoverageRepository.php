<?php
/**
 * Bounded, token-free delivery coverage counts for one attempt.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Returns aggregate counts only; never recipient tokens, keys or provider IDs. */
final class DeliveryCoverageRepository {

	/**
	 * Reads one attempt's association and per-recipient evidence aggregates.
	 *
	 * @param string $message_uuid Validated attempt UUID.
	 * @return array|null Null when the attempt was not tracked or has been deleted.
	 * @throws \RuntimeException When storage cannot be read.
	 */
	public function find( string $message_uuid ): ?array {
		global $wpdb;
		$message_uuid = strtolower( $message_uuid );
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $message_uuid ) ) {
			return null;
		}
		$suppressed = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned primary-key read.
			$attempt = $wpdb->get_row( $wpdb->prepare( 'SELECT expected_recipients,created_at FROM %i WHERE message_uuid=%s', $wpdb->prefix . 'scalyn_delivery_attempts', $message_uuid ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			if ( ! is_array( $attempt ) ) {
				return null;
			}
			// Aggregate per recipient so a token never leaves SQL; bounded by the 50-recipient membership limit.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed message_receipt aggregate.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT MAX(kind='delivery') AS delivered, MAX(kind='bounce') AS bounced FROM %i WHERE message_uuid=%s GROUP BY recipient_token LIMIT 51", $wpdb->prefix . 'scalyn_delivery_events', $message_uuid ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
				throw new \RuntimeException();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed latest receipt time.
			$latest = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(received_at) FROM %i WHERE message_uuid=%s', $wpdb->prefix . 'scalyn_delivery_events', $message_uuid ) );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			$counts = array(
				'delivered' => 0,
				'bounced'   => 0,
				'mixed'     => 0,
			);
			foreach ( $rows as $row ) {
				$delivered = 1 === (int) $row['delivered'];
				$bounced   = 1 === (int) $row['bounced'];
				if ( $delivered && $bounced ) {
					++$counts['mixed'];
				} elseif ( $delivered ) {
					++$counts['delivered'];
				} elseif ( $bounced ) {
					++$counts['bounced'];
				}
			}
			return array(
				'expected'    => (int) $attempt['expected_recipients'],
				'created_at'  => (string) $attempt['created_at'],
				'latest_at'   => is_string( $latest ) ? $latest : null,
				'delivered'   => $counts['delivered'],
				'bounced'     => $counts['bounced'],
				'mixed'       => $counts['mixed'],
				'with_events' => count( $rows ),
			);
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Delivery evidence is unavailable.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}
}
