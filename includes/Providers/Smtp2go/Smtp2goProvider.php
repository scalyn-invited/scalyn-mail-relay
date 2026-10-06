<?php
/**
 * SMTP2GO HTTPS API transport.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Smtp2go;

use Scalyn\MailRelay\Providers\JsonApiProvider;
use Scalyn\MailRelay\Providers\ConnectionResult;

defined( 'ABSPATH' ) || exit;

/** Single-attempt sending with conservative partial-acceptance handling. */
class Smtp2goProvider extends JsonApiProvider {
	/** Provider identity. */
	public function get_id(): string {
		return 'smtp2go'; }
	/** Public label. */
	public function get_label(): string {
		return 'SMTP2GO API'; }
	/** Fixed send route. */
	protected function send_path(): string {
		return '/email/send'; }
	/** API authentication header. */
	protected function auth_header(): string {
		return 'X-Smtp2go-Api-Key'; }

	/**
	 * Verifies read access without submitting mail.
	 *
	 * @param array $config Private settings.
	 * @return ConnectionResult Credential-free observation.
	 */
	public function test_connection( array $config ): ConnectionResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new ConnectionResult( false, 'SMTP2GO configuration is incomplete or invalid.' );
		}
		$response = $this->request( '/stats/email_cycle', $config['api_key'], '{}' );
		$data     = $response['data']['data'] ?? array();
		$valid    = 200 === $response['code'] && is_array( $data ) && ! isset( $data['error_code'] ) && is_int( $data['cycle_used'] ?? null ) && $data['cycle_used'] >= 0;
		return new ConnectionResult( $valid, $valid ? 'SMTP2GO authenticated API read access. No email was sent; sending permission, sender authorization and delivery remain unverified.' : 'SMTP2GO verification failed. Check the API key, account access and /stats/email_cycle permission. No email was sent.' );
	}

	/**
	 * Maps validated content to the sending API.
	 *
	 * @param array $message Validated content.
	 * @return array Provider payload.
	 */
	protected function payload( array $message ): array {
		$payload = array(
			'sender'     => $this->mailbox( $message['sender'] ),
			'subject'    => $message['subject'],
			'fastaccept' => false,
			'text/html' === $message['content_type'] ? 'html_body' : 'text_body' => $message['body'],
		);
		foreach ( array( 'to', 'cc', 'bcc' ) as $role ) {
			if ( $message[ $role ] ) {
				$payload[ $role ] = array_map( array( $this, 'mailbox' ), $message[ $role ] );
			}
		}
		if ( null !== $message['reply_to'] ) {
			$message['headers']['Reply-To'] = $this->mailbox( $message['reply_to'] );
		}
		foreach ( $message['headers'] as $name => $value ) {
			$payload['custom_headers'][] = array(
				'header' => $name,
				'value'  => $value,
			);
		}
		foreach ( $message['attachments'] as $file ) {
			$payload['attachments'][] = array(
				'filename' => $file['Name'],
				'fileblob' => $file['Content'],
				'mimetype' => $file['ContentType'],
			);
		}
		return $payload;
	}

	/**
	 * Formats a previously validated mailbox.
	 *
	 * @param array $address Validated mailbox.
	 * @return string Provider mailbox.
	 */
	private function mailbox( array $address ): string {
		return isset( $address['name'] ) ? '"' . addcslashes( $address['name'], '\\"' ) . '" <' . $address['email'] . '>' : $address['email'];
	}

	/**
	 * Requires all recipients acknowledged; partial acceptance is uncertain.
	 *
	 * @param array $response Private response.
	 * @param int   $count Submitted count.
	 * @return string|null Safe identifier.
	 */
	protected function accepted_id( array $response, int $count ): ?string {
		$data = $response['data']['data'] ?? $response['data']['email_response'] ?? array();
		$id   = is_array( $data ) ? ( $data['email_id'] ?? null ) : null;
		return 200 === $response['code'] && ! isset( $data['error_code'] ) && ( $data['succeeded'] ?? null ) === $count && 0 === ( $data['failed'] ?? null ) && empty( $data['failures'] ) && is_string( $id ) && preg_match( '/^[A-Za-z0-9-]{5,100}$/D', $id ) ? $id : null;
	}

	/**
	 * Fixed HTTPS boundary.
	 *
	 * @param string $path Internal route.
	 * @param array  $args HTTP options.
	 * @return mixed WordPress response.
	 */
	protected function http( string $path, array $args ): mixed {
		return wp_safe_remote_request( 'https://api.smtp2go.com/v3' . $path, $args );
	}
}
