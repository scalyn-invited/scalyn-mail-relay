<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Components\HeaderAnalysisForm;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Diagnostics\HeaderAnalyzer;

final class HeaderAnalyzerTest extends TestCase {

	/** Synthetic headers shaped like a Gmail copy of a Postmark message. */
	private function postmark_gmail( string $ar = '' ): string {
		$ar = '' !== $ar ? $ar : "mx.google.com;\r\n       dkim=pass header.i=@example.com header.s=20240101pm header.b=abc;\r\n       dkim=pass header.i=@pm.mtasv.net header.s=pm header.b=def;\r\n       spf=pass (google.com: domain of pm_bounces@pm-bounces.example.com designates 203.0.113.5 as permitted sender) smtp.mailfrom=pm_bounces@pm-bounces.example.com;\r\n       dmarc=pass (p=QUARANTINE sp=NONE dis=NONE) header.from=example.com";
		return "Delivered-To: private.person@gmail.com\r\nReceived: by 2002:a05:6000:1:b0:1 with SMTP id x; Fri, 3 Oct 2026 01:00:00 -0700\r\nReceived: from sc-ord-mta117.mtasv.net (sc-ord-mta117.mtasv.net. [203.0.113.5])\r\n        by mx.google.com with ESMTPS id y\r\n        (version=TLS1_3 cipher=TLS_AES_256_GCM_SHA384 bits=256/256);\r\nAuthentication-Results: {$ar}\r\nDKIM-Signature: v=1; a=rsa-sha256; d=example.com; s=20240101pm; b=abc\r\nDKIM-Signature: v=1; a=rsa-sha256; d=pm.mtasv.net; s=pm; b=def\r\nFrom: \"Private Sender\" <secret.sender@example.com>\r\nTo: private.person@gmail.com\r\nSubject: Confidential subject line\r\nMessage-ID: <unique-private-id@example.com>\r\n\r\nPrivate body text";
	}

	public function test_postmark_message_passes_dmarc_through_aligned_dkim(): void {
		$result = ( new HeaderAnalyzer() )->analyze( $this->postmark_gmail() );
		$this->assertSame( 'pass', $result['status'] );
		$this->assertSame( 'example.com', $result['summary']['from_domain'] );
		$this->assertSame( 'mx.google.com', $result['summary']['receiver'] );
		$this->assertTrue( $result['summary']['tls_observed'] );
		$this->assertSame( 2, $result['summary']['hops'] );
		$by = array_column( $result['findings'], null, 'label' );
		// A custom Return-Path under the From domain gives relaxed SPF alignment.
		$this->assertSame( 'pass', $by['SPF']['status'] );
		$this->assertStringContainsString( 'aligned (relaxed, subdomain)', $by['SPF']['text'] );
	}

	public function test_bounce_domain_spf_is_not_aligned_but_dkim_carries_dmarc(): void {
		$ar     = 'mx.google.com; dkim=pass header.d=example.com header.s=pm; spf=pass smtp.mailfrom=pm_bounces@pm.mtasv.net; dmarc=pass header.from=example.com';
		$result = ( new HeaderAnalyzer() )->analyze( $this->postmark_gmail( $ar ) );
		$by     = array_column( $result['findings'], null, 'label' );
		$this->assertSame( 'pass', $result['status'] );
		$this->assertSame( 'warn', $by['SPF']['status'] );
		$this->assertStringContainsString( 'not aligned', $by['SPF']['text'] );
		$this->assertStringContainsString( "bounce domain", $by['SPF']['text'] );
		$this->assertSame( 'pass', $by['DKIM']['status'] );
		$this->assertStringContainsString( 'aligned (exact domain)', $by['DKIM']['text'] );
	}

	public function test_unaligned_dkim_and_dmarc_failure(): void {
		$ar     = 'mx.example.net; dkim=pass header.d=pm.mtasv.net; spf=pass smtp.mailfrom=bounce@pm.mtasv.net; dmarc=fail header.from=example.com';
		$result = ( new HeaderAnalyzer() )->analyze( $this->postmark_gmail( $ar ) );
		$by     = array_column( $result['findings'], null, 'label' );
		$this->assertSame( 'fail', $result['status'] );
		$this->assertSame( 'warn', $by['DKIM']['status'] );
		$this->assertStringContainsString( 'configure DKIM signing with your own domain', $by['DKIM']['text'] );
		$this->assertSame( 'fail', $by['DMARC']['status'] );
	}

	public function test_no_receiver_verdict_stays_unknown_and_sibling_domains_are_only_possible(): void {
		$computed = ( new HeaderAnalyzer() )->analyze( $this->postmark_gmail( 'mx.example.net; dkim=pass header.d=mail.example.com' ) );
		$by       = array_column( $computed['findings'], null, 'label' );
		$this->assertSame( 'unknown', $by['DMARC']['status'] );
		$this->assertSame( 'unknown', $computed['status'] );
		$this->assertStringContainsString( 'alignment mode are not known', $by['DMARC']['text'] );
		$this->assertStringContainsString( 'relaxed', $by['DKIM']['text'] );
		$sibling = str_replace( 'From: "Private Sender" <secret.sender@example.com>', 'From: <a@news.example.com>', $this->postmark_gmail( 'mx.example.net; dkim=pass header.d=mail.example.com' ) );
		$by      = array_column( ( new HeaderAnalyzer() )->analyze( $sibling )['findings'], null, 'label' );
		$this->assertStringContainsString( 'possibly aligned', $by['DKIM']['text'] );
		$this->assertSame( 'unknown', $by['DMARC']['status'] );
	}

	public function test_sent_folder_copy_without_authentication_results_is_unknown(): void {
		$result = ( new HeaderAnalyzer() )->analyze( "From: a@example.com\nTo: b@example.net\nSubject: hi\n" );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertStringContainsString( 'recipient mailbox', $result['findings'][0]['text'] );
	}

	public function test_even_exact_domain_passes_cannot_invent_a_missing_dmarc_verdict(): void {
		foreach ( array( 'dkim=pass header.d=example.com', 'spf=pass smtp.mailfrom=sender@example.com', 'spf=pass smtp.mailfrom=sender@mail.example.com' ) as $mechanism ) {
			$result = ( new HeaderAnalyzer() )->analyze( $this->postmark_gmail( 'mx.example.net; ' . $mechanism ) );
			$by = array_column( $result['findings'], null, 'label' );
			$this->assertSame( 'unknown', $by['DMARC']['status'] );
			$this->assertSame( 'unknown', $result['status'] );
		}
	}

	public function test_output_never_contains_private_header_content(): void {
		$output = json_encode( ( new HeaderAnalyzer() )->analyze( $this->postmark_gmail() ) );
		foreach ( array( 'private.person', 'secret.sender', 'Private Sender', 'Confidential', 'unique-private-id', 'Private body', 'pm_bounces@', '203.0.113.5' ) as $private ) {
			$this->assertStringNotContainsString( $private, $output, $private );
		}
	}

	public function test_rejects_oversized_and_non_header_input(): void {
		foreach ( array( str_repeat( 'a', HeaderAnalyzer::MAX_BYTES + 1 ), 'just some text', '' ) as $input ) {
			try { ( new HeaderAnalyzer() )->analyze( $input ); $this->fail( 'Must reject' ); }
			catch ( InvalidArgumentException $error ) { $this->assertStringNotContainsString( 'some text', $error->getMessage() ); }
		}
	}

	public function test_form_requires_capability_and_nonce_and_never_echoes_input(): void {
		$GLOBALS['_test_current_user_can'] = array( Capabilities::RUN_DIAGNOSTICS => true );
		$GLOBALS['_test_wp_nonce_valid']   = true;
		$_SERVER['REQUEST_METHOD']         = 'POST';
		$_POST                             = array( 'scalyn_header_analysis' => '1', '_wpnonce' => 'valid', 'scalyn_headers' => $this->postmark_gmail() );
		$form                              = new HeaderAnalysisForm();
		$form->handle();
		ob_start();
		$form->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'as judged by mx.google.com', $html );
		$this->assertStringContainsString( 'scalyn-badge--healthy', $html );
		foreach ( array( 'private.person', 'secret.sender', 'Confidential', 'Private body' ) as $private ) {
			$this->assertStringNotContainsString( $private, $html );
		}
		$this->assertSame( '', $_POST['scalyn_headers'] );
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$_POST['scalyn_headers']         = $this->postmark_gmail();
		try { ( new HeaderAnalysisForm() )->handle(); $this->fail( 'Bad nonce must die' ); }
		catch ( RuntimeException $error ) { $this->assertStringContainsString( 'Nonce', $error->getMessage() ); }
		$GLOBALS['_test_wp_nonce_valid']   = true;
		$GLOBALS['_test_current_user_can'] = array();
		ob_start();
		( new HeaderAnalysisForm() )->render();
		$this->assertSame( '', ob_get_clean() );
		$this->expectException( RuntimeException::class );
		try { ( new HeaderAnalysisForm() )->handle(); } finally { $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET'; }
	}
}
