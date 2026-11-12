<?php
/**
 * REST endpoint for running diagnostics.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Rest;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Handles POST requests to trigger diagnostic runs and fetch results.
 *
 * Endpoint: POST /wp-json/scalyn-mail-relay/v1/diagnostics/run
 * Requires: RUN_DIAGNOSTICS capability
 *
 * Response: {
 *   'success': bool,
 *   'results': [ { 'check_name', 'status', 'result_message', 'recommended_action', 'raw_result' } ],
 *   'health_score': int|null,
 *   'message': string (on error)
 * }
 *
 * Ownership: Bernie.
 */
final class DiagnosticsRunEndpoint {

	/**
	 * Registers the REST endpoint with WordPress.
	 */
	public function register(): void {
		register_rest_route(
			SCALYN_MAIL_RELAY_REST_NAMESPACE,
			'/diagnostics/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Checks whether the requesting user has permission to run diagnostics.
	 *
	 * @return bool True if the user can run diagnostics, false otherwise.
	 */
	public function check_permission(): bool {
		return current_user_can( Capabilities::RUN_DIAGNOSTICS );
	}

	/**
	 * Handles POST requests to run diagnostics.
	 *
	 * Executes registered diagnostic checks, persists results to the database,
	 * and returns the latest run data with health score.
	 *
	 * @return WP_REST_Response The response containing diagnostic results or error.
	 */
	public function handle_request(): WP_REST_Response {
		$result = Plugin::instance()->container()->get( \Scalyn\MailRelay\Diagnostics\DiagnosticRunService::class )->run();
		$status = $result['status'];
		unset( $result['status'] );
		return new WP_REST_Response( $result, $status );
	}
}
