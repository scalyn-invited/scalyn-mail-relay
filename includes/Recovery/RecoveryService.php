<?php
/** Bounded asynchronous ownership and conservative retry/failover.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Recovery;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\MailOrigin;
use Scalyn\MailRelay\Mail\RoutingRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\SubmissionSafety;

defined( 'ABSPATH' ) || exit;

/** At most one submission per worker tick; no blocking retry loops. */
final class RecoveryService {

	/**
	 * Supplies owned persistence, protection and transport boundaries.
	 *
	 * @param RecoveryQueueRepository  $queue Durable queue.
	 * @param RecoveryPolicyRepository $policy Consent policy.
	 * @param RecoveryPayload          $payload Private protection boundary.
	 * @param MailDispatcher           $dispatcher Existing provider contract.
	 * @param SettingsRepository       $settings Request snapshot.
	 */
	public function __construct( private readonly RecoveryQueueRepository $queue, private readonly RecoveryPolicyRepository $policy, private readonly RecoveryPayload $payload, private readonly MailDispatcher $dispatcher, private readonly SettingsRepository $settings ) {}

	/** Registers worker and reconciles the cleanup schedule without enabling recovery. */
	public function register(): void {
		add_action( ScheduledHooks::RECOVERY, array( $this, 'tick' ) );
		self::ensure_scheduled();
	}

	/** Reconciles only the owned cadence after activation, without enabling sending. */
	public static function ensure_scheduled(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Approved recovery delays require minute cadence; one submission per tick, no polling loop.
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		if ( wp_next_scheduled( ScheduledHooks::RECOVERY ) && 'scalyn_minute' !== wp_get_schedule( ScheduledHooks::RECOVERY ) ) {
			wp_clear_scheduled_hook( ScheduledHooks::RECOVERY );
		}
		if ( ! wp_next_scheduled( ScheduledHooks::RECOVERY ) ) {
			// phpcs:ignore WordPress.WP.CronInterval -- Approved bounded recovery delays require a minute cadence; one submission per tick, no polling loop.
			wp_schedule_event( time() + 60, 'scalyn_minute', ScheduledHooks::RECOVERY );
		}
	}

	/**
	 * Adds only the owned one-minute cadence; WordPress cron is not punctual proof.
	 *
	 * @param array $schedules Existing recurrences.
	 */
	public static function schedules( array $schedules ): array {
		$schedules['scalyn_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Mail Relay recovery and expiry', 'scalyn-mail-relay' ),
		);
		return $schedules;
	}

	/** Whether the request explicitly opted in to durable asynchronous ownership. */
	public function enabled(): bool {
		return $this->policy->policy()['enabled'];
	}

	/**
	 * True means durable queue ownership only, never provider acceptance.
	 * No synchronous fallback after an ownership failure or duplicate claim.
	 *
	 * @param MailMessage $message Prepared private message.
	 * @param array|null  $plan Trusted ordered targets already pinned by the bridge.
	 * @return bool Whether durable ownership was established.
	 */
	public function own( MailMessage $message, ?array $plan = null ): bool {
		if ( ! $this->enabled() ) {
			return false;
		}
		try {
			$origin = MailOrigin::current();
			$plan ??= ( new RoutingRepository() )->plan( $this->settings, $origin );
			if ( array() === $plan ) {
				$plan = array( $this->settings->configuration_target( $this->settings->get_active_configuration_id() ) );
			}
			$this->queue->enqueue( $message->uuid, $this->payload->seal( $message, $plan, $origin ), time() );
			$this->audit( 'owned', $message->uuid );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** One due message; interrupted/ambiguous sends stop for review, never replay. */
	public function tick(): void {
		$handles = array();
		$row     = null;
		try {
			$this->queue->sweep( time() );
			if ( ! $this->enabled() ) {
				return;
			}
			$row = $this->queue->claim( time() );
			if ( null === $row ) {
				return;
			}
			if ( strtotime( $row['expires_at'] . ' UTC' ) <= time() ) {
				$this->queue->finish( $row, 'failed', 'storage_unavailable', time() );
				return;
			}
			$data      = $this->payload->open( $row['payload'], $row['logical_uuid'] );
			$completed = (int) $row['attempt_count'];
			$target    = $data['plan'][ min( $completed, count( $data['plan'] ) - 1 ) ];
			// Refresh before transport, not just from the cached request-time service.
			$fresh = $this->settings->fresh();
			try {
				$fresh->resolve_target( $target );
			} catch ( \Throwable $error ) {
				$this->queue->finish( $row, 'failed', 'configuration_changed', time() );
				return;
			}
			$message = $this->payload->message( $data, wp_generate_uuid4(), $completed + 1, $handles );
			if ( ! $this->enabled() ) {
				$this->queue->finish( $row, 'failed', 'disabled', time() );
				return;
			}
			if ( ! $this->queue->submitting( $row, time() ) ) {
				return;
			}
			$result = $this->dispatcher->dispatch( $message, $target, true );
			if ( $result->success ) {
				$this->queue->finish( $row, 'accepted', 'accepted', time() );
				$this->audit( 'accepted', $row['logical_uuid'] );
			} elseif ( $result->acceptance_unconfirmed ) {
				$this->queue->finish( $row, 'review', 'unconfirmed', time() );
				$this->audit( 'review', $row['logical_uuid'] );
			} elseif ( SubmissionSafety::retryable( $result ) && $completed + 1 < 3 ) {
				$next = time() + SubmissionSafety::delay( $result, $completed + 1 );
				$this->queue->finish( $row, $next < strtotime( $row['expires_at'] . ' UTC' ) ? 'pending' : 'failed', $next < strtotime( $row['expires_at'] . ' UTC' ) ? 'retry_wait' : 'exhausted', time(), $next );
				$this->audit( 'retry_evaluated', $row['logical_uuid'] );
			} else {
				$this->queue->finish( $row, 'failed', $completed + 1 >= 3 ? 'exhausted' : 'permanent', time() );
				$this->audit( 'stopped', $row['logical_uuid'] );
			}
		} catch ( \Throwable $error ) {
			if ( null !== $row ) {
				try {
					$this->queue->finish( $row, 'review', 'storage_unavailable', time() );
				} catch ( \Throwable $persistence_error ) {
					// Leave the claim for expiry/review cleanup; never resubmit.
					unset( $persistence_error );
				}
			}
		} finally {
			foreach ( $handles as $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing deletes private per-process temporary content.
				fclose( $handle );
			}
		}
	}

	/**
	 * Audit observers never reverse ownership or a provider outcome.
	 *
	 * @param string $outcome Fixed operational outcome.
	 * @param string $logical Logical correlation UUID.
	 */
	private function audit( string $outcome, string $logical ): void {
		try {
			do_action( \Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT, new \Scalyn\MailRelay\Audit\AuditEvent( 'recovery_work', $outcome, $logical ) );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}
}
