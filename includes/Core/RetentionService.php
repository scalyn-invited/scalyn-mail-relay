<?php
/**
 * Bounded scheduled retention orchestration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Database\DiagnosticRetentionRepository;
use Scalyn\MailRelay\Database\RetentionStateRepository;
use Scalyn\MailRelay\Database\DeliveryRetentionRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Logging\MailRetentionRepository;
use Scalyn\MailRelay\Audit\AuditRepository;

defined( 'ABSPATH' ) || exit;

/** Runs one bounded batch per history on each hourly tick. */
final class RetentionService {

	public const BATCH_SIZE = 100;

	/**
	 * Creates the service from repository dependencies.
	 *
	 * @param SettingsRepository               $settings Retention policy.
	 * @param MailRetentionRepository          $mail Mail aggregates.
	 * @param DiagnosticRetentionRepository    $diagnostics Diagnostic histories.
	 * @param RetentionStateRepository         $state Coordination and status.
	 * @param AuditRepository                  $audit Audit history retention.
	 * @param DeliveryRetentionRepository|null $delivery Delivery evidence retention.
	 * @param DeliveryKeyRepository|null       $keys Retired matching key cleanup.
	 */
	public function __construct(
		private SettingsRepository $settings,
		private MailRetentionRepository $mail,
		private DiagnosticRetentionRepository $diagnostics,
		private RetentionStateRepository $state,
		private AuditRepository $audit,
		private ?DeliveryRetentionRepository $delivery = null,
		private ?DeliveryKeyRepository $keys = null
	) {
		$this->delivery ??= new DeliveryRetentionRepository();
		$this->keys     ??= new DeliveryKeyRepository( new CredentialCipher() );
	}

	/** Registers the listener on frontend, admin and cron requests. */
	public function register(): void {
		add_action( ScheduledHooks::CLEANUP, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/** Schedules new and upgraded installs idempotently, without running cleanup. */
	public static function ensure_scheduled(): void {
		if ( wp_next_scheduled( ScheduledHooks::CLEANUP ) && 'hourly' !== wp_get_schedule( ScheduledHooks::CLEANUP ) ) {
			wp_clear_scheduled_hook( ScheduledHooks::CLEANUP );
		}
		if ( ! wp_next_scheduled( ScheduledHooks::CLEANUP ) ) {
			wp_schedule_event( time() + 3600, 'hourly', ScheduledHooks::CLEANUP );
		}
	}

	/** Runs once; a full batch conservatively means more work may remain. */
	public function run(): void {
		if ( ! $this->state->acquire() ) {
			return;
		}

		$status = array(
			'state'           => 'running',
			'started_at'      => time(),
			'last_success_at' => $this->state->get()['last_success_at'],
		);
		try {
			$this->state->save( $status );
			$cutoff_time    = current_datetime()->modify( '-' . $this->settings->get_log_retention_days() . ' days' );
			$cutoff         = $cutoff_time->format( 'Y-m-d H:i:s' );
			$delivery_count = 0;
			if ( version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.5.0', '>=' ) ) {
				$utc_cutoff     = $cutoff_time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$delivery_count = $this->delivery->delete_expired_batch( $utc_cutoff, self::BATCH_SIZE );
			}
			$mail                      = $this->mail->delete_expired_batch( $cutoff, self::BATCH_SIZE );
			$status['mail_logs']       = $mail->deleted_mail_logs;
			$status['timeline_events'] = $mail->deleted_timeline_events;
			$key_count                 = 0;
			if ( version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.6.0', '>=' ) ) {
				$key_count = $this->keys->prune_retired( self::BATCH_SIZE );
			}
			$this->state->save( $status );
			$diagnostics               = $this->diagnostics->delete_expired_batch( $cutoff, self::BATCH_SIZE );
			$status['diagnostic_rows'] = $diagnostics->deleted_diagnostic_rows;
			$status['health_scores']   = $diagnostics->deleted_health_scores;
			$this->state->save( $status );
			$status['audit_rows']      = $this->audit->delete_expired_batch( $cutoff );
			$status['state']           = max( $delivery_count, $key_count, $mail->selected_messages, $diagnostics->selected_runs, $diagnostics->selected_health_scores, $status['audit_rows'] ) >= self::BATCH_SIZE ? 'more_pending' : 'complete';
			$status['last_success_at'] = time();
		} catch ( \Throwable $error ) {
			// Exception details may contain credentials or SQL. Store only a fixed state.
			$status['state'] = 'failed';
		} finally {
			try {
				$status['finished_at'] = time();
				$this->state->save( $status );
			} finally {
				$this->state->release();
			}
		}
	}
}
