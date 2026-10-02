<?php

use PHPUnit\Framework\TestCase;
use PHPMailer\PHPMailer\PHPMailer;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\FailureClassifier;
use Scalyn\MailRelay\Providers\Smtp\SmtpProvider;
use Scalyn\MailRelay\Providers\SendGrid\SendGridProvider;

/** Shared assertions run against both real adapters; only network boundaries are fake. */
final class ProviderContractTest extends TestCase {
	private const UUID = '12345678-1234-4234-8234-123456789abc';
	private const SECRET = 'synthetic-credential-never-persist';

	public static function providers(): array {
		return array( 'SMTP' => array( 'smtp' ), 'SendGrid' => array( 'sendgrid' ), 'Postmark' => array( 'postmark' ) );
	}

	private function fixture( string $id ): array {
		$config = array( 'from_email' => 'sender@example.com', 'from_name' => 'Sender' );
		if ( 'smtp' === $id ) {
			$boundary = new PHPMailer();
			$provider = new class( $boundary ) extends SmtpProvider {
				public function __construct( private PHPMailer $boundary ) {}
				protected function create_mailer(): PHPMailer { return $this->boundary; }
			};
			$config += array( 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'sender@example.com', 'password' => self::SECRET );
		} elseif ( 'postmark' === $id ) {
			$provider = new class() extends \Scalyn\MailRelay\Providers\Postmark\PostmarkProvider {
				public array $requests = array();
				public int $code = 200;
				public int $probes = 0;
				protected function http(string $path, array $args): mixed {
					if ('/server' === $path) { ++$this->probes; return ['response'=>['code'=>200],'body'=>'{"ID":1,"DeliveryType":"Live"}']; }
					$this->requests[]=$args;
					return ['response'=>['code'=>$this->code],'body'=>'{"ErrorCode":0,"MessageID":"12345678-1234-4234-8234-123456789abc"}'];
				}
			};
			$boundary=$provider; $config += ['api_key'=>self::SECRET];
		} else {
			$provider = new class() extends SendGridProvider {
				public array $requests = array();
				public int $code = 202;
				protected function post( array $args ): mixed {
					$this->requests[] = $args;
					return array( 'response' => array( 'code' => $this->code ), 'body' => 'private remote response' );
				}
				protected function response_code( mixed $response ): int { return $response['response']['code']; }
			};
			$boundary = $provider;
			$config += array( 'api_key' => self::SECRET );
		}
		return array( $provider, $boundary, $config );
	}

	private function message( array $overrides = array() ): MailMessage {
		return new MailMessage( ...array_replace( array(
			'uuid' => self::UUID,
			'from' => 'Sender <sender@example.com>',
			'to' => array( 'Recipient <recipient@example.com>' ),
			'subject' => 'Contract verification — café',
			'body' => 'Hello — café',
			'content_type' => 'text/plain',
			'headers' => array(),
			'attachments' => array(),
			'context' => array( 'private' => 'internal-secret-context' ),
		), $overrides ) );
	}

	private function send_count( string $id, object $boundary ): int {
		return 'smtp' === $id ? $boundary->send_calls : count( $boundary->requests );
	}

	/** @dataProvider providers */
	public function test_validation_and_connection_probe_do_not_send_mail( string $id ): void {
		list( $provider, $boundary, $config ) = $this->fixture( $id );
		$this->assertInstanceOf( ProviderInterface::class, $provider );
		$this->assertTrue( $provider->validate_config( $config )->valid );
		$this->assertFalse( $provider->validate_config( array() )->valid );
		$this->assertSame( 0, $this->send_count( $id, $boundary ) );
		if ( 'sendgrid' === $id ) { $boundary->code = 200; }
		$this->assertTrue( $provider->test_connection( $config )->success );
		if ( 'smtp' === $id ) {
			$this->assertSame( 0, $boundary->send_calls );
			$this->assertSame( 1, $boundary->connect_calls );
			$this->assertTrue( $boundary->smtpClose_was_called );
		} elseif ('postmark' === $id) {
			$this->assertSame(1,$boundary->probes);
			$this->assertSame(0,$this->send_count($id,$boundary));
		} else {
			$this->assertCount( 1, $boundary->requests );
			$payload = json_decode( $boundary->requests[0]['body'], true );
			$this->assertTrue( $payload['mail_settings']['sandbox_mode']['enable'] );
			$this->assertArrayNotHasKey( 'custom_args', $payload );
		}
	}

	/** @dataProvider providers */
	public function test_common_plain_and_html_attachment_contract( string $id ): void {
		$path = tempnam( sys_get_temp_dir(), 'contract-' );
		try {
			file_put_contents( $path, 'Synthetic attachment bytes.' );
			foreach ( array( 'text/plain', 'text/html' ) as $type ) {
				list( $provider, $boundary, $config ) = $this->fixture( $id );
				$message = $this->message( array( 'content_type' => $type, 'attachments' => array( $path ), 'headers' => array( 'Cc: Copy <copy@example.com>', 'Bcc: blind@example.com', 'Reply-To: Support <support@example.com>', 'X-Priority: 1' ) ) );
				$result = $provider->send( $message, $config );
				$this->assertTrue( $result->success );
				$this->assertSame( $id, $result->provider );
				$this->assertFalse( $result->acceptance_unconfirmed );
				$this->assertFalse( $result->retryable );
				$this->assertSame( 1, $this->send_count( $id, $boundary ) );
				$this->assertStringContainsString( 'accepted', $result->response_message );
				foreach ( array( self::SECRET, 'blind@example.com', 'recipient@example.com', 'café', 'Synthetic attachment', 'internal-secret-context', $path ) as $private ) {
					$this->assertStringNotContainsString( $private, serialize( $result ) );
				}
				if ( 'smtp' === $id ) {
					$this->assertSame( 'text/html' === $type, $boundary->is_html );
					$this->assertSame( $message->body, $boundary->Body );
					$this->assertSame( $message->subject, $boundary->Subject );
					$this->assertSame( 'UTF-8', $boundary->CharSet );
					$this->assertSame( 'copy@example.com', $boundary->cc[0]['address'] );
					$this->assertSame( 'blind@example.com', $boundary->bcc[0]['address'] );
					$this->assertSame( 'support@example.com', $boundary->reply_to[0]['address'] );
					$this->assertSame( array( $path ), $boundary->attachments );
				} elseif ('postmark' === $id) {
					$payload=json_decode($boundary->requests[0]['body'],true);
					$this->assertSame($message->body,$payload['text/html' === $type ? 'HtmlBody' : 'TextBody']);
					$this->assertSame($message->subject,$payload['Subject']);
					$this->assertSame(self::UUID,$payload['Metadata']['scalyn_message_uuid']);
					$this->assertSame('"Copy" <copy@example.com>',$payload['Cc']);
					$this->assertSame('blind@example.com',$payload['Bcc']);
					$this->assertSame('"Support" <support@example.com>',$payload['ReplyTo']);
					$this->assertSame('Synthetic attachment bytes.',base64_decode($payload['Attachments'][0]['Content'],true));
					$this->assertSame('outbound',$payload['MessageStream']);
					$this->assertFalse($payload['TrackOpens']);
					$this->assertSame('None',$payload['TrackLinks']);
					$this->assertStringNotContainsString('internal-secret-context',$boundary->requests[0]['body']);
				} else {
					$payload = json_decode( $boundary->requests[0]['body'], true );
					$this->assertSame( $type, $payload['content'][0]['type'] );
					$this->assertSame( $message->body, $payload['content'][0]['value'] );
					$this->assertSame( $message->subject, $payload['subject'] );
					$this->assertSame( self::UUID, $payload['custom_args']['scalyn_message_uuid'] );
					$this->assertSame( 'copy@example.com', $payload['personalizations'][0]['cc'][0]['email'] );
					$this->assertSame( 'blind@example.com', $payload['personalizations'][0]['bcc'][0]['email'] );
					$this->assertSame( 'support@example.com', $payload['reply_to']['email'] );
					$this->assertSame( 'Synthetic attachment bytes.', base64_decode( $payload['attachments'][0]['content'], true ) );
					$this->assertStringNotContainsString( 'internal-secret-context', $boundary->requests[0]['body'] );
				}
			}
		} finally { unlink( $path ); }
	}

	/** @dataProvider providers */
	public function test_unsupported_input_fails_before_any_send( string $id ): void {
		foreach ( array(
			array( 'to' => array() ), array( 'from' => 'invalid' ),
			array( 'to' => array( 'invalid' ) ), array( 'body' => '' ),
			array( 'content_type' => 'application/pdf' ),
			array( 'subject' => "Injected\r\nsubject" ),
			array( 'headers' => array( 'Bcc: invalid' ) ),
			array( 'headers' => array( 'Reply-To: a@example.com,b@example.com' ) ),
			array( 'headers' => array( 'Reply-To: a@example.com', 'reply-to: b@example.com' ) ),
			array( 'headers' => array( "X-Priority: 1\r\nBcc: private@example.com" ) ),
			array( 'attachments' => array( 'https://example.com/private.txt' ) ),
			array( 'attachments' => array( __DIR__ . '/missing-contract-file.txt' ) ),
		) as $change ) {
			list( $provider, $boundary, $config ) = $this->fixture( $id );
			$result = $provider->send( $this->message( $change ), $config );
			$this->assertFalse( $result->success );
			$this->assertSame( 'config', $result->failure_category );
			$this->assertFalse( $result->acceptance_unconfirmed );
			$this->assertSame( 0, $this->send_count( $id, $boundary ) );
			if ( 'smtp' === $id ) { $this->assertSame( 0, $boundary->connect_calls ); }
			$this->assertStringNotContainsString( 'private@example.com', serialize( $result ) );
		}
	}

	/** @dataProvider providers */
	public function test_uncertainty_is_safe_and_never_automatically_retried( string $id ): void {
		list( $provider, $boundary, $config ) = $this->fixture( $id );
		if ( 'smtp' === $id ) {
			$boundary->send_exception = new \PHPMailer\PHPMailer\Exception( 'Timeout with private recipient and credential.' );
		} else { $boundary->code = 504; }
		$result = $provider->send( $this->message(), $config );
		$this->assertFalse( $result->success );
		$this->assertTrue( $result->acceptance_unconfirmed );
		$this->assertFalse( $result->retryable );
		$this->assertSame( 1, $this->send_count( $id, $boundary ) );
		$this->assertStringContainsString( 'avoid duplicate', ( new FailureClassifier() )->classify( $result )->suggestion );
		$this->assertStringNotContainsString( 'private', serialize( $result ) );
	}
}
