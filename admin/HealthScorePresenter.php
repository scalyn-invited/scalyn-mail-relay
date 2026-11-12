<?php
/**
 * Health score presentation helper.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a persisted health score row into the values the admin views render.
 *
 * Both the Dashboard and the Diagnostics page must present the same number,
 * from the same source, with the same status thresholds. The single source is
 * HealthScoreRepository::find_latest(), i.e. the last HealthScorer::score()
 * snapshot. Nothing here computes a score; it only reads what the scorer
 * persisted and formats it, including the per-component breakdown and the
 * scorer's own summary of which components had evidence.
 *
 * Ownership: Kim / Admin.
 */
final class HealthScorePresenter {

	/**
	 * Lowest overall score presented as "healthy".
	 */
	public const HEALTHY_MIN = 80;

	/**
	 * Lowest overall score presented as "warning"; anything lower is "critical".
	 */
	public const WARNING_MIN = 60;

	/**
	 * Builds the view model for a health score row.
	 *
	 * @param array<string, mixed>|null $row Row from HealthScoreRepository::find_latest(), or null when none exists.
	 * @return array{
	 *   score: int|null,
	 *   ui_status: string,
	 *   label: string,
	 *   components: array<string, int|null>,
	 *   summary: string,
	 *   created_at: string|null
	 * }
	 */
	public static function present( ?array $row ): array {
		$score = null !== $row ? self::int_or_null( $row['overall_score'] ?? null ) : null;

		return array(
			'score'      => $score,
			'ui_status'  => self::ui_status( $score ),
			'label'      => self::label( $score ),
			'components' => array(
				__( 'DNS & authentication', 'scalyn-mail-relay' )   => null !== $row ? self::int_or_null( $row['dns_score'] ?? null ) : null,
				__( 'Provider & transport', 'scalyn-mail-relay' )   => null !== $row ? self::int_or_null( $row['provider_score'] ?? null ) : null,
				__( 'Operational reliability', 'scalyn-mail-relay' ) => null !== $row ? self::int_or_null( $row['failure_score'] ?? null ) : null,
			),
			'summary'    => null !== $row ? trim( (string) ( $row['summary'] ?? '' ) ) : '',
			'created_at' => null !== $row && ! empty( $row['created_at'] ) ? (string) $row['created_at'] : null,
		);
	}

	/**
	 * Maps an overall score to the UI status vocabulary used by StatusBadge.
	 *
	 * @param int|null $score Overall score, or null when no score exists.
	 * @return string One of 'healthy', 'warning', 'critical', 'unknown'.
	 */
	public static function ui_status( ?int $score ): string {
		if ( null === $score ) {
			return 'unknown';
		}
		if ( $score >= self::HEALTHY_MIN ) {
			return 'healthy';
		}
		if ( $score >= self::WARNING_MIN ) {
			return 'warning';
		}
		return 'critical';
	}

	/**
	 * Formats the badge label for an overall score.
	 *
	 * @param int|null $score Overall score, or null when no score exists.
	 */
	public static function label( ?int $score ): string {
		if ( null === $score ) {
			return __( 'Unknown', 'scalyn-mail-relay' );
		}
		/* translators: %d is the health score number (0-100) */
		return sprintf( __( '%d/100', 'scalyn-mail-relay' ), $score );
	}

	/**
	 * Casts a database value to int, treating null and non-numeric values as "no evidence".
	 *
	 * @param mixed $value Raw column value.
	 */
	private static function int_or_null( mixed $value ): ?int {
		return ( null !== $value && is_numeric( $value ) ) ? (int) $value : null;
	}
}
