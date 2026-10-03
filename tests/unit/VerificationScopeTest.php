<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Components\VerificationScope;
use Scalyn\MailRelay\Diagnostics\Checks\SpfCheck;
use Scalyn\MailRelay\Diagnostics\Checks\DkimCheck;
use Scalyn\MailRelay\Diagnostics\Checks\DmarcCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

final class VerificationScopeTest extends TestCase {

	public function test_scope_does_not_depend_on_historical_snapshot_claims(): void {
		ob_start();
		VerificationScope::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Even 100/100 is a configuration-check score', $output );
		$this->assertStringContainsString( 'Message authentication and delivery: not verified', $output );
		$this->assertStringContainsString( 'envelope-sender domain', $output );
		$this->assertStringContainsString( 'Older findings labelled valid', $output );
	}

	public function test_spf_record_presence_does_not_claim_ip_authorization(): void {
		$check  = new SpfCheck( static fn( string $domain ) => array( array( 'txt' => 'example.com' === $domain ? 'v=spf1 include:example.net -all' : 'v=spf1 ip4:192.0.2.0/24 -all' ) ) );
		$result = $check->run( new DiagnosticContext( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
		$this->assertStringContainsString( 'Sending-IP authorization has not been evaluated', $result->message );
	}

	public function test_dkim_nonempty_key_does_not_claim_signature_verification(): void {
		$check  = new DkimCheck( static fn() => array( array( 'txt' => 'v=DKIM1; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwAFJg9Cbu86ewrAa/2O8PYIFucFPL9zAbytaHN6uPSeZh2/P6ELDN3uuKMVgunoVh+grXbbSyZTroHTLDZs/0/YYdoWtFxHGEklOKsvopq7ysX5uVCsGPP7TLtd+zM90VXgoQ6A5Yb2KLb+Lg4aP1oyBK4RX/ELaiHwBMmfCPtfrF2VKI/yjJvj0JiQUcDZazZXnw/jmva81UUNFK8jpXl99I8tYCsd43nDZnMsh8I74STDDW55JWENUyKIvMXx+C4TlqdMQeVDggDJcS8caZMaulyfTAEHUNqqng35JReAiG2wO0CqzjeKzT3Rs0d12NwcuUvFgdvkEEL5+cQl7MQIDAQAB' ) ) );
		$result = $check->run( new DiagnosticContext( 'example.com', array( 'dkim_selector' => 'default' ) ) );

		$this->assertSame( 'pass', $result->status );
		$this->assertStringContainsString( 'usable 2048-bit RSA public key', $result->message );
		$this->assertStringContainsString( 'Message signatures have not been verified', $result->message );
	}

	public function test_dmarc_policy_does_not_claim_message_alignment(): void {
		$check  = new DmarcCheck( static fn() => array( array( 'txt' => 'v=DMARC1; p=reject' ) ) );
		$result = $check->run( new DiagnosticContext( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
		$this->assertStringContainsString( 'Message authentication and alignment have not been verified', $result->message );
	}
}
