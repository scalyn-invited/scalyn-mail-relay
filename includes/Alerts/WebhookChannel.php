<?php
/**
 * Independent, protected webhook channel.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Alerts;

defined( 'ABSPATH' ) || exit;

/** Does not use wp_mail or persist request/response secrets. */
final class WebhookChannel {

	/** Returns only configuration readiness, never the URL or token. */
	public function configured(): bool {
		$url = defined( 'SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL' ) ? SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL : '';
		if ( ! is_string( $url ) || strlen( $url ) > 2048 ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$token = defined( 'SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN' ) ? SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN : '';
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] ) && is_string( $token ) && strlen( $token ) <= 4096 && ! preg_match( '/[\r\n]/', $token );
	}

	/**
	 * Sends an allowlisted payload with bounded safe HTTP and no redirects.
	 *
	 * @param array $job Normalized job.
	 * @return int HTTP status only; zero for unavailable/transport error.
	 */
	public function send( array $job ): int {
		if ( ! $this->configured() || ! in_array( $job['alert_type'] ?? '', IncidentRules::TYPES, true ) || ! in_array( $job['event'] ?? '', array( 'opened', 'recovered' ), true ) ) {
			return 0;
		}
		foreach ( array( 'notification_uuid', 'alert_uuid' ) as $key ) {
			if ( ! is_string( $job[ $key ] ?? null ) || ! preg_match( '/^[0-9a-f-]{36}$/D', $job[ $key ] ) ) {
				return 0;
			}
		}
		try {
			$headers = array(
				'Content-Type'    => 'application/json',
				'Idempotency-Key' => $job['notification_uuid'],
			);
			if ( defined( 'SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN' ) && '' !== SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN ) {
				$headers['Authorization'] = 'Bearer ' . SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN;
			}
			$response = wp_safe_remote_post(
				SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL,
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 1024,
					'headers'             => $headers,
					'body'                => wp_json_encode(
						array(
							'version'           => 1,
							'notification_uuid' => $job['notification_uuid'],
							'incident_uuid'     => $job['alert_uuid'],
							'type'              => $job['alert_type'],
							'event'             => $job['event'],
							'message'           => IncidentRules::description( $job['alert_type'], 'recovered' === $job['event'] ),
						)
					),
				)
			);
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			return $code >= 100 && $code <= 599 ? $code : 0;
		} catch ( \Throwable $error ) {
			return 0;
		}
	}
}
