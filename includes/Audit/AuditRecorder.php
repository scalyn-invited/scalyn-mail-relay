<?php
/**
 * Failure-isolated audit event subscriber.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Audit;

use Scalyn\MailRelay\Core\HookNames;

defined( 'ABSPATH' ) || exit;

/** Audit failures never change the outcome of the operation being recorded. */
final class AuditRecorder {

	/**
	 * Creates the subscriber.
	 *
	 * @param AuditRepository $repository Audit persistence.
	 */
	public function __construct( private AuditRepository $repository ) {}

	/** Registers once during plugin boot. */
	public function register(): void {
		add_action( HookNames::AUDIT_EVENT, array( $this, 'record' ) );
	}

	/**
	 * Writes an event without exposing any error details.
	 *
	 * @param AuditEvent $event Allowlisted event.
	 */
	public function record( AuditEvent $event ): void {
		try {
			$this->repository->append( $event );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed operational warning, no exception or event payload.
			error_log( 'Scalyn Mail Relay: audit persistence failed.' );
		}
	}
}
