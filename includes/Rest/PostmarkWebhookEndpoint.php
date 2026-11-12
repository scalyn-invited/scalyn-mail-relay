<?php
/**
 * Server-to-server Postmark delivery/bounce receiver.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Rest;

use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;
use Scalyn\MailRelay\Database\DeliveryEventRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Database\WebhookRateLimitRepository;
use Scalyn\MailRelay\Providers\Postmark\WebhookIngress;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-0022's documented exception to the admin REST permission rule: this route
 * is authenticated by dedicated webhook credentials, TLS and an exact-IP allowlist,
 * not by a WordPress user. It acknowledges an event only after durable commit,
 * returns no payload, and never serializes internal evidence to the caller.
 */
final class PostmarkWebhookEndpoint {

	/** Route prefix below the plugin namespace. */
	public const ROUTE = '/webhooks/postmark/';

	/**
	 * Creates the receiver from approved services and repositories.
	 *
	 * @param PostmarkWebhookSettings    $sources Source lifecycle.
	 * @param SettingsRepository         $settings Retention setting.
	 * @param WebhookRateLimitRepository $budget Per-source request budget.
	 * @param DeliveryAttemptRepository  $attempts Correlation.
	 * @param DeliveryKeyRepository      $keys Recipient matching.
	 * @param DeliveryEventRepository    $events Atomic evidence storage.
	 */
	public function __construct(
		private readonly PostmarkWebhookSettings $sources,
		private readonly SettingsRepository $settings,
		private readonly WebhookRateLimitRepository $budget,
		private readonly DeliveryAttemptRepository $attempts,
		private readonly DeliveryKeyRepository $keys,
		private readonly DeliveryEventRepository $events
	) {}

	/** Registers the route only while a source exists. */
	public function register(): void {
		if ( ! $this->sources->has_source() ) {
			return;
		}
		register_rest_route(
			SCALYN_MAIL_RELAY_REST_NAMESPACE,
			self::ROUTE . '(?P<source>[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Authenticated in handle() by webhook credentials, never by a WordPress user.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Core treats any REST Basic header as an Application Password login and rejects
	 * the request before routing. Webhook credentials are not WordPress users.
	 *
	 * @param bool $is_api_request Core decision.
	 * @return bool False only for this receiver's path.
	 */
	public static function exclude_application_passwords( bool $is_api_request ): bool {
		return $is_api_request && ! self::is_receiver_request();
	}

	/**
	 * Handles one callback. Response bodies are empty; status codes follow ADR-0022.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response Fixed status without payload.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$source = $this->sources->ingress_source( (string) $request->get_param( 'source' ) );
		} catch ( \Throwable $error ) {
			return $this->respond( 503 );
		}
		if ( null === $source ) {
			return $this->respond( 401 );
		}
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			// WordPress canonicalizes names to lowercase with underscores.
			$headers[ str_replace( '_', '-', (string) $name ) ] = is_array( $values ) ? array_values( $values ) : array( $values );
		}
		$cutoff = current_datetime()->modify( '-' . $this->settings->get_log_retention_days() . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$id     = strtolower( $source['id'] );
		$result = ( new WebhookIngress() )->inspect(
			array(
				'method'  => $request->get_method(),
				'headers' => $headers,
				'body'    => $request->get_body(),
				'https'   => is_ssl(),
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Trusted server peer address, validated by the authenticator; forwarded headers are ignored.
				'peer_ip' => isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '',
			),
			$source,
			fn( string $source_id ): bool => $this->budget->consume( $source_id ),
			fn( string $message_id, ?string $hint, string $address ): ?array => $this->attempts->resolve( $id, $message_id, $hint, $address, $cutoff, $this->keys ),
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
		);
		unset( $source );
		if ( 'rejected' === $result['disposition'] ) {
			return $this->respond( (int) $result['http_status'] );
		}
		if ( 'ready' !== $result['disposition'] ) {
			// Authenticated but disabled, unsupported or uncorrelated: fixed acknowledgement, nothing stored.
			return $this->respond( 200 );
		}
		// Source coordination: a disable committed after authentication stops this write.
		if ( ! $this->sources->is_enabled( $id ) ) {
			return $this->respond( 200 );
		}
		try {
			$this->events->append( $result['event'], $cutoff );
		} catch ( \Throwable $error ) {
			// Temporary or conflicting persistence failure: Postmark retries on 5xx, never 403.
			return $this->respond( 503 );
		}
		return $this->respond( 200 );
	}

	/**
	 * Builds an empty response with no caching.
	 *
	 * @param int $status HTTP status.
	 */
	private function respond( int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Detects this receiver's REST path before routing, for authentication filters. */
	private static function is_receiver_request(): bool {
		$prefix = '/' . SCALYN_MAIL_RELAY_REST_NAMESPACE . self::ROUTE;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Path comparison only.
		$route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : '';
		if ( '' === $route ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Path comparison only.
			$uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$base  = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
			$route = str_starts_with( $path, rtrim( $base, '/' ) ) ? substr( $path, strlen( rtrim( $base, '/' ) ) ) : '';
		}
		return str_starts_with( $route, $prefix );
	}
}
