<?php
/** Minimal operational AI auditing.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

defined( 'ABSPATH' ) || exit;
/** No prompts, credentials or evidence enter the audit. */
final class AiAudit {
	/** Appends an allowlisted operation.
	 *
	 * @param string $action Fixed action.
	 * @param string $outcome Fixed outcome.
	 */
	public static function record( string $action, string $outcome ): void {
		try {
			( new \Scalyn\MailRelay\Audit\AuditRepository() )->append( new \Scalyn\MailRelay\Audit\AuditEvent( $action, $outcome ) );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed operational failure only.
			error_log( 'Scalyn Mail Relay: AI audit persistence failed.' );
		}
	}
}
