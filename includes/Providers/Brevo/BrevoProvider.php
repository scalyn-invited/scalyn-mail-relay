<?php
/**
 * Brevo transactional HTTPS API adapter.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Brevo;

use Scalyn\MailRelay\Providers\JsonApiProvider;
use Scalyn\MailRelay\Providers\ConnectionResult;

defined( 'ABSPATH' ) || exit;

/** Single-message API transport; no inferred delivery or automatic retries. */
class BrevoProvider extends JsonApiProvider {
	/** Provider identity. */
	public function get_id(): string {
		return 'brevo'; }
	/** Public label. */
	public function get_label(): string {
		return 'Brevo API'; }
	/** Fixed send route. */
	protected function send_path(): string {
		return '/smtp/email'; }
	/** Authentication header. */
	protected function auth_header(): string {
		return 'api-key'; }

	/**
	 * Checks API account access without sending mail.
	 *
	 * @param array $config Private settings.
	 * @return ConnectionResult Safe observation.
	 */
	public function test_connection( array $config ): ConnectionResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new ConnectionResult( false, 'Brevo configuration is incomplete or invalid.' );
		}
		$response = $this->request( '/account', $config['api_key'] );
		$data     = $response['data'];
		$valid    = 200 === $response['code'] && ! isset( $data['code'] ) && is_string( $data['email'] ?? null ) && false !== filter_var( $data['email'], FILTER_VALIDATE_EMAIL ) && is_array( $data['plan'] ?? null );
		return new ConnectionResult( $valid, $valid ? 'Brevo authenticated API account access. No email was sent; transactional activation, sender authorization, allowance and delivery remain unverified.' : 'Brevo verification failed. Check the API key (not an SMTP key), account access and authorized IP restrictions. No email was sent.' );
	}

	/**
	 * Maps locally validated content.
	 *
	 * @param array $message Validated content.
	 * @return array API payload.
	 */
	protected function payload( array $message ): array {
		$payload = array(
			'sender'  => $message['sender'],
			'to'      => $message['to'],
			'subject' => $message['subject'],
			'text/html' === $message['content_type'] ? 'htmlContent' : 'textContent' => $message['body'],
		);
		foreach ( array( 'cc', 'bcc' ) as $role ) {
			if ( $message[ $role ] ) {
				$payload[ $role ] = $message[ $role ];
			}
		}
		if ( null !== $message['reply_to'] ) {
			$payload['replyTo'] = $message['reply_to'];
		}
		if ( $message['headers'] ) {
			$payload['headers'] = $message['headers'];
		}
		foreach ( $message['attachments'] as $file ) {
			$payload['attachment'][] = array(
				'name'    => $file['Name'],
				'content' => $file['Content'],
			);
		}
		return $payload;
	}

	/**
	 * Requires a well-formed acknowledgement identifier.
	 *
	 * @param array $response Private response.
	 * @param int   $count Submitted recipients, not a delivery count.
	 * @return string|null Safe provider identifier.
	 */
	protected function accepted_id( array $response, int $count ): ?string {
		$id = $response['data']['messageId'] ?? null;
		return 201 === $response['code'] && ! isset( $response['data']['code'] ) && is_string( $id ) && strlen( $id ) <= 255 && preg_match( '/^<[A-Za-z0-9._-]+@[A-Za-z0-9.-]+>$/D', $id ) ? $id : null;
	}

	/**
	 * Fixed HTTPS boundary.
	 *
	 * @param string $path Internal route.
	 * @param array  $args HTTP options.
	 * @return mixed WordPress response.
	 */
	protected function http( string $path, array $args ): mixed {
		return wp_safe_remote_request( 'https://api.brevo.com/v3' . $path, $args );
	}
}
