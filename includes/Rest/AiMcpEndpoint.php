<?php
/** Authenticated read-only MCP Streamable HTTP transport.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Rest;

use Scalyn\MailRelay\AI\AiAccess;
use Scalyn\MailRelay\AI\AiSettings;
use Scalyn\MailRelay\AI\AiEvidence;
use Scalyn\MailRelay\AI\AiRepository;

defined( 'ABSPATH' ) || exit;

/** No chat-history or execution tools; no OAuth compatibility claim. */
final class AiMcpEndpoint {
	/** Core-authenticated Application Password principal for this request.
	 *
	 * @var int
	 */
	private int $application_user = 0;
	private const VERSIONS        = array( '2025-06-18', '2025-03-26' );
	private const TOOLS           = array(
		'plugin_help'           => 'help',
		'configuration_summary' => 'configuration',
		'recorded_diagnostics'  => 'diagnostics',
		'health_explanation'    => 'health',
		'aggregate_activity'    => 'activity',
	);

	/** Creates the transport.
	 *
	 * @param AiSettings   $settings Separate opt-in.
	 * @param AiEvidence   $evidence Shared evidence boundary.
	 * @param AiRepository $repository Atomic budgets.
	 */
	public function __construct( private AiSettings $settings, private AiEvidence $evidence, private AiRepository $repository ) {}

	/** Records successful core Application Password authentication.
	 *
	 * @param \WP_User $user Principal.
	 */
	public function authenticated( $user ): void {
		$this->application_user = (int) $user->ID;
	}

	/** POST-only stateless endpoint. */
	public function route(): void {
		register_rest_route(
			SCALYN_MAIL_RELAY_REST_NAMESPACE,
			'/ai/mcp',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'handle' ),
			)
		);
	}

	/** Rechecks identity, HTTPS, Origin and independent opt-in.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function permission( $request ): bool {
		if ( ! is_ssl() || ! AiAccess::allowed() || 1 > $this->application_user || get_current_user_id() !== $this->application_user ) {
			return false;
		}
		$origin   = $request->get_header( 'origin' );
		$site     = wp_parse_url( home_url() );
		$expected = ( $site['scheme'] ?? 'https' ) . '://' . ( $site['host'] ?? '' ) . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );
		return ( ! $origin || hash_equals( $expected, $origin ) ) && $this->settings->summary()['mcp'];
	}

	/** Serves JSON-RPC with fixed bounded tools.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws \RuntimeException Internally translated into safe protocol errors.
	 */
	public function handle( $request ): \WP_REST_Response {
		$id = null;
		try {
			if ( ! $this->permission( $request ) ) {
				throw new \RuntimeException( 'Forbidden', 403 );
			}
			if ( strlen( $request->get_body() ) > 8192 || ! str_contains( (string) $request->get_header( 'content-type' ), 'application/json' ) || ! str_contains( (string) $request->get_header( 'accept' ), 'application/json' ) ) {
				throw new \RuntimeException( 'Invalid transport', 400 );
			}
			$body = $request->get_json_params();
			if ( ! is_array( $body ) || '2.0' !== ( $body['jsonrpc'] ?? '' ) || ! is_string( $body['method'] ?? null ) || ( isset( $body['id'] ) && ! is_int( $body['id'] ) && ! is_string( $body['id'] ) ) || ( isset( $body['params'] ) && ! is_array( $body['params'] ) ) ) {
				throw new \RuntimeException( 'Invalid request', 400 );
			}
			$id = $body['id'] ?? null;
			if ( 'notifications/initialized' !== $body['method'] && null === $id ) {
				throw new \RuntimeException( 'Request identifier is required.', 400 );
			}
			if ( is_string( $id ) && strlen( $id ) > 64 ) {
				$id = null;
				throw new \RuntimeException( 'Invalid identifier', 400 );
			}
			$version = $request->get_header( 'mcp-protocol-version' );
			if ( $version && ! in_array( $version, self::VERSIONS, true ) ) {
				throw new \RuntimeException( 'Unsupported version', 400 );
			}
			$this->repository->consume( 'mcp-minute:' . get_current_user_id() . ':' . gmdate( 'Y-m-d-H-i' ), 60, time() + 120 );
			$params = $body['params'] ?? array();
			switch ( $body['method'] ) {
				case 'initialize':
					$requested = $params['protocolVersion'] ?? '';
					$result    = array(
						'protocolVersion' => in_array( $requested, self::VERSIONS, true ) ? $requested : self::VERSIONS[0],
						'capabilities'    => array( 'tools' => new \stdClass() ),
						'serverInfo'      => array(
							'name'    => 'scalyn-mail-relay',
							'version' => SCALYN_MAIL_RELAY_VERSION,
						),
						'instructions'    => 'Read-only plugin evidence. No histories, secrets, actions or inbox-delivery guarantees. External conversations are not restricted by this server.',
					);
					break;
				case 'notifications/initialized':
					if ( null !== $id ) {
						throw new \RuntimeException( 'Invalid notification', 400 );
					}
					return new \WP_REST_Response( null, 202 );
				case 'ping':
					$result = new \stdClass();
					break;
				case 'tools/list':
					$result = array( 'tools' => array() );
					foreach ( self::TOOLS as $name => $source ) {
						$result['tools'][] = array(
							'name'        => $name,
							'description' => 'Read redacted plugin ' . $source . ' evidence. Unknown is not a pass.',
							'inputSchema' => array(
								'type'                 => 'object',
								'properties'           => new \stdClass(),
								'additionalProperties' => false,
							),
							'annotations' => array(
								'readOnlyHint'    => true,
								'destructiveHint' => false,
								'openWorldHint'   => false,
							),
						);
					}
					break;
				case 'tools/call':
					$name = $params['name'] ?? '';
					if ( ! is_string( $name ) || ! isset( self::TOOLS[ $name ] ) || ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) || ! empty( $params['arguments'] ) ) {
						throw new \RuntimeException( 'Unsupported tool', 400 );
					}
					$snapshot = $this->evidence->snapshot();
					$data     = array(
						'captured_at_utc' => $snapshot['captured_at_utc'],
						'revision'        => $snapshot['revision'],
						'source'          => self::TOOLS[ $name ],
						'evidence'        => $snapshot['sources'][ self::TOOLS[ $name ] ],
					);
					$result   = array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $data ),
							),
						),
						'isError' => false,
					);
					\Scalyn\MailRelay\AI\AiAudit::record( 'ai_mcp', 'read' );
					break;
				default:
					return new \WP_REST_Response(
						array(
							'jsonrpc' => '2.0',
							'id'      => $id,
							'error'   => array(
								'code'    => -32601,
								'message' => 'Method not supported.',
							),
						),
						200
					);
			}
			$response = new \WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => $result,
				),
				200
			);
		} catch ( \Throwable $error ) {
			$status   = in_array( $error->getCode(), array( 403, 429 ), true ) ? $error->getCode() : 400;
			$response = new \WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Request unavailable. Check authentication, opt-in, protocol and limits.',
					),
				),
				$status
			);
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Keeps accepted notifications bodyless.
	 *
	 * @param bool   $served Already served.
	 * @param object $result Response.
	 * @param object $request Request.
	 * @param object $server REST server.
	 */
	public function serve_notification( $served, $result, $request, $server ): bool {
		unset( $server );
		return $served || ( '/' . SCALYN_MAIL_RELAY_REST_NAMESPACE . '/ai/mcp' === $request->get_route() && 202 === $result->get_status() );
	}
}
