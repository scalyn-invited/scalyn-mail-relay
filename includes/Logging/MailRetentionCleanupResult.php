<?php
/**
 * Result of one bounded mail-retention cleanup batch.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, non-sensitive cleanup counts returned by MailRetentionRepository.
 */
final readonly class MailRetentionCleanupResult {

	/**
	 * Creates a cleanup result.
	 *
	 * @param string $cutoff                  Exclusive site-time expiry boundary.
	 * @param int    $selected_messages       Expired message UUIDs selected.
	 * @param int    $deleted_timeline_events Related timeline rows deleted.
	 * @param int    $deleted_mail_logs       Expired mail-log rows deleted.
	 */
	public function __construct(
		public string $cutoff,
		public int $selected_messages,
		public int $deleted_timeline_events,
		public int $deleted_mail_logs
	) {}
}
