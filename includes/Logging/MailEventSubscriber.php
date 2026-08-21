<?php
/**
 * Mail lifecycle event subscriber.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Logging;

use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\MailStatus;
use Scalyn\MailRelay\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes to terminal mail lifecycle hooks and persists log and timeline records.
 *
 * Hooks consumed:
 *   HookNames::MAIL_SENT   — message was accepted by the provider.
 *   HookNames::MAIL_FAILED — message was rejected or a transport/config error occurred.
 *
 * Hooks deferred (not consumed in this module):
 *   HookNames::MAIL_PREPARED — deferred; no SendResult available at that stage.
 *
 * SENT status reasoning:
 *   MAIL_SENT maps to MailStatus::ACCEPTED. SmtpProvider::send() returns success=true
 *   only after PHPMailer::send() completes without throwing, which requires a 250 SMTP
 *   DATA response. MailStatus::ACCEPTED is explicitly defined as "For SMTP this
 *   corresponds to a 250 response." This is direct, authoritative contract evidence —
 *   not an inference. MAIL_SENT does not fabricate CONNECTED, AUTHENTICATED, or any
 *   other intermediate lifecycle stage.
 *
 * Failure isolation:
 *   Persistence errors are caught and swallowed. A logging failure must never
 *   interrupt mail delivery, propagate to MailDispatcher, or expose credential-adjacent
 *   exception details. Only the exception class name is written to the PHP error log.
 *
 * Ownership: Kim / Logging.
 */
final class MailEventSubscriber {

	/**
	 * Creates a new mail event subscriber.
	 *
	 * @param MailLogRepository  $log_repo      Repository for scalyn_mail_logs.
	 * @param TimelineRepository $timeline_repo Repository for scalyn_mail_timeline.
	 */
	public function __construct(
		private readonly MailLogRepository $log_repo,
		private readonly TimelineRepository $timeline_repo
	) {}

	/**
	 * Registers WordPress action hooks for the consumed mail lifecycle events.
	 *
	 * Must be called during plugin boot, after the service container is initialized.
	 * accepted_args is 2 for both hooks because each fires with (SendResult, MailMessage).
	 */
	public function register(): void {
		add_action( HookNames::MAIL_SENT, array( $this, 'on_mail_sent' ), 10, 2 );
		add_action( HookNames::MAIL_FAILED, array( $this, 'on_mail_failed' ), 10, 2 );
	}

	/**
	 * Persists a mail log row and timeline event when a send is accepted.
	 *
	 * Status: MailStatus::ACCEPTED — SmtpProvider returns success=true only after
	 * the SMTP server acknowledges the DATA command with a 250 response, which the
	 * MailStatus contract maps explicitly to ACCEPTED.
	 *
	 * @param SendResult  $result  The normalized send result (success=true).
	 * @param MailMessage $message The dispatched message.
	 */
	public function on_mail_sent( SendResult $result, MailMessage $message ): void {
		try {
			$this->log_repo->upsert( $message, $result, MailStatus::ACCEPTED );

			$this->timeline_repo->insert_event(
				$message->uuid,
				'mail_sent',
				MailStatus::ACCEPTED,
				__( 'Message accepted by provider', 'scalyn-mail-relay' ),
				$result->response_message,
				$this->build_sent_event_data( $result )
			);
		} catch ( \Throwable $e ) {
			// Persistence failure must not propagate to MailDispatcher or the caller.
			// Log only the class name — never the message, which may contain provider
			// details, connection strings, or other credential-adjacent text.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Scalyn Mail Relay: mail log persistence failed on MAIL_SENT: ' . get_class( $e ) );
		}
	}

	/**
	 * Persists a mail log row and timeline event when a send fails.
	 *
	 * @param SendResult  $result  The normalized send result (success=false).
	 * @param MailMessage $message The dispatched message.
	 */
	public function on_mail_failed( SendResult $result, MailMessage $message ): void {
		try {
			$this->log_repo->upsert( $message, $result, MailStatus::FAILED );

			$this->timeline_repo->insert_event(
				$message->uuid,
				'mail_failed',
				MailStatus::FAILED,
				__( 'Message delivery failed', 'scalyn-mail-relay' ),
				$result->response_message,
				$this->build_failed_event_data( $result )
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Scalyn Mail Relay: mail log persistence failed on MAIL_FAILED: ' . get_class( $e ) );
		}
	}

	/**
	 * Builds the allowlisted event_data array for a MAIL_SENT event.
	 *
	 * Only provider identification and SMTP response code are included.
	 * Explicitly excluded: SendResult::$metadata (may contain credential-adjacent
	 * provider data per its @security annotation), response_message (stored in the
	 * mail log row), and provider_message_id (minimal data preference).
	 *
	 * @param SendResult $result The normalized send result.
	 * @return array<string, mixed>
	 */
	private function build_sent_event_data( SendResult $result ): array {
		return array(
			'provider'      => $result->provider,
			'response_code' => $result->response_code,
		);
	}

	/**
	 * Builds the allowlisted event_data array for a MAIL_FAILED event.
	 *
	 * Includes failure classification and retry flag in addition to provider/code,
	 * as these are needed for automated retry and alerting decisions.
	 * Explicitly excluded: SendResult::$metadata, response_message, provider_message_id.
	 *
	 * @param SendResult $result The normalized send result.
	 * @return array<string, mixed>
	 */
	private function build_failed_event_data( SendResult $result ): array {
		return array(
			'provider'         => $result->provider,
			'response_code'    => $result->response_code,
			'failure_category' => $result->failure_category,
			'retryable'        => $result->retryable,
		);
	}
}
