<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\Checks\DmarcCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/**
 * Unit tests for DmarcCheck.
 *
 * All DNS lookups are stubbed via constructor injection; no real network
 * calls are ever made. Covers valid/missing/multiple/malformed DMARC, policy
 * strength (none/quarantine/reject), DNS failure, and invalid domain input.
 */
final class DmarcCheckTest extends TestCase {

	private function make_check( array|false $txt_records ): DmarcCheck {
		return new DmarcCheck( static fn( string $domain ): array|false => $txt_records );
	}

	private function make_context( string $domain ): DiagnosticContext {
		return new DiagnosticContext( $domain );
	}

	private function txt( string $value ): array {
		return array( 'txt' => $value );
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_get_id_and_category(): void {
		$check = $this->make_check( array() );

		$this->assertSame( 'dmarc_policy', $check->get_id() );
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
			'ip literal'    => array( '192.168.1.1' ),
			'no tld'        => array( 'example' ),
			'wildcard'      => array( '*.example.com' ),
			'control chars' => array( "example.com\r\nInjected" ),
		);
	}

	public function test_does_not_perform_dns_lookup_for_invalid_domain(): void {
		$lookup_called = false;
		$check         = new DmarcCheck(
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
	// Missing DMARC
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_no_txt_records_exist(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
	}

	public function test_returns_fail_when_txt_records_exist_but_none_are_dmarc(): void {
		$records = array( $this->txt( 'google-site-verification=abc123' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
	}

	// -------------------------------------------------------------------------
	// Multiple DMARC
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_multiple_dmarc_records_exist(): void {
		$records = array(
			$this->txt( 'v=DMARC1; p=reject' ),
			$this->txt( 'v=DMARC1; p=none' ),
		);

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
		$this->assertStringContainsString( 'Multiple DMARC', $result->message );
	}

	// -------------------------------------------------------------------------
	// Malformed DMARC
	// -------------------------------------------------------------------------

	public function test_returns_warn_when_policy_tag_is_missing(): void {
		$records = array( $this->txt( 'v=DMARC1; rua=mailto:reports@example.com' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	public function test_returns_warn_when_policy_value_is_unrecognized(): void {
		$records = array( $this->txt( 'v=DMARC1; p=drop' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	// -------------------------------------------------------------------------
	// Policy strength
	// -------------------------------------------------------------------------

	public function test_returns_warn_for_monitor_only_policy(): void {
		$records = array( $this->txt( 'v=DMARC1; p=none' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	/** @dataProvider enforcingPolicyProvider */
	public function test_returns_pass_for_enforcing_policy( string $policy ): void {
		$records = array( $this->txt( "v=DMARC1; p={$policy}" ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
	}

	public static function enforcingPolicyProvider(): array {
		return array(
			'quarantine' => array( 'quarantine' ),
			'reject'     => array( 'reject' ),
		);
	}

	// -------------------------------------------------------------------------
	// Safe evidence
	// -------------------------------------------------------------------------

	public function test_evidence_contains_only_the_dmarc_record_text(): void {
		$dmarc = 'v=DMARC1; p=reject; rua=mailto:reports@example.com';

		$result = $this->make_check( array( $this->txt( $dmarc ) ) )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( $dmarc, $result->evidence );
	}
}
