<?php
/** Evidence-based estimate policy, ADR-0026.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

defined( 'ABSPATH' ) || exit;

/** Pure, replayable calculation. Unknown evidence never acquires points. */
final class DeliverabilityScorer {

	public const VERSION = 'adr0026-v1';
	public const WEIGHTS = array(
		'authentication'         => 35,
		'domain_alignment'       => 15,
		'dns_quality'            => 10,
		'historical_performance' => 40,
		'header_quality'         => 0,
		'provider_reputation'    => 0,
	);

	/** Evaluates only allowlisted counts/statuses; arbitrary caller data is discarded.
	 *
	 * @param array $input Credential-free evidence.
	 * @return array Persistable trace, including the exact evaluation instant.
	 */
	public function evaluate( array $input ): array {
		$now        = is_int( $input['evaluated_at'] ?? null ) ? $input['evaluated_at'] : 0;
		$ttl        = min( 604800, max( 1, (int) ( $input['fresh_for'] ?? 604800 ) ) );
		$days       = min( 30, max( 1, (int) ( $input['days'] ?? 30 ) ) );
		$checks     = array();
		$points     = array(
			'pass' => 100,
			'warn' => 50,
			'fail' => 0,
		);
		$components = array();
		foreach ( self::WEIGHTS as $key => $weight ) {
			$components[ $key ] = array(
				'weight'   => $weight,
				'score'    => null,
				'included' => array(),
				'excluded' => array(),
			);
		}
		$recommendations = array();
		$auth_count      = 0;
		foreach ( array( 'spf_record', 'dkim_record', 'dmarc_policy', 'mx_record', 'reverse_dns' ) as $name ) {
			$row             = $input['checks'][ $name ] ?? array();
			$status          = in_array( $row['status'] ?? '', array( 'pass', 'warn', 'fail', 'unknown', 'error', 'stale', 'unavailable', 'not_assessed' ), true ) ? $row['status'] : 'unknown';
			$checked         = is_int( $row['checked_at'] ?? null ) ? $row['checked_at'] : 0;
			$checks[ $name ] = array(
				'status'     => $status,
				'checked_at' => $checked,
			);
			$eligible        = isset( $points[ $status ] ) && $checked > 0 && $checked <= $now && $now - $checked <= $ttl;
			$reason          = ! isset( $points[ $status ] ) ? $status : ( $eligible ? '' : 'stale' );
			$component       = in_array( $name, array( 'mx_record', 'reverse_dns' ), true ) ? 'dns_quality' : 'authentication';
			if ( 'reverse_dns' === $name && 'smtp' !== ( $input['transport'] ?? '' ) ) {
				$eligible = false;
				$reason   = 'not_applicable';
			}
			if ( $eligible ) {
				$components[ $component ]['included'][ $name ] = $points[ $status ];
				$auth_count                                   += 'authentication' === $component ? 1 : 0;
				if ( 'pass' !== $status ) {
					$recommendations[] = array(
						'priority' => 'fail' === $status ? 'Critical' : 'Important',
						'reason'   => 'Review ' . $name . ' in Diagnostics; confirm actual message authentication with receiver evidence.',
					);
				}
			} else {
				$components[ $component ]['excluded'][ $name ] = $reason;
			}
		}
		// A configured selector means alignment is possible, not verified. Only
		// this explicit DMARC configuration verdict contributes (warn = 50).
		$alignment = 'possible_with_configured_selector' === ( $input['alignment'] ?? '' ) ? 'possible_with_configured_selector' : 'not_assessed';
		if ( isset( $components['authentication']['included']['dmarc_policy'] ) && 'possible_with_configured_selector' === $alignment ) {
			$components['domain_alignment']['included']['dmarc_configuration'] = 50;
			$recommendations[] = array(
				'priority' => 'Important',
				'reason'   => 'Confirm SPF or DKIM alignment on an actual message; configured DKIM alignment is only possible, not verified.',
			);
		} else {
			$components['domain_alignment']['excluded']['dmarc_configuration'] = 'not_assessed';
		}
		$submission = $this->counts( $input['submissions'] ?? null, array( 'accepted', 'failed' ) );
		$total      = null === $submission ? 0 : $submission['accepted'] + $submission['failed'];
		if ( $total >= 20 ) {
			$components['historical_performance']['included']['submission_reliability'] = $this->rate_points( $submission['failed'], $total );
		} else {
			$components['historical_performance']['excluded']['submission_reliability'] = null === $submission ? 'unavailable' : 'minimum_20_submissions';
		}
		$delivery_status = in_array( $input['delivery']['status'] ?? '', array( 'available', 'off', 'paused', 'unsupported', 'unavailable' ), true ) ? $input['delivery']['status'] : 'unavailable';
		$delivery_counts = $this->counts( $input['delivery'] ?? null, array( 'tracked', 'observed', 'hard_bounced' ) );
		if ( null !== $delivery_counts && ( $delivery_counts['observed'] > $delivery_counts['tracked'] || $delivery_counts['hard_bounced'] > $delivery_counts['observed'] ) ) {
			$delivery_counts = null;
		}
		$coverage = null !== $delivery_counts && $delivery_counts['tracked'] > 0 ? $delivery_counts['observed'] / $delivery_counts['tracked'] : null;
		if ( $total >= 20 && 'available' === $delivery_status && null !== $coverage && $delivery_counts['observed'] >= 20 && $coverage >= 0.5 ) {
			$components['historical_performance']['included']['hard_bounce_rate'] = $this->rate_points( $delivery_counts['hard_bounced'], $delivery_counts['observed'] );
		} else {
			$components['historical_performance']['excluded']['hard_bounce_rate'] = 'available' === $delivery_status ? 'minimum_20_observed_and_50_percent_coverage' : $delivery_status;
		}
		$components['header_quality']['excluded']['message_headers']        = 'informational_only';
		$components['provider_reputation']['excluded']['reputation_source'] = 'unavailable';
		$weight = 0;
		$sum    = 0;
		foreach ( $components as $key => &$component ) {
			if ( $component['included'] ) {
				$component['score'] = array_sum( $component['included'] ) / count( $component['included'] );
				$weight            += $component['weight'];
				$sum               += $component['score'] * $component['weight'];
				if ( 'historical_performance' === $key && $component['score'] < 100 ) {
					$recommendations[] = array(
						'priority' => $component['score'] < 50 ? 'Critical' : 'Important',
						'reason'   => 'Review submission failures and authenticated hard-bounce evidence in Email Logs.',
					);
				}
			}
		}
		unset( $component );
		$missing = array();
		if ( $auth_count < 2 ) {
			$missing[] = 'At least two fresh SPF, DKIM or DMARC results are required.';
		}
		if ( $total < 20 ) {
			$missing[] = 'At least 20 Accepted/Failed submissions for this configuration are required.';
		}
		if ( $weight < 60 ) {
			$missing[] = 'At least 60% component-weight coverage is required.';
		}
		foreach ( $missing as $reason ) {
			$recommendations[] = array(
				'priority' => 'Important',
				'reason'   => $reason,
			);
		}
		$recommendations[] = array(
			'priority' => 'Optional',
			'reason'   => 'Analyze received-message headers for informational verdicts. Header quality and reputation do not contribute to this score.',
		);
		$order             = array(
			'Critical'  => 0,
			'Important' => 1,
			'Optional'  => 2,
		);
		usort( $recommendations, static fn( array $a, array $b ): int => $order[ $a['priority'] ] <=> $order[ $b['priority'] ] );
		return array(
			'weights_version'      => self::VERSION,
			'score'                => $missing ? null : (int) floor( $sum / $weight ),
			'components'           => $components,
			'weight_coverage'      => $weight,
			'authentication_count' => $auth_count,
			'delivery_coverage'    => $coverage,
			'missing'              => $missing,
			'recommendations'      => $recommendations,
			'inputs'               => array(
				'evaluated_at' => $now,
				'fresh_for'    => $ttl,
				'days'         => $days,
				'window_start' => $now - $days * 86400,
				'window_end'   => $now,
				'transport'    => 'smtp' === ( $input['transport'] ?? '' ) ? 'smtp' : 'api',
				'checks'       => $checks,
				'alignment'    => $alignment,
				'submissions'  => $submission,
				'delivery'     => array_merge(
					array(
						'status'    => $delivery_status,
						'source_id' => preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $input['delivery']['source_id'] ?? '' ) ) ? $input['delivery']['source_id'] : '',
					),
					$delivery_counts ?? array()
				),
			),
		);
	}

	/** Validates aggregate evidence without coercing missing or corrupt counts.
	 *
	 * @param mixed $value Candidate aggregate.
	 * @param array $keys Allowed count names.
	 */
	private function counts( mixed $value, array $keys ): ?array {
		$out = array();
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! is_int( $value[ $key ] ?? null ) || $value[ $key ] < 0 || $value[ $key ] > 100000000 ) {
				return null;
			}
			$out[ $key ] = $value[ $key ];
		}
		return $out;
	}

	/** Uses integer cross-products for exact 2% and 5% boundaries.
	 *
	 * @param int $bad Failed outcomes.
	 * @param int $total Eligible outcomes, positive.
	 */
	private function rate_points( int $bad, int $total ): int {
		return $bad * 100 < $total * 2 ? 100 : ( $bad * 100 <= $total * 5 ? 50 : 0 );
	}
}
