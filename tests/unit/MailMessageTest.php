<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Mail\MailMessage;

final class MailMessageTest extends TestCase {

	public function test_all_required_fields_are_stored(): void {
		$msg = new MailMessage(
			uuid: 'uuid-001',
			from: 'sender@example.com',
			to: array( 'recipient@example.com' ),
			subject: 'Test Subject',
			body: '<p>Hello</p>'
		);

		$this->assertSame( 'uuid-001', $msg->uuid );
		$this->assertSame( 'sender@example.com', $msg->from );
		$this->assertSame( array( 'recipient@example.com' ), $msg->to );
		$this->assertSame( 'Test Subject', $msg->subject );
		$this->assertSame( '<p>Hello</p>', $msg->body );
	}

	public function test_content_type_defaults_to_html(): void {
		$msg = new MailMessage(
			uuid: 'uuid-002',
			from: 'a@example.com',
			to: array( 'b@example.com' ),
			subject: 'Sub',
			body: 'Body'
		);

		$this->assertSame( 'text/html', $msg->content_type );
	}

	public function test_explicit_plain_text_content_type_is_stored(): void {
		$msg = new MailMessage(
			uuid: 'uuid-003',
			from: 'a@example.com',
			to: array( 'b@example.com' ),
			subject: 'Sub',
			body: 'Plain body',
			content_type: 'text/plain'
		);

		$this->assertSame( 'text/plain', $msg->content_type );
	}

	public function test_optional_fields_default_to_empty_arrays(): void {
		$msg = new MailMessage(
			uuid: 'uuid-004',
			from: 'a@example.com',
			to: array( 'b@example.com' ),
			subject: 'Sub',
			body: 'Body'
		);

		$this->assertSame( array(), $msg->headers );
		$this->assertSame( array(), $msg->attachments );
		$this->assertSame( array(), $msg->context );
	}

	public function test_display_name_from_format_is_preserved(): void {
		$msg = new MailMessage(
			uuid: 'uuid-005',
			from: 'Sender Name <sender@example.com>',
			to: array( 'Recipient Name <recipient@example.com>' ),
			subject: 'Sub',
			body: 'Body'
		);

		$this->assertSame( 'Sender Name <sender@example.com>', $msg->from );
		$this->assertSame( 'Recipient Name <recipient@example.com>', $msg->to[0] );
	}

	public function test_multiple_recipients_are_stored(): void {
		$recipients = array( 'one@example.com', 'two@example.com', 'three@example.com' );
		$msg        = new MailMessage(
			uuid: 'uuid-006',
			from: 'a@example.com',
			to: $recipients,
			subject: 'Sub',
			body: 'Body'
		);

		$this->assertSame( $recipients, $msg->to );
	}

	public function test_context_metadata_is_stored(): void {
		$context = array(
			'source_type' => 'wp_mail',
			'post_id'     => 42,
		);
		$msg     = new MailMessage(
			uuid: 'uuid-007',
			from: 'a@example.com',
			to: array( 'b@example.com' ),
			subject: 'Sub',
			body: 'Body',
			context: $context
		);

		$this->assertSame( $context, $msg->context );
	}
}
