<?php
/** Fixed-origin Gemini and Claude adapters with the shared answer contract.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Contracts\AiAdapterInterface;
defined( 'ABSPATH' ) || exit;
/** No tool calling, arbitrary endpoints or server-side conversation storage. */
final class VendorAiAdapter implements AiAdapterInterface {
	/** Binds one official provider.
	 *
	 * @param AiSettings $settings Encrypted credentials.
	 * @param string     $provider Fixed provider ID.
	 * @throws \InvalidArgumentException For unsupported vendors.
	 */
	public function __construct( private AiSettings $settings, private string $provider ) {
		if ( ! in_array( $provider, array( 'gemini', 'claude' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported AI adapter.' );}}
	/** Fetches a bounded account-visible starter list, without following arbitrary pagination links. */
	public function models(): array {
		$data = $this->request( 'gemini' === $this->provider ? 'models?pageSize=1000' : 'models?limit=1000', null );
		$ids  = array();
		foreach ( array_slice( $data[ 'gemini' === $this->provider ? 'models' : 'data' ] ?? array(), 0, 1000 )as$row ) {
			if ( ! is_array( $row ) ) {
				continue;}
			$id = 'gemini' === $this->provider ? preg_replace( '/^models\//', '', (string) ( $row['name'] ?? '' ) ) : ( $row['id'] ?? '' );
			if ( ! is_string( $id ) || ! isset( AiCatalog::MODELS[ $this->provider ][ $id ] ) ) {
				continue;}
			if ( 'gemini' === $this->provider && ! in_array( 'generateContent', $row['supportedGenerationMethods'] ?? array(), true ) ) {
				continue;}
			if ( 'claude' === $this->provider && isset( $row['lifecycle'] ) && 'active' !== $row['lifecycle'] ) {
				continue;}
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}
	/** Makes one structured advisory request.
	 *
	 * @param string $model Exact supported model.
	 * @param array  $history Bounded redacted history.
	 * @param array  $evidence Current allowed evidence.
	 * @throws \InvalidArgumentException For a mismatched model.
	 * @throws \RuntimeException For unavailable or malformed answers.
	 * @throws AiFailure For incomplete answers.
	 */
	public function answer( string $model, array $history, array $evidence ): array {
		if ( AiCatalog::provider( $model ) !== $this->provider ) {
			throw new \InvalidArgumentException( 'Model belongs to another AI provider.' );}
		$system = AiAnswer::INSTRUCTIONS . ' Current redacted plugin evidence: ' . wp_json_encode( $evidence );
		if ( 'gemini' === $this->provider ) {
			$contents = array();
			foreach ( $history as$message ) {
				$contents[] = array(
					'role'  => 'assistant' === $message['role'] ? 'model' : 'user',
					'parts' => array( array( 'text' => $message['content'] ) ),
				);}
			$data      = $this->request(
				'models/' . $model . ':generateContent',
				array(
					'store'             => false,
					'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
					'contents'          => $contents,
					'generationConfig'  => array(
						'maxOutputTokens' => 1200,
						'candidateCount'  => 1,
						'thinkingConfig'  => array(
							'thinkingLevel'   => 'MINIMAL',
							'includeThoughts' => false,
						),
						'responseFormat'  => array(
							'text' => array(
								'mimeType' => 'application/json',
								'schema'   => AiAnswer::schema(),
							),
						),
					),
				)
			);
			$candidate = $data['candidates'][0] ?? array();
			if ( 'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed provider identifier selects authored error prose.
				throw new AiFailure( 'ai_incomplete', $this->provider );}
			if ( 'STOP' !== ( $candidate['finishReason'] ?? '' ) || count( $data['candidates'] ?? array() ) !== 1 ) {
				throw new \RuntimeException( 'AI answer unavailable.' );}
			$text = '';
			foreach ( $candidate['content']['parts'] ?? array()as$part ) {
				if ( empty( $part['thought'] ) && is_string( $part['text'] ?? null ) ) {
					$text .= $part['text'];}
			}
		} else {
			$data = $this->request(
				'messages',
				array(
					'model'         => $model,
					'max_tokens'    => 1200,
					'thinking'      => array( 'type' => 'claude-sonnet-5-5' === $model ? 'between_tools' : 'disabled' ),
					'system'        => $system,
					'messages'      => $history,
					'output_config' => array(
						'effort' => 'low',
						'format' => array(
							'type'   => 'json_schema',
							'schema' => AiAnswer::schema(),
						),
					),
				)
			);
			if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed provider identifier selects authored error prose.
				throw new AiFailure( 'ai_incomplete', $this->provider );}
			if ( 'end_turn' !== ( $data['stop_reason'] ?? '' ) || 'assistant' !== ( $data['role'] ?? '' ) ) {
				throw new \RuntimeException( 'AI answer unavailable.' );}
			$text = '';
			foreach ( $data['content'] ?? array()as$part ) {
				if ( 'text' === ( $part['type'] ?? '' ) && is_string( $part['text'] ?? null ) ) {
					$text .= $part['text'];
				} else {
					throw new \RuntimeException( 'Unsupported AI output.' );}
			}
		}
		return AiAnswer::parse( $text, $evidence );
	}
	/** Uses only the bound provider origin and credential header.
	 *
	 * @param string     $path Repository-authored path, not caller input.
	 * @param array|null $payload Optional request body.
	 * @throws \RuntimeException For invalid payload or response.
	 * @throws AiFailure For safe remote failures.
	 */
	private function request( string $path, ?array $payload ): array {
		$body = null === $payload ? null : wp_json_encode( $payload );
		if ( is_string( $body ) && strlen( $body ) > 65000 ) {
			throw new \RuntimeException( 'Conversation context is too large.' );}
		$key     = $this->settings->credential( $this->provider );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( 'gemini' === $this->provider ) {
			$url                       = 'https://generativelanguage.googleapis.com/v1beta/' . $path;
			$headers['x-goog-api-key'] = $key;
		} else {
			$url                          = 'https://api.anthropic.com/v1/' . $path;
			$headers['x-api-key']         = $key;
			$headers['anthropic-version'] = '2023-06-01';}
		$response = wp_remote_request(
			$url,
			array(
				'method'              => null === $payload ? 'GET' : 'POST',
				'timeout'             => 45,
				'redirection'         => 0,
				'limit_response_size' => 262144,
				'headers'             => $headers,
				'body'                => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed provider identifier selects authored error prose.
			throw new AiFailure( 'ai_network', $this->provider );}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$reason = match ( true ) {
				429 === $status=>'ai_rate', in_array( $status, array( 401, 403 ), true )=>'ai_key', 404 === $status=>'ai_model', default=>'ai_remote'};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed category mapped to authored prose, never remote content.
			throw new AiFailure( $reason, $this->provider );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'AI response unavailable.' );
		}return $data;
	}
}
