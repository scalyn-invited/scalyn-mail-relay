<?php
/**
 * Evidence-based incident policies.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Alerts;

defined( 'ABSPATH' ) || exit;

/** Pure rules: null means unknown/hold, never recovery. */
final class IncidentRules {

	public const TYPES = array( 'send_failures', 'monitoring', 'health' );

	/**
	 * Evaluates bounded, credential-free observations.
	 *
	 * @param array $mail Latest terminal sends, newest first, UTC epoch timestamps.
	 * @param array $monitor Schedule enabled, due timestamp and latest scheduled run.
	 * @param array $health Latest score and epoch timestamp.
	 * @param int   $now UTC epoch.
	 * @return array Tri-state incident conditions.
	 */
	public function evaluate( array $mail, array $monitor, array $health, int $now ): array {
		$send  = null;
		$fresh = static fn( $at, $age ): bool => is_int( $at ) && $at <= $now && $at >= $now - $age;
		if ( isset( $mail[0] ) && $fresh( $mail[0]['at'] ?? null, 900 ) ) {
			if ( 'accepted' === ( $mail[0]['status'] ?? '' ) ) {
				$send = false;
			} elseif ( 3 === count( $mail ) && 3 === count( array_filter( $mail, static fn( $row ) => 'failed' === ( $row['status'] ?? '' ) && $fresh( $row['at'] ?? null, 900 ) ) ) ) {
				$send = true;
			}
		}
		$monitoring = null;
		if ( true === ( $monitor['enabled'] ?? false ) ) {
			$run   = $monitor['run'] ?? array();
			$due   = $monitor['due'] ?? 0;
			$state = $run['state'] ?? '';
			if ( ! $due || $due < $now - 900 || 'failed' === $state || ( 'running' === $state && ( $run['started'] ?? $now ) < $now - 900 ) ) {
				$monitoring = true;
			} elseif ( 'completed' === $state && $fresh( $run['finished'] ?? null, ( $monitor['interval'] ?? 86400 ) + 900 ) ) {
				$monitoring = false;
			}
		}
		$degraded = null;
		if ( isset( $health['score'] ) && is_int( $health['score'] ) && $health['score'] >= 0 && $health['score'] <= 100 && $fresh( $health['at'] ?? null, 86400 ) ) {
			$degraded = $health['score'] < 60 ? true : ( $health['score'] >= 75 ? false : null );
		}
		return array(
			'send_failures' => $send,
			'monitoring'    => $monitoring,
			'health'        => $degraded,
		);
	}

	/**
	 * Fixed actionable text; no source response data.
	 *
	 * @param string $type Recognized incident type.
	 * @param bool   $recovered Whether recovery was observed.
	 * @return string Description and next action.
	 */
	public static function description( string $type, bool $recovered = false ): string {
		if ( $recovered ) {
			return match ( $type ) {
				'send_failures' => __( 'A subsequent send was Accepted by the configured provider. The repeated-send-failure incident has recovered; this is not confirmation of inbox delivery.', 'scalyn-mail-relay' ),
				'monitoring' => __( 'Scheduled monitoring has a fresh completed run and a current schedule. The monitoring incident has recovered.', 'scalyn-mail-relay' ),
				'health' => __( 'A fresh health score reached at least 75. The health-degradation incident has recovered; review Diagnostics for the latest evidence.', 'scalyn-mail-relay' ),
				default => __( 'Unrecognized historical incident.', 'scalyn-mail-relay' ),
			};
		}
		return match ( $type ) {
			'send_failures' => __( 'Three consecutive sends failed within 15 minutes. Review Email Logs and provider configuration. Recovery means a subsequent provider acceptance, not confirmed delivery.', 'scalyn-mail-relay' ),
			'monitoring' => __( 'Scheduled monitoring failed, is missing, or is overdue by more than 15 minutes. Review Diagnostics, the configured cadence, database access, and the WordPress cron runner.', 'scalyn-mail-relay' ),
			'health' => __( 'The latest health score is below 60. Review the persisted diagnostic evidence and remediation steps. Recovery requires a fresh score of at least 75.', 'scalyn-mail-relay' ),
			default => __( 'Unrecognized historical incident.', 'scalyn-mail-relay' ),
		};
	}
}
