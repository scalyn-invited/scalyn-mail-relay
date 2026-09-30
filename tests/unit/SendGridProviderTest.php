<?php

namespace Scalyn\MailRelay\Providers\SendGrid {
	function wp_safe_remote_post( string $url, array $args ): array {
		$GLOBALS['_sendgrid_http_calls'][] = array( $url, $args );
		return array( 'response' => array( 'code' => 202 ) );
	}

	function wp_remote_retrieve_response_code( mixed $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}

	function is_wp_error( mixed $response ): bool {
		return $response instanceof \Throwable;
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use Scalyn\MailRelay\Contracts\ProviderInterface;
	use Scalyn\MailRelay\Mail\MailMessage;
	use Scalyn\MailRelay\Providers\SendGrid\SendGridProvider;

	final class StubSendGridProvider extends SendGridProvider {
		public array $calls = array();
		public mixed $response = array( 'response' => array( 'code' => 202 ) );

		protected function post( array $args ): mixed {
			$this->calls[] = $args;
			return $this->response;
		}

		protected function response_code( mixed $response ): int {
			return (int) ( $response['response']['code'] ?? 0 );
		}
	}

	final class SendGridProviderTest extends TestCase {
		private const UUID = '12345678-1234-4234-8234-123456789abc';
		private const KEY  = 'SG.synthetic_test_credential_0123456789';

		private function config(): array {
			return array( 'api_key' => self::KEY, 'from_email' => 'sender@example.com', 'from_name' => 'Scalyn' );
		}

		private function message( array $changes = array() ): MailMessage {
			$data = array_replace(
				array(
					'uuid'         => self::UUID,
					'from'         => 'Sender <sender@example.com>',
					'to'           => array( 'Recipient <recipient@example.com>' ),
					'subject'      => 'A test subject',
					'body'         => '<p>Hello</p>',
					'content_type' => 'text/html',
					'headers'      => array(),
					'attachments'  => array(),
					'context'      => array( 'sensitive' => 'private-source' ),
				),
				$changes
			);
			return new MailMessage( ...$data );
		}

		public function test_contract_identity_and_network_free_config_validation(): void {
			$provider = new StubSendGridProvider();
			$this->assertInstanceOf( ProviderInterface::class, $provider );
			$this->assertSame( 'sendgrid', $provider->get_id() );
			$this->assertSame( array( 'html', 'attachments' ), $provider->get_capabilities() );
			$this->assertTrue( $provider->validate_config( $this->config() )->valid );
			$this->assertFalse( $provider->validate_config( array( 'api_key' => 'bad', 'from_email' => 'bad' ) )->valid );
			$this->assertSame( array(), $provider->calls );
		}

		public function test_production_http_boundary_uses_the_fixed_sendgrid_endpoint(): void {
			$GLOBALS['_sendgrid_http_calls'] = array();
			$result = ( new SendGridProvider() )->send( $this->message(), $this->config() );
			$this->assertTrue( $result->success );
			$this->assertCount( 1, $GLOBALS['_sendgrid_http_calls'] );
			$this->assertSame( 'https://api.sendgrid.com/v3/mail/send', $GLOBALS['_sendgrid_http_calls'][0][0] );
			$this->assertSame( 0, $GLOBALS['_sendgrid_http_calls'][0][1]['redirection'] );
		}

		public function test_sandbox_probe_never_claims_a_send(): void {
			$provider           = new StubSendGridProvider();
			$provider->response = array( 'response' => array( 'code' => 200 ) );
			$result             = $provider->test_connection( $this->config() );
			$this->assertTrue( $result->success );
			$this->assertStringContainsString( 'No email was sent', $result->message );
			$this->assertCount( 1, $provider->calls );
			$payload = json_decode( $provider->calls[0]['body'], true );
			$this->assertTrue( $payload['mail_settings']['sandbox_mode']['enable'] );
			$this->assertSame( 'sender@example.com', $payload['personalizations'][0]['to'][0]['email'] );
			$this->assertArrayNotHasKey( 'custom_args', $payload );
			$this->assertSame( 0, count( $provider->calls ) - 1 );
		}

		public function test_send_builds_one_bounded_private_request_and_accepts_only_202(): void {
			$provider = new StubSendGridProvider();
			$message  = $this->message(
				array(
					'headers' => array( 'Cc: Copy <copy@example.com>', 'Bcc: blind@example.com', 'Reply-To: Support <support@example.com>', 'X-Priority: 1' ),
				)
			);
			$result = $provider->send( $message, $this->config() );
			$this->assertTrue( $result->success );
			$this->assertSame( '202', $result->response_code );
			$this->assertNull( $result->provider_message_id );
			$this->assertStringContainsString( 'delivery is unconfirmed', $result->response_message );
			$this->assertCount( 1, $provider->calls );
			$args = $provider->calls[0];
			$this->assertSame( 'Bearer ' . self::KEY, $args['headers']['Authorization'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertTrue( $args['sslverify'] );
			$this->assertTrue( $args['reject_unsafe_urls'] );
			$this->assertSame( 15, $args['timeout'] );
			$this->assertSame( 1024, $args['limit_response_size'] );
			$this->assertStringNotContainsString( self::KEY, $args['body'] );
			$this->assertStringNotContainsString( 'private-source', $args['body'] );
			$payload = json_decode( $args['body'], true );
			$this->assertSame( self::UUID, $payload['custom_args']['scalyn_message_uuid'] );
			$this->assertSame( 'copy@example.com', $payload['personalizations'][0]['cc'][0]['email'] );
			$this->assertSame( 'blind@example.com', $payload['personalizations'][0]['bcc'][0]['email'] );
			$this->assertSame( 'support@example.com', $payload['reply_to']['email'] );
			$this->assertSame( array( 'X-Priority' => '1' ), $payload['headers'] );
			$this->assertFalse( $payload['tracking_settings']['open_tracking']['enable'] );
			$this->assertFalse( $payload['tracking_settings']['click_tracking']['enable'] );
		}

		public function test_plain_text_and_sender_fallback(): void {
			$provider = new StubSendGridProvider();
			$provider->send( $this->message( array( 'from' => 'sender@example.com', 'content_type' => 'text/plain', 'body' => 'Hello' ) ), $this->config() );
			$payload = json_decode( $provider->calls[0]['body'], true );
			$this->assertSame( 'Scalyn', $payload['from']['name'] );
			$this->assertSame( 'text/plain', $payload['content'][0]['type'] );
			$this->assertSame( 'Hello', $payload['content'][0]['value'] );
		}

		public function test_local_attachment_is_encoded_once_and_filename_only_is_sent(): void {
			$path = tempnam( sys_get_temp_dir(), 'sg-test-' );
			$this->assertNotFalse( $path );
			try {
				file_put_contents( $path, 'Attachment bytes' );
				$provider = new StubSendGridProvider();
				$this->assertTrue( $provider->send( $this->message( array( 'attachments' => array( $path ) ) ), $this->config() )->success );
				$payload = json_decode( $provider->calls[0]['body'], true );
				$this->assertSame( basename( $path ), $payload['attachments'][0]['filename'] );
				$this->assertSame( base64_encode( 'Attachment bytes' ), $payload['attachments'][0]['content'] );
				$this->assertStringNotContainsString( $path, $provider->calls[0]['body'] );
			} finally {
				unlink( $path );
			}
		}

		public function test_unsupported_message_inputs_do_not_issue_http_requests(): void {
			$changes = array(
				array( 'from' => 'other@example.com' ),
				array( 'to' => array() ),
				array( 'to' => array( 'bad' ) ),
				array( 'uuid' => 'bad' ),
				array( 'content_type' => 'application/octet-stream' ),
				array( 'body' => str_repeat( 'x', 1048577 ) ),
				array( 'subject' => "Bad\r\nSubject" ),
				array( 'headers' => array( "Bcc: victim@example.com\r\nX-Test: value" ) ),
				array( 'headers' => array( 'From: other@example.com' ) ),
				array( 'headers' => array( 'Reply-To: a@example.com,b@example.com' ) ),
				array( 'headers' => array( 'Cc: a@example.com', 'Cc: invalid' ) ),
				array( 'attachments' => array( 'https://example.com/private' ) ),
				array( 'attachments' => array( 'C:/missing/private.txt' ) ),
			);
			foreach ( $changes as $change ) {
				$provider = new StubSendGridProvider();
				$result   = $provider->send( $this->message( $change ), $this->config() );
				$this->assertFalse( $result->success );
				$this->assertSame( array(), $provider->calls );
				$this->assertStringNotContainsString( 'victim@example.com', (string) $result->response_message );
			}
		}

		public function test_provider_errors_and_transport_uncertainty_expose_no_response_body(): void {
			$provider = new StubSendGridProvider();
			foreach ( array( 200, 400, 401, 403, 429, 500, 0 ) as $status ) {
				$provider->response = array( 'response' => array( 'code' => $status ), 'body' => 'secret remote response' );
				$result             = $provider->send( $this->message(), $this->config() );
				$this->assertFalse( $result->success );
				$this->assertStringNotContainsString( 'secret remote response', (string) $result->response_message );
			}
			$provider->response = new \RuntimeException( 'secret network error' );
			$result             = $provider->send( $this->message(), $this->config() );
			$this->assertFalse( $result->success );
			$this->assertStringContainsString( 'unconfirmed', (string) $result->response_message );
			$this->assertStringNotContainsString( 'secret network error', (string) $result->response_message );
		}

		public function test_http_401_does_not_infer_authentication_failure_from_status_alone(): void {
			$provider = new StubSendGridProvider();
			$provider->response = array( 'response' => array( 'code' => 401 ), 'body' => 'unrecognized response' );
			$result = $provider->send( $this->message(), $this->config() );
			$this->assertSame( 'provider-rejection', $result->failure_category );
			$this->assertSame( '401', $result->response_code );
			$this->assertStringContainsString( 'Email API credits', $result->response_message );
			$this->assertStringContainsString( 'HTTP 401', $provider->test_connection( $this->config() )->message );
		}

		public function test_exact_credit_restriction_has_safe_actionable_guidance(): void {
			$provider = new StubSendGridProvider();
			$provider->response = array( 'response' => array( 'code' => 401 ), 'body' => '{"errors":[{"message":"Maximum credits exceeded","field":"private@example.com"}]}' );
			$result = $provider->send( $this->message(), $this->config() );
			$this->assertSame( 'provider-rejection', $result->failure_category );
			$this->assertFalse( $result->acceptance_unconfirmed );
			$this->assertStringContainsString( 'credits are exhausted', $result->response_message );
			$this->assertStringContainsString( 'credits are exhausted', $provider->test_connection( $this->config() )->message );
			$this->assertStringNotContainsString( 'private@example.com', serialize( $result ) );
		}

		public function test_only_an_exact_bounded_single_error_can_identify_exhausted_credits(): void {
			foreach ( array( '', 'not json', '{"errors":["Maximum credits exceeded"]}', '{"errors":true}', '{"errors":[{"message":"Maximum credits exceeded secret"}]}', '{"errors":[{"message":"Maximum credits exceeded"},{}]}', str_repeat( ' ', 1025 ) . '{"errors":[{"message":"Maximum credits exceeded"}]}' ) as $body ) {
				$provider = new StubSendGridProvider();
				$provider->response = array( 'response' => array( 'code' => 401 ), 'body' => $body );
				$this->assertStringNotContainsString( 'credits are exhausted', $provider->send( $this->message(), $this->config() )->response_message );
			}
		}

		public function test_outcome_matrix_never_retries_or_treats_uncertainty_as_failure(): void {
			foreach ( array( 0, 100, 200, 201, 202, 204, 301, 400, 401, 403, 404, 405, 408, 413, 429, 451, 500, 502, 503, 504, 599, 600 ) as $code ) {
				$provider = new StubSendGridProvider();
				$provider->response = array( 'response' => array( 'code' => $code ), 'body' => 'private recipient and credential' );
				$result = $provider->send( $this->message(), $this->config() );
				$accepted = 202 === $code;
				$rejected = $code >= 400 && $code < 500 && 408 !== $code;
				$this->assertSame( $accepted, $result->success, (string) $code );
				$this->assertSame( ! $accepted && ! $rejected, $result->acceptance_unconfirmed, (string) $code );
				$this->assertFalse( $result->retryable );
				$this->assertCount( 1, $provider->calls );
				$this->assertStringNotContainsString( 'private recipient', serialize( $result ) );
				if ( 429 === $code ) {
					$this->assertStringContainsString( 'rate limiting', $result->response_message );
				}
			}
		}
	}
}
