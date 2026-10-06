<?php
/**
 * Secure request and outcome boundary for JSON mail adapters.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers;

use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;

defined( 'ABSPATH' ) || exit;

/** Shared bounds, not automatic retries or inferred delivery. */
abstract class JsonApiProvider implements ProviderInterface {

	/** Supported message content only. */
	public function get_capabilities(): array {
		return array( 'html', 'attachments' );
	}

	/**
	 * Validates without network access.
	 *
	 * @param array $config Transport-only private settings.
	 * @return ValidationResult Safe field errors.
	 */
	public function validate_config( array $config ): ValidationResult {
		$errors = array();
		$key    = $config['api_key'] ?? null;
		$email  = $config['from_email'] ?? null;
		$name   = $config['from_name'] ?? '';
		if ( ! is_string( $key ) || strlen( $key ) < 16 || strlen( $key ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $key ) ) {
			$errors['api_key'] = 'A valid provider API key is required.';
		}
		if ( ! is_string( $email ) || strlen( $email ) > 254 || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['from_email'] = 'A valid authorized sender address is required.';
		}
		if ( ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name ) ) {
			$errors['from_name'] = 'The sender name is invalid.';
		}
		return new ValidationResult( array() === $errors, $errors );
	}

	/**
	 * Validates and submits once. Never automatically retries.
	 *
	 * @param MailMessage $message Prepared message.
	 * @param array       $config Private transport configuration.
	 * @return SendResult Safe outcome.
	 * @throws \InvalidArgumentException Internally caught preparation failures.
	 */
	final public function send( MailMessage $message, array $config ): SendResult {
		if ( ! $this->validate_config( $config )->valid ) {
			return new SendResult( false, $this->get_id(), null, null, 'Provider configuration is incomplete or invalid.', false, TransportFailureCategory::CONFIG );
		}
		try {
			$prepared = ( new BoundedApiMessage() )->prepare( $message, $config );
			$payload  = $this->payload( $prepared );
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Reject invalid UTF-8 instead of altering mail content.
			$json = json_encode( $payload, JSON_THROW_ON_ERROR );
			if ( strlen( $json ) > 8388608 ) {
				throw new \InvalidArgumentException( 'The message exceeds the API adapter size limit.' );
			}
		} catch ( \InvalidArgumentException $error ) {
			return new SendResult( false, $this->get_id(), null, null, $error->getMessage(), false, TransportFailureCategory::CONFIG );
		} catch ( \Throwable $error ) {
			return new SendResult( false, $this->get_id(), null, null, 'Message preparation failed before sending.', false, TransportFailureCategory::CONFIG );
		}
		$response = $this->request( $this->send_path(), $config['api_key'], $json );
		$count    = count( $prepared['to'] ) + count( $prepared['cc'] ) + count( $prepared['bcc'] );
		$id       = $this->accepted_id( $response, $count );
		$code     = $response['code'];
		if ( null !== $id ) {
			return new SendResult( true, $this->get_id(), $id, (string) $code, 'Message accepted by the provider; delivery is unconfirmed.' );
		}
		$rejected = $code >= 400 && $code < 500 && 408 !== $code;
		$text     = $rejected ? 'Provider rejected the request. Check API permissions, authorized sender, account restrictions and allowance. No automatic retry was attempted.' : 'Provider acceptance is unconfirmed or partial. Inspect provider activity before retrying to avoid duplicates. No automatic retry was attempted.';
		return new SendResult( false, $this->get_id(), null, $code ? (string) $code : null, $text, false, $rejected ? TransportFailureCategory::PROVIDER_REJECTION : TransportFailureCategory::UNKNOWN, array(), ! $rejected );
	}

	/**
	 * Makes a bounded request; raw data stays inside the transport adapter.
	 *
	 * @param string      $path Fixed internal route.
	 * @param string      $key Private API key.
	 * @param string|null $json Encoded body or null for GET.
	 * @return array Private HTTP data for provider-specific parsing only.
	 */
	final protected function request( string $path, #[\SensitiveParameter] string $key, ?string $json = null ): array {
		try {
			$args = array(
				'method'              => null === $json ? 'GET' : 'POST',
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 16384,
				'headers'             => array(
					$this->auth_header() => $key,
					'Accept'             => 'application/json',
					'Content-Type'       => 'application/json',
				),
			);
			if ( null !== $json ) {
				$args['body'] = $json;
			}
			$response = $this->http( $path, $args );
			$code     = is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
			$body     = is_array( $response ) ? ( $response['body'] ?? '' ) : '';
			$data     = is_string( $body ) && strlen( $body ) <= 16384 ? json_decode( $body, true, 8 ) : null;
			return array(
				'code' => is_numeric( $code ) && $code >= 100 && $code <= 599 ? (int) $code : 0,
				'data' => is_array( $data ) ? $data : array(),
			);
		} catch ( \Throwable $error ) {
			return array(
				'code' => 0,
				'data' => array(),
			);
		}
	}

	/**
	 * Provider payload mapping.
	 *
	 * @param array $message Validated content.
	 * @return array Provider payload.
	 */
	abstract protected function payload( array $message ): array;

	/** Fixed send route. */
	abstract protected function send_path(): string;

	/** Fixed authentication header name. */
	abstract protected function auth_header(): string;

	/**
	 * Parses complete provider acknowledgement only.
	 *
	 * @param array $response Private HTTP response.
	 * @param int   $count Submitted recipient count.
	 * @return string|null Safe provider identifier.
	 */
	abstract protected function accepted_id( array $response, int $count ): ?string;

	/**
	 * Fixed HTTPS boundary, replaceable only for offline tests.
	 *
	 * @param string $path Internal route.
	 * @param array  $args HTTP options.
	 * @return mixed HTTP response.
	 */
	abstract protected function http( string $path, array $args ): mixed;
}
