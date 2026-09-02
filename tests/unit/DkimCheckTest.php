<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\Checks\DkimCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/**
 * Unit tests for DkimCheck.
 *
 * All DNS lookups are stubbed via constructor injection; no real network
 * calls are ever made. Covers the no-selector-configured case (the primary
 * expected outcome, since selectors are never guessed), selector validation,
 * present/missing/multiple/revoked DKIM records, DNS failure, and invalid
 * domain input.
 */
final class DkimCheckTest extends TestCase {

	private function make_check( array|false $txt_records ): DkimCheck {
		return new DkimCheck( static fn( string $domain ): array|false => $txt_records );
	}

	private function make_context( string $domain, array $settings = array() ): DiagnosticContext {
		return new DiagnosticContext( $domain, $settings );
	}

	private function txt( string $value ): array {
		return array( 'txt' => $value );
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_get_id_and_category(): void {
		$check = $this->make_check( array() );

		$this->assertSame( 'dkim_record', $check->get_id() );
		$this->assertSame( 'dns', $check->get_category() );
	}

	// -------------------------------------------------------------------------
	// Domain validation
	// -------------------------------------------------------------------------

	public function test_returns_unknown_for_empty_domain(): void {
		$result = $this->make_check( array() )->run( $this->make_context( '', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'unknown', $result->status );
	}

	public function test_returns_unknown_for_invalid_domain(): void {
		$result = $this->make_check( array() )->run( $this->make_context( '*.example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'unknown', $result->status );
	}

	// -------------------------------------------------------------------------
	// No selector configured — the primary, expected case: never guess.
	// -------------------------------------------------------------------------

	public function test_returns_unknown_when_no_selector_is_configured(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'unknown', $result->status );
	}

	public function test_does_not_perform_dns_lookup_when_no_selector_is_configured(): void {
		$lookup_called = false;
		$check         = new DkimCheck(
			static function ( string $domain ) use ( &$lookup_called ): array|false {
				$lookup_called = true;
				return array();
			}
		);

		$check->run( $this->make_context( 'example.com' ) );

		$this->assertFalse( $lookup_called );
	}

	/** @dataProvider invalidSelectorProvider */
	public function test_returns_unknown_for_an_unsafe_selector( mixed $selector ): void {
		$lookup_called = false;
		$check         = new DkimCheck(
			static function ( string $domain ) use ( &$lookup_called ): array|false {
				$lookup_called = true;
				return array();
			}
		);

		$result = $check->run( $this->make_context( 'example.com', array( 'dkim_selector' => $selector ) ) );

		$this->assertSame( 'unknown', $result->status );
		$this->assertFalse( $lookup_called, 'An unsafe selector must never reach the DNS resolver.' );
	}

	public static function invalidSelectorProvider(): array {
		return array(
			'empty string'    => array( '' ),
			'whitespace only' => array( '   ' ),
			'not a string'    => array( 12345 ),
			'wildcard'        => array( '*' ),
			'space injected'  => array( 'sel ector' ),
			'control chars'   => array( "selector1\r\nInjected" ),
			'too long'        => array( str_repeat( 'a', 64 ) ),
		);
	}

	// -------------------------------------------------------------------------
	// DNS failure
	// -------------------------------------------------------------------------

	public function test_returns_unknown_when_dns_lookup_fails(): void {
		$result = $this->make_check( false )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'unknown', $result->status );
	}

	// -------------------------------------------------------------------------
	// Missing DKIM record
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_no_record_exists_for_the_selector(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'fail', $result->status );
	}

	// -------------------------------------------------------------------------
	// Multiple records
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_multiple_records_exist_for_the_selector(): void {
		$records = array(
			$this->txt( 'v=DKIM1; k=rsa; p=abc123' ),
			$this->txt( 'v=DKIM1; k=rsa; p=def456' ),
		);

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'fail', $result->status );
	}

	// -------------------------------------------------------------------------
	// Malformed / revoked
	// -------------------------------------------------------------------------

	public function test_returns_warn_when_public_key_tag_is_missing(): void {
		$records = array( $this->txt( 'v=DKIM1; k=rsa' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'warn', $result->status );
	}

	public function test_returns_fail_when_public_key_is_revoked(): void {
		$records = array( $this->txt( 'v=DKIM1; k=rsa; p=' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'fail', $result->status );
		$this->assertStringContainsString( 'revoked', strtolower( $result->message ) );
	}

	// -------------------------------------------------------------------------
	// Valid DKIM
	// -------------------------------------------------------------------------

	public function test_returns_pass_for_a_well_formed_record(): void {
		$records = array( $this->txt( 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( 'pass', $result->status );
	}

	// -------------------------------------------------------------------------
	// Safe evidence
	// -------------------------------------------------------------------------

	public function test_evidence_contains_only_the_dkim_record_text(): void {
		$dkim = 'v=DKIM1; k=rsa; p=abc123';

		$result = $this->make_check( array( $this->txt( $dkim ) ) )->run( $this->make_context( 'example.com', array( 'dkim_selector' => 'selector1' ) ) );

		$this->assertSame( $dkim, $result->evidence );
	}
}
