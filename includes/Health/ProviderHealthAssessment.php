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
	 * @param SettingsRepository     $settings Current provider and revision.
	 * @param ConnectionVerification $connection Revision-keyed connection evidence.
	 * @param MailLogRepository      $logs Revision-keyed terminal submission counts.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ConnectionVerification $connection,
		private readonly MailLogRepository $logs
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
		return self::evaluate( $provider, $revision, $connection, $day, $week );
	}

	/**
	 * Deterministically applies only rules supported by the passed evidence.
	 *
	 * @param string     $provider Provider identifier.
	 * @param string     $revision Opaque revision identifier.
	 * @param array      $connection `status`, `checked_at`.
	 * @param array|null $day 24-hour accepted/failed/total counts.
	 * @param array|null $week Seven-day accepted/failed/total counts.
	 * @return array<string,mixed> Safe assessment.
	 */
	public static function evaluate( string $provider, string $revision, array $connection, ?array $day, ?array $week ): array {
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
		} elseif ( 'stale' === $connection_status ) {
			$state              = 'warning';
			$recommended_action = __( 'Run a new connection check for the current configuration.', 'scalyn-mail-relay' );
			$findings[]         = __( 'Connection evidence is older than seven days.', 'scalyn-mail-relay' );
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
			'findings'           => $findings,
			'recommended_action' => $recommended_action,
			'limitations'        => array(
				__( 'Only Accepted and Failed submissions attributed to the current configuration are counted.', 'scalyn-mail-relay' ),
				__( 'Unattributed history, unconfirmed outcomes and missing evidence are excluded.', 'scalyn-mail-relay' ),
				in_array( $provider, array( 'postmark', 'smtp2go', 'brevo' ), true ) ? __( 'Bounce-rate assessment needs aggregated tracked-recipient coverage and is not assessed here.', 'scalyn-mail-relay' ) : __( 'Out-of-band delivery/bounce evidence is unavailable for this provider.', 'scalyn-mail-relay' ),
			),
		);
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
