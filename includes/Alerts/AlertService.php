<?php
/**
 * Bounded alert orchestration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Alerts;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Audit\AuditEvent;

defined( 'ABSPATH' ) || exit;

/** Fixed rules, deduplicated incidents, and independent webhook retries. */
final class AlertService {

	/**
	 * Shared services.
	 *
	 * @param AlertRepository       $repository Persistence.
	 * @param ObservationRepository $observations Credential-free evidence.
	 * @param WebhookChannel        $channel Independent notification transport.
	 */
	public function __construct( private AlertRepository $repository, private ObservationRepository $observations, private WebhookChannel $channel ) {}

	/** Registers scheduling and execution; no inline mail transport hook. */
	public function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- ADR-0012: five-minute incident detection, three bounded reads and at most five HTTP attempts.
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
		add_action( ScheduledHooks::ALERTS, array( $this, 'tick' ) );
	}

	/**
	 * Adds the bounded cadence.
	 *
	 * @param array $schedules WordPress schedules.
	 * @return array Schedules.
	 */
	public static function schedules( array $schedules ): array {
		$schedules['scalyn_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (Mail Relay)', 'scalyn-mail-relay' ),
		);
		return $schedules;
	}

	/** Reconciles the recurring tick; missing cron still requires an external runner. */
	public static function ensure_scheduled(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- ADR-0012: deliberately bounded alert cadence, no unbounded mail processing.
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		if ( wp_next_scheduled( ScheduledHooks::ALERTS ) && 'scalyn_five_minutes' !== wp_get_schedule( ScheduledHooks::ALERTS ) ) {
			wp_clear_scheduled_hook( ScheduledHooks::ALERTS );
		}
		if ( ! wp_next_scheduled( ScheduledHooks::ALERTS ) ) {
			wp_schedule_event( time() + 300, 'scalyn_five_minutes', ScheduledHooks::ALERTS );
		}
	}

	/** Runs three rules, at most five attempts, and one bounded retention batch. */
	public function tick(): void {
		$this->repository->exclusive(
			function (): void {
				$now      = time();
				$settings = new SettingsRepository();
				foreach ( $this->observations->conditions( $now ) as $type => $condition ) {
						$change = $this->repository->transition( $type, $condition, $settings->get_alert_webhook_enabled(), $now );
					if ( $change ) {
						$this->audit( 'alert_incident', $change['event'], $change['uuid'] );
					}
				}
				foreach ( $this->repository->due( $now ) as $job ) {
					$attempts = (int) $job['attempts'];
					if ( ! ( new SettingsRepository() )->get_alert_webhook_enabled() || $attempts >= 3 ) {
						$status = $attempts >= 3 ? 'failed' : 'skipped';
						$this->repository->progress( $job, $status, $attempts, 0, $now );
					} else {
						++$attempts;
						$delay = 1 === $attempts ? 60 : 300;
						$this->repository->progress( $job, 'sending', $attempts, 0, $now, $delay );
						$code   = $this->channel->send( $job );
						$status = $code >= 200 && $code < 300 ? 'sent' : ( $attempts >= 3 ? 'failed' : 'pending' );
						$this->repository->progress( $job, $status, $attempts, $code, $now, $delay );
					}
					$this->audit( 'alert_notification', 'pending' === $status ? 'retry' : $status, $job['notification_uuid'] );
				}
				$this->repository->retain( $now - $settings->get_log_retention_days() * 86400 );
			}
		);
	}

	/**
	 * Best-effort append-only audit never changes a committed incident outcome.
	 *
	 * @param string $action Fixed action.
	 * @param string $outcome Fixed outcome.
	 * @param string $uuid Correlation.
	 */
	private function audit( string $action, string $outcome, string $uuid ): void {
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( $action, $outcome, $uuid ) );
		} catch ( \Throwable $error ) {
			// Do not repeat an incident/notification because an audit observer failed.
			return;
		}
	}
}
