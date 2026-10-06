<?php
/**
 * Current-provider health read model (ADR-0027).
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Health;

use Scalyn\MailRelay\Core\ConnectionVerification;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Logging\MailLogRepository;

defined( 'ABSPATH' ) || exit;

/** Computes explainable state from revision-scoped retained evidence only. */
final class ProviderHealthAssessment {
	/**
	 * Creates the current-configuration health read model.
	 *
	 * @param SettingsRepository            $settings Current provider and revision.
	 * @param ConnectionVerification        $connection Revision-keyed connection evidence.
	 * @param MailLogRepository             $logs Revision-keyed terminal submission counts.
	 * @param ProviderDeliveryEvidence|null $delivery Optional delivery coverage service.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ConnectionVerification $connection,
		private readonly MailLogRepository $logs,
		private readonly ?ProviderDeliveryEvidence $delivery = null
	) {}

	/**
	 * Returns only the selected provider's current configuration assessment.
	 *
	 * @return array<string,mixed> Safe, count-only provider card data.
	 */
	public function current(): array {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return self::evaluate(
				'',
				'',
				array(
					'status'     => 'unknown',
					'checked_at' => null,
				),
				null,
				null
			);
		}
		$provider   = $this->settings->get_active_provider_id();
		$revision   = $this->settings->get_diagnostic_revision();
		$connection = $this->connection->current();
		$day        = $this->logs->configuration_status_counts( $revision, $provider, 24 );
		$week       = $this->logs->configuration_status_counts( $revision, $provider, 168 );
		return self::evaluate( $provider, $revision, $connection, $day, $week, $this->delivery?->current() );
	}

	/**
	 * Deterministically applies only rules supported by the passed evidence.
	 *
	 * @param string     $provider Provider identifier.
	 * @param string     $revision Opaque revision identifier.
	 * @param array      $connection `status`, `checked_at`.
	 * @param array|null $day 24-hour accepted/failed/total counts.
	 * @param array|null $week Seven-day accepted/failed/total counts.
	 * @param array|null $delivery Current-source delivery counts and availability.
	 * @return array<string,mixed> Safe assessment.
	 */
	public static function evaluate( string $provider, string $revision, array $connection, ?array $day, ?array $week, ?array $delivery = null ): array {
		$delivery           = self::delivery_summary( $delivery );
		$connection_status  = in_array( $connection['status'] ?? '', array( 'passed', 'failed', 'stale' ), true ) ? $connection['status'] : 'unknown';
		$day                = self::normalise_counts( $day );
		$week               = self::normalise_counts( $week );
		$rate               = $day['total'] >= 10 ? $day['failed'] / $day['total'] : null;
		$state              = 'unknown';
		$findings           = array();
		$recommended_action = __( 'Run a connection check. Health remains unknown until enough current-configuration submission evidence is retained.', 'scalyn-mail-relay' );
		if ( null !== $rate && $rate > 0.20 ) {
			$state              = 'critical';
			$recommended_action = __( 'Review recent failed submissions and the provider configuration before relying on this route.', 'scalyn-mail-relay' );
			/* translators: 1: Failed attributed submissions. 2: Total attributed submissions. */
			$findings[] = sprintf( __( '%1$d of %2$d attributed submissions failed in the last 24 hours.', 'scalyn-mail-relay' ), $day['failed'], $day['total'] );
		} elseif ( null !== $rate && $rate >= 0.05 ) {
			$state              = 'warning';
			$recommended_action = __( 'Review recent failed submissions and correct the underlying provider or configuration issue.', 'scalyn-mail-relay' );
			/* translators: 1: Failed attributed submissions. 2: Total attributed submissions. */
			$findings[] = sprintf( __( '%1$d of %2$d attributed submissions failed in the last 24 hours.', 'scalyn-mail-relay' ), $day['failed'], $day['total'] );
		} elseif ( null !== $delivery['rate'] && $delivery['rate'] > 0.05 ) {
			$state              = 'warning';
			$recommended_action = __( 'Review hard bounces and recipient quality in your provider account. Do not automatically resend bounced messages.', 'scalyn-mail-relay' );
			/* translators: 1: Hard-bounced recipient attempts, 2: Observed recipient attempts. */
			$findings[] = sprintf( __( '%1$d of %2$d tracked recipient attempts with evidence hard-bounced in the delivery window (over 5%%).', 'scalyn-mail-relay' ), $delivery['hard_bounced'], $delivery['observed'] );
		} elseif ( 'stale' === $connection_status ) {
			$state              = 'warning';
			$recommended_action = __( 'Run a new connection check for the current configuration.', 'scalyn-mail-relay' );
			$findings[]         = __( 'Connection evidence is older than seven days.', 'scalyn-mail-relay' );
		} elseif ( 'passed' === $connection_status && $week['total'] >= 10 && ! $day['available'] ) {
			$findings[] = __( 'The 24-hour submission evidence is unavailable; health cannot be confirmed from older totals.', 'scalyn-mail-relay' );
		} elseif ( 'passed' === $connection_status && $week['total'] >= 10 && in_array( $delivery['status'], array( 'insufficient', 'partial', 'unavailable', 'paused' ), true ) ) {
			$findings[]         = $delivery['explanation'];
			$recommended_action = __( 'Review webhook collection and wait for sufficient current-configuration evidence. Missing callbacks are not delivery failures.', 'scalyn-mail-relay' );
		} elseif ( 'passed' === $connection_status && $week['total'] >= 10 ) {
			$state              = 'healthy';
			$recommended_action = __( 'Continue monitoring; this state does not confirm delivery or inbox placement.', 'scalyn-mail-relay' );
			$findings[]         = __( 'Current configuration has a fresh passed connection check and enough recent attributed submissions.', 'scalyn-mail-relay' );
		} else {
			$findings[] = self::unknown_reason( $connection_status, $week );
		}
		return array(
			'provider'           => $provider,
			'configuration_id'   => $revision,
			'connection'         => array(
				'status'     => $connection_status,
				'checked_at' => is_string( $connection['checked_at'] ?? null ) ? $connection['checked_at'] : null,
			),
			'health'             => $state,
			'window_24h'         => $day,
			'window_7d'          => $week,
			'failure_rate'       => $rate,
			'delivery'           => $delivery,
			'findings'           => $findings,
			'recommended_action' => $recommended_action,
			'limitations'        => array(
				__( 'Only Accepted and Failed submissions attributed to the current configuration are counted.', 'scalyn-mail-relay' ),
				__( 'Unattributed history, unconfirmed outcomes and missing evidence are excluded.', 'scalyn-mail-relay' ),
				__( 'Bounce-rate assessment uses only current-source authenticated recipient evidence; missing callbacks are not passes or failures.', 'scalyn-mail-relay' ),
				__( 'Category-based connection and submission failure rules remain unassessed until those categories are retained.', 'scalyn-mail-relay' ),
			),
		);
	}

	/**
	 * Keeps unknown/partial evidence distinct from a zero bounce rate.
	 *
	 * @param array|null $input Count-only current-source projection.
	 * @return array Safe presentation and rule inputs.
	 */
	private static function delivery_summary( ?array $input ): array {
		$status = $input['status'] ?? 'unsupported';
		if ( ! in_array( $status, array( 'available', 'off', 'paused', 'unsupported', 'unavailable' ), true ) ) {
			$status = 'unavailable'; }
		$result = array(
			'status'       => $status,
			'tracked'      => null,
			'observed'     => null,
			'hard_bounced' => null,
			'coverage'     => null,
			'rate'         => null,
			'window_start' => null,
			'window_end'   => null,
			'days'         => null,
		);
		if ( 'available' === $status ) {
			foreach ( array( 'tracked', 'observed', 'hard_bounced' ) as $key ) {
				if ( ! is_int( $input[ $key ] ?? null ) || $input[ $key ] < 0 || $input[ $key ] > 50000 ) {
					$status = 'unavailable'; }
			}
			if ( 'available' === $status && ( $input['observed'] > $input['tracked'] || $input['hard_bounced'] > $input['observed'] ) ) {
				$status = 'unavailable'; }
			if ( 'available' === $status ) {
				foreach ( array( 'tracked', 'observed', 'hard_bounced' ) as $key ) {
					$result[ $key ] = $input[ $key ]; }
				$result['coverage'] = $input['tracked'] > 0 ? $input['observed'] / $input['tracked'] : null;
				$result['rate']     = $input['observed'] >= 20 ? $input['hard_bounced'] / $input['observed'] : null;
				$status             = $input['observed'] < 20 ? 'insufficient' : ( $input['observed'] < $input['tracked'] ? 'partial' : 'assessed' );
				foreach ( array( 'window_start', 'window_end' ) as $key ) {
					$result[ $key ] = is_string( $input[ $key ] ?? null ) ? $input[ $key ] : null; }
				$result['days'] = is_int( $input['days'] ?? null ) ? $input['days'] : null;
			}
		}
		$result['status']      = $status;
		$result['explanation'] = match ( $status ) {
			'off' => __( 'Collection is not enabled; health uses connection and submission evidence only.', 'scalyn-mail-relay' ),
			'paused' => __( 'Delivery collection is paused for this configuration. No prior-source evidence is reused.', 'scalyn-mail-relay' ),
			'insufficient' => __( 'Fewer than 20 tracked recipient attempts have current delivery or bounce evidence. Bounce rate is not assessed.', 'scalyn-mail-relay' ),
			'partial' => __( 'Some tracked recipient outcomes remain unknown. The bounce rate describes observed recipients only and cannot establish healthy delivery.', 'scalyn-mail-relay' ),
			'assessed' => __( 'Authenticated recipient evidence is available. Recipient-server delivery does not prove inbox placement.', 'scalyn-mail-relay' ),
			'unsupported' => __( 'Out-of-band delivery/bounce evidence is unavailable for this provider.', 'scalyn-mail-relay' ),
			default => __( 'Delivery evidence is unavailable or exceeds the bounded assessment limit. It is not treated as healthy.', 'scalyn-mail-relay' ),
		};
		return $result;
	}

	/**
	 * Normalises untrusted repository values; unavailable counts remain zero.
	 *
	 * @param array<string,int>|null $counts Repository counts.
	 * @return array{accepted:int,failed:int,total:int,available:bool} Normalised counts.
	 */
	private static function normalise_counts( ?array $counts ): array {
		$accepted = max( 0, (int) ( $counts['accepted'] ?? 0 ) );
		$failed   = max( 0, (int) ( $counts['failed'] ?? 0 ) );
		return array(
			'accepted'  => $accepted,
			'failed'    => $failed,
			'total'     => $accepted + $failed,
			'available' => null !== $counts,
		);
	}

	/**
	 * Explains why insufficient evidence is neither success nor failure.
	 *
	 * @param string                                                  $connection_status Current connection state.
	 * @param array{accepted:int,failed:int,total:int,available:bool} $week Seven-day counts.
	 * @return string Safe finding text.
	 */
	private static function unknown_reason( string $connection_status, array $week ): string {
		if ( 'failed' === $connection_status ) {
			return __( 'The current connection check failed. Its failure category is not retained, so this alone is not classified as a health failure.', 'scalyn-mail-relay' );
		}
		if ( 'unknown' === $connection_status ) {
			return __( 'No usable connection check exists for the current configuration.', 'scalyn-mail-relay' );
		}
		if ( ! $week['available'] ) {
			return __( 'Recent submission evidence is unavailable for this configuration.', 'scalyn-mail-relay' );
		}
		return __( 'Fewer than 10 attributed Accepted or Failed submissions exist in the last seven days.', 'scalyn-mail-relay' );
	}
}
