<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\Checks\SpfCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/**
 * Unit tests for SpfCheck.
 *
 * All DNS lookups are stubbed via constructor injection; no real network
 * calls are ever made. Covers valid/missing/multiple/malformed SPF, DNS
 * failure, and invalid domain input per the Y2 ticket's test list.
 */
final class SpfCheckTest extends TestCase {

	private function make_check( array|false $txt_records ): SpfCheck {
		return new SpfCheck( static fn( string $domain ): array|false => $txt_records );
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

		$this->assertSame( 'spf_record', $check->get_id() );
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
			'leading hyphen' => array( '-example.com' ),
			'no tld'         => array( 'example' ),
			'space injected' => array( 'example .com' ),
			'wildcard'       => array( '*.example.com' ),
			'control chars'  => array( "example.com\r\nInjected" ),
			'too long'       => array( str_repeat( 'a', 250 ) . '.com' ),
		);
	}

	public function test_does_not_perform_dns_lookup_for_invalid_domain(): void {
		$lookup_called = false;
		$check         = new SpfCheck(
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
	// Missing SPF
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_no_txt_records_exist(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
	}

	public function test_returns_fail_when_txt_records_exist_but_none_are_spf(): void {
		$records = array( $this->txt( 'google-site-verification=abc123' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
	}

	// -------------------------------------------------------------------------
	// Multiple SPF
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_multiple_spf_records_exist(): void {
		$records = array(
			$this->txt( 'v=spf1 include:_spf.example.com ~all' ),
			$this->txt( 'v=spf1 include:_spf.other.com ~all' ),
		);

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
		$this->assertStringContainsString( 'Multiple SPF', $result->message );
	}

	// -------------------------------------------------------------------------
	// Malformed SPF (present but missing terminal mechanism)
	// -------------------------------------------------------------------------

	public function test_returns_warn_when_spf_missing_terminal_mechanism(): void {
		$records = array( $this->txt( 'v=spf1 include:_spf.example.com' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	// -------------------------------------------------------------------------
	// Valid SPF
	// -------------------------------------------------------------------------

	/** @dataProvider validSpfProvider */
	public function test_returns_pass_for_well_formed_spf( string $spf ): void {
		$result = $this->make_check( array( $this->txt( $spf ) ) )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
	}

	public static function validSpfProvider(): array {
		return array(
			'soft fail all' => array( 'v=spf1 include:_spf.example.com ~all' ),
			'hard fail all' => array( 'v=spf1 include:_spf.example.com -all' ),
			'neutral all'   => array( 'v=spf1 include:_spf.example.com ?all' ),
			'redirect'      => array( 'v=spf1 redirect=_spf.example.com' ),
		);
	}

	public function test_ignores_non_spf_txt_records_when_a_valid_spf_is_present(): void {
		$records = array(
			$this->txt( 'google-site-verification=abc123' ),
			$this->txt( 'v=spf1 include:_spf.example.com ~all' ),
		);

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
	}

	// -------------------------------------------------------------------------
	// Safe evidence
	// -------------------------------------------------------------------------

	public function test_evidence_contains_only_the_spf_record_text(): void {
		$spf = 'v=spf1 include:_spf.example.com ~all';

		$result = $this->make_check( array( $this->txt( $spf ) ) )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( $spf, $result->evidence );
	}
}
