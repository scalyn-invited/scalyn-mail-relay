<?php
/**
 * Evidence-bound, deterministic diagnostic recommendations.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

defined( 'ABSPATH' ) || exit;

/** Read-only rules. Never interprets record presence as message authentication. */
final class RecommendationEngine {

	/**
	 * Produces at most five recommendations plus a refresh notice.
	 *
	 * @param array  $rows Latest retained diagnostic run rows.
	 * @param string $cadence Monitoring cadence.
	 * @param int    $now UTC evaluation timestamp.
	 * @return array Refresh flag and ordered safe items; no raw diagnostic strings.
	 */
	public function recommend( array $rows, string $cadence, int $now ): array {
		$rules    = $this->rules();
		$by_check = array();
		$refresh  = count( $rows ) > 250;
		$run      = null;
		foreach ( array_slice( $rows, 0, 250 ) as $row ) {
			$name = $row['check_name'] ?? '';
			if ( ! is_string( $name ) || ! isset( $rules[ $name ] ) ) {
				continue;
			}
			if ( isset( $by_check[ $name ] ) ) {
				// Ambiguous duplicate findings must be refreshed, not arbitrarily selected.
				$refresh = true;
			}
			$by_check[ $name ] = $row;
			$uuid              = $row['diagnostic_uuid'] ?? '';
			if ( ! is_string( $uuid ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $uuid ) || ( null !== $run && $run !== $uuid ) ) {
				$refresh = true;
			}
			$run = $uuid;
			if ( ! $this->recent( $row['created_at'] ?? null, $cadence, $now ) ) {
				$refresh = true;
			}
		}
		if ( $refresh || array() === $by_check ) {
			return array(
				'refresh' => true,
				'items'   => array(),
			);
		}
		$items          = array();
		$severity_order = array(
			'critical' => 0,
			'high'     => 1,
			'medium'   => 2,
			'low'      => 3,
		);
		foreach ( $rules as $name => $rule ) {
			$row    = $by_check[ $name ] ?? array();
			$status = $row['status'] ?? 'unknown';
			if ( 'pass' === $status ) {
				continue;
			}
			$observed = in_array( $status, array( 'fail', 'warn' ), true );
			$severity = $row['severity'] ?? '';
			$severity = is_string( $severity ) && isset( $severity_order[ $severity ] ) ? $severity : 'unknown';
			$items[]  = array(
				'check'       => $name,
				'title'       => $rule[0],
				'kind'        => $observed ? ( 'fail' === $status ? __( 'Recorded failure', 'scalyn-mail-relay' ) : __( 'Recorded warning', 'scalyn-mail-relay' ) ) : __( 'Evidence needed', 'scalyn-mail-relay' ),
				'impact'      => $observed ? $rule[1] : __( 'This check has not established a usable result; no configuration failure is inferred.', 'scalyn-mail-relay' ),
				'action'      => $observed ? $rule[2] : $rule[3],
				'severity'    => $severity,
				'run_uuid'    => $row['diagnostic_uuid'] ?? '',
				'recorded_at' => $row['created_at'] ?? '',
				'rank'        => ( 'fail' === $status ? 0 : ( 'warn' === $status ? 10 : 20 ) ) + ( $severity_order[ $severity ] ?? 4 ),
			);
		}
		usort( $items, static fn( array $a, array $b ): int => $a['rank'] === $b['rank'] ? strcmp( $a['check'], $b['check'] ) : $a['rank'] <=> $b['rank'] );
		return array(
			'refresh' => false,
			'items'   => $items,
		);
	}

	/**
	 * Validates freshness using the monitoring policy, without parsing display text.
	 *
	 * @param mixed  $value Site-local timestamp.
	 * @param string $cadence Monitoring cadence.
	 * @param int    $now UTC timestamp.
	 * @return bool Whether evidence is recent and not in the future.
	 */
	private function recent( mixed $value, string $cadence, int $now ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return false;
		}
		$intervals = array(
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
		);
		$age       = $now - $date->getTimestamp();
		return $age >= 0 && $age <= ( $intervals[ $cadence ] ?? 86400 ) + 300;
	}

	/**
	 * Defines reviewed guidance for the supported checks.
	 *
	 * @return array Fixed titles, impacts, review steps and evidence-acquisition steps.
	 */
	private function rules(): array {
		return array(
			'smtp_tls'     => array(
				__( 'Review SMTP transport security', 'scalyn-mail-relay' ),
				__( 'The stored transport check reported a security or connectivity concern; it does not prove recipient rejection.', 'scalyn-mail-relay' ),
				__( 'Review the SMTP/TLS finding. Confirm host, port and encryption with your provider, correct the reported issue, then rerun the connection check. Do not disable certificate verification.', 'scalyn-mail-relay' ),
				__( 'Confirm provider configuration and retry diagnostics. An unavailable connection check does not establish a transport failure.', 'scalyn-mail-relay' ),
			),
			'spf_record'   => array(
				__( 'Review the SPF record finding', 'scalyn-mail-relay' ),
				__( 'An SPF record issue may affect sender authorization, but this check does not evaluate the actual outbound IP.', 'scalyn-mail-relay' ),
				__( 'Review the SPF finding. Ask your provider for the required SPF policy and actual envelope-sender domain before correcting DNS. Do not blindly authorize an IP. Rerun diagnostics after the change.', 'scalyn-mail-relay' ),
				__( 'Retry the DNS check and confirm the checked domain with your provider. Unknown SPF evidence is not an authentication failure.', 'scalyn-mail-relay' ),
			),
			'dkim_record'  => array(
				__( 'Review DKIM record configuration', 'scalyn-mail-relay' ),
				__( 'A selector or public-key record issue may prevent verification. This check does not inspect actual message signatures.', 'scalyn-mail-relay' ),
				__( 'Compare the selector and domain in the DKIM finding with your provider configuration. Confirm provider signing and its published key, then rerun diagnostics. Entering a selector alone does not enable signing.', 'scalyn-mail-relay' ),
				__( 'Confirm your provider signing domain and selector. If the selector is missing, configure it in Settings; otherwise review DNS lookup availability and rerun. Do not guess a selector or infer missing signatures.', 'scalyn-mail-relay' ),
			),
			'dmarc_policy' => array(
				__( 'Review DMARC policy', 'scalyn-mail-relay' ),
				__( 'The published policy needs review; actual message authentication and alignment are not evaluated here.', 'scalyn-mail-relay' ),
				__( 'Review the DMARC finding and reporting data with your provider. Verify legitimate sender alignment before tightening enforcement; an enforcing policy can reject legitimate unauthenticated mail.', 'scalyn-mail-relay' ),
				__( 'Confirm DNS lookup availability and rerun the DMARC check. Missing evidence is not proof of failed message alignment.', 'scalyn-mail-relay' ),
			),
			'mx_record'    => array(
				__( 'Review inbound MX records', 'scalyn-mail-relay' ),
				__( 'MX describes inbound mail routing, not whether outbound SMTP or recipient delivery works.', 'scalyn-mail-relay' ),
				__( 'Confirm whether the checked domain should receive mail. Compare its MX records with the receiving provider requirements before changing DNS; outbound-only domains may intentionally not receive mail.', 'scalyn-mail-relay' ),
				__( 'Confirm the domain receiving-mail requirements and retry the MX lookup. Do not infer an outbound delivery failure.', 'scalyn-mail-relay' ),
			),
		);
	}
}
