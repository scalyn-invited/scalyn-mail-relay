<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\Checks\MxCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/**
 * Unit tests for MxCheck.
 *
 * All DNS lookups are stubbed via constructor injection; no real network
 * calls are ever made. Covers MX present/absent, DNS failure, and invalid
 * domain input per the Y2 ticket's test list.
 */
final class MxCheckTest extends TestCase {

	private function make_check( array|false $mx_records ): MxCheck {
		return new MxCheck( static fn( string $domain ): array|false => $mx_records );
	}

	private function make_context( string $domain ): DiagnosticContext {
		return new DiagnosticContext( $domain );
	}

	private function mx( string $target ): array {
		return array(
			'target' => $target,
			'pri'    => 10,
		);
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_get_id_and_category(): void {
		$check = $this->make_check( array() );

		$this->assertSame( 'mx_record', $check->get_id() );
		$this->assertSame( 'dns', $check->get_category() );
	}

	// -------------------------------------------------------------------------
	// Domain validation
	// -------------------------------------------------------------------------

	public function test_returns_unknown_for_empty_domain(): void {
		$result = $this->make_check( array() )->run( $this->make_context( '' ) );

		$this->assertSame( 'unknown', $result->status );
	}

	/** @dataProvider invalidDomainProvider */
	public function test_returns_unknown_for_invalid_domain( string $domain ): void {
		$result = $this->make_check( array() )->run( $this->make_context( $domain ) );

		$this->assertSame( 'unknown', $result->status );
	}

	public static function invalidDomainProvider(): array {
		return array(
			'ip literal'     => array( '192.168.1.1' ),
			'no tld'         => array( 'example' ),
			'wildcard'       => array( '*.example.com' ),
			'control chars'  => array( "example.com\r\nInjected" ),
		);
	}

	public function test_does_not_perform_dns_lookup_for_invalid_domain(): void {
		$lookup_called = false;
		$check         = new MxCheck(
			static function ( string $domain ) use ( &$lookup_called ): array|false {
				$lookup_called = true;
				return array();
			}
		);

		$check->run( $this->make_context( 'not a domain' ) );

		$this->assertFalse( $lookup_called );
	}

	// -------------------------------------------------------------------------
	// DNS failure
	// -------------------------------------------------------------------------

	public function test_returns_unknown_when_dns_lookup_fails(): void {
		$result = $this->make_check( false )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'unknown', $result->status );
	}

	// -------------------------------------------------------------------------
	// MX absent
	// -------------------------------------------------------------------------

	public function test_returns_warn_when_no_mx_records_exist(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	public function test_absent_mx_message_does_not_claim_deliverability_impact(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com' ) );

		$this->assertStringContainsString( 'receive', strtolower( $result->impact ) );
		$this->assertStringContainsString( 'send', strtolower( $result->impact ) );
	}

	// -------------------------------------------------------------------------
	// MX present
	// -------------------------------------------------------------------------

	public function test_returns_pass_when_mx_records_present(): void {
		$result = $this->make_check( array( $this->mx( 'mail.example.com' ) ) )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
	}

	public function test_evidence_lists_mx_hosts(): void {
		$records = array( $this->mx( 'mail1.example.com' ), $this->mx( 'mail2.example.com' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertStringContainsString( 'mail1.example.com', $result->evidence );
		$this->assertStringContainsString( 'mail2.example.com', $result->evidence );
	}

	public function test_message_reports_mx_record_count(): void {
		$records = array( $this->mx( 'mail1.example.com' ), $this->mx( 'mail2.example.com' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertStringContainsString( '2', $result->message );
	}
}
