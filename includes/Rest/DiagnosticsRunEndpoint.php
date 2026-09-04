<?php
/**
 * REST endpoint for running diagnostics.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Rest;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;
use Scalyn\MailRelay\Diagnostics\HealthScorer;
use Scalyn\MailRelay\Logging\MailLogRepository;
use WP_REST_Request;
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
 * Ownership: Kim / REST.
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
		$container = Plugin::instance()->container();

		try {
			$runner        = $container->get( DiagnosticRunner::class );
			$registry      = $container->get( DiagnosticCheckRegistry::class );
			$repo          = $container->get( DiagnosticRepository::class );
			$mail_log_repo = $container->get( MailLogRepository::class );
			$scorer        = $container->get( HealthScorer::class );
			$score_repo    = $container->get( HealthScoreRepository::class );

			// Build the context through the credential-safe builder: it exposes only
			// host/port/encryption to checks (never username/password) and targets
			// the sending domain from the From address, falling back to the site host.
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$context   = $container->get( DiagnosticContextBuilder::class )->build(
				$container->get( SettingsRepository::class ),
				is_string( $site_host ) && '' !== $site_host ? $site_host : 'localhost'
			);

			// Execute all registered diagnostic checks and collect results.
			$checks        = $registry->get_all();
			$check_results = $runner->run( array_values( $checks ), $context );

			// Generate a UUID for this diagnostic run.
			$run_uuid = wp_generate_uuid4();

			// Persist each check result to the database.
			foreach ( $check_results as $check_result ) {
				$repo->persist_result(
					$run_uuid,
					$check_result['category'],
					$check_result['id'],
					$check_result['result']
				);
			}

			// Compute health score from diagnostic results and recent mail history.
			$run_data            = $repo->find_latest_run();
			$mail_status_counts  = $mail_log_repo->count_recent_by_status();
			$health_score_result = $scorer->score( $run_data['results'], $mail_status_counts );

			// Persist health score if computed.
			if ( null !== $health_score_result ) {
				$score_repo->persist( $health_score_result );
			}

			return new WP_REST_Response(
				array(
					'success'      => true,
					'results'      => $run_data['results'],
					'health_score' => $health_score_result ? $health_score_result->overall_score : null,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'An error occurred while running diagnostics.', 'scalyn-mail-relay' ),
				),
				500
			);
		}
	}
}
