<?php
/** Nonce-protected administrator chat and confirmation actions.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Rest;

use Scalyn\MailRelay\AI\AiAccess;
use Scalyn\MailRelay\AI\AiRepository;
use Scalyn\MailRelay\AI\AiService;
use Scalyn\MailRelay\AI\AiEvidence;
use Scalyn\MailRelay\AI\AiRedaction;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunService;

defined( 'ABSPATH' ) || exit;

/** No AI-generated operation names are dispatched. */
final class AiEndpoint {

	/** Creates the endpoint.
	 *
	 * @param AiRepository         $repository Private state.
	 * @param AiService            $service Chat orchestration.
	 * @param AiEvidence           $evidence Current summaries.
	 * @param SettingsRepository   $settings Revision guard.
	 * @param DiagnosticRunService $diagnostics Explicit confirmed operation.
	 */
	public function __construct( private AiRepository $repository, private AiService $service, private AiEvidence $evidence, private SettingsRepository $settings, private DiagnosticRunService $diagnostics ) {}

	/** Registers a single fixed-action REST endpoint. */
	public function register(): void {
		register_rest_route(
			SCALYN_MAIL_RELAY_REST_NAMESPACE,
			'/ai',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'handle' ),
			)
		);
	}

	/** Requires a native administrator and fresh WordPress REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function permission( $request ): bool {
		return AiAccess::allowed() && (bool) wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	/** Executes only enumerated, validated actions.
	 *
	 * @param \WP_REST_Request $request Protected request.
	 * @throws \RuntimeException Internally translated into safe REST errors.
	 * @throws \InvalidArgumentException Internally translated into safe REST errors.
	 */
	public function handle( $request ): \WP_REST_Response {
		try {
			if ( ! $this->permission( $request ) ) {
				throw new \RuntimeException( 'Administrator access and a fresh nonce are required.', 403 );
			}
			if ( strlen( $request->get_body() ) > 20000 ) {
				throw new \InvalidArgumentException( 'AI request is too large.' );
			}
			$input = $request->get_json_params();
			if ( ! is_array( $input ) || ! is_string( $input['action'] ?? null ) || ( isset( $input['uuid'] ) && ! is_string( $input['uuid'] ) ) ) {
				throw new \InvalidArgumentException( 'Invalid AI request.' );
			}
			$uuid = $input['uuid'] ?? '';
			switch ( $input['action'] ) {
				case 'list':
					$result = array(
						'sessions' => $this->repository->listing(),
						'usage'    => $this->repository->usage(),
					);
					break;
				case 'create':
					$result = array( 'uuid' => $this->repository->create() );
					break;
				case 'read':
					$result = $this->repository->read( $uuid );
					break;
				case 'delete':
					$this->repository->delete( $uuid );
					$result = array( 'deleted' => true );
					break;
				case 'rename':
					$title = AiRedaction::input( is_string( $input['title'] ?? null ) ? $input['title'] : '' );
					if ( mb_strlen( $title, 'UTF-8' ) > 80 ) {
						throw new \InvalidArgumentException( 'Use up to 80 characters for a title.' );
					}
					$claim = $this->repository->claim( $uuid );
					try {
						$session          = $this->repository->read( $uuid );
						$session['title'] = $title;
						$this->repository->save( $uuid, $claim, $session, false );
					} finally {
						$this->repository->release( $uuid, $claim );
					}
					$result = array( 'renamed' => true );
					break;
				case 'send':
					$result = $this->service->send( $uuid, is_string( $input['text'] ?? null ) ? $input['text'] : '' );
					break;
				case 'evidence':
					$result = $this->evidence->snapshot();
					break;
				case 'diagnostics':
					if ( true !== ( $input['confirmed'] ?? null ) || ! is_string( $input['revision'] ?? null ) || ! hash_equals( $this->settings->get_diagnostic_revision(), $input['revision'] ) || ! current_user_can( \Scalyn\MailRelay\Core\Capabilities::RUN_DIAGNOSTICS ) ) {
						throw new \RuntimeException( 'Configuration changed or confirmation is missing. Refresh evidence and confirm again.', 409 );
					}
					$this->repository->consume( 'diagnostics-minute:' . get_current_user_id() . ':' . gmdate( 'Y-m-d-H-i' ), 1, time() + 120 );
					\Scalyn\MailRelay\AI\AiAudit::record( 'ai_diagnostics', 'confirmed' );
					$run    = $this->diagnostics->run();
					$result = array(
						'completed' => ! empty( $run['success'] ),
						'evidence'  => $this->evidence->snapshot(),
						'message'   => 'Diagnostic operation finished. Review the recorded results; no test email was sent.',
					);
					break;
				default:
					throw new \InvalidArgumentException( 'Unsupported AI operation.' );
			}
			$response = new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
				),
				200
			);
		} catch ( \Throwable $error ) {
			$status = in_array( $error->getCode(), array( 403, 404, 409, 429 ), true ) ? $error->getCode() : 400;
			// Never expose SQL, transport bodies or unexpected exception messages.
			$response = new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'AI operation unavailable. Check settings, limits, conversation ownership or current configuration; refresh before trying again.',
				),
				$status
			);
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
