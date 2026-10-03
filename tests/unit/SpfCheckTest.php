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

	/**
	 * The root domain returns $txt_records; included domains return $includes
	 * entries, defaulting to a valid terminal policy so chains resolve.
	 */
	private function make_check( array|false $txt_records, array $includes = array() ): SpfCheck {
		return new SpfCheck(
			static function ( string $domain ) use ( $txt_records, $includes ): array|false {
				if ( 'example.com' === $domain ) {
					return $txt_records;
				}
				return $includes[ $domain ] ?? array( array( 'txt' => 'v=spf1 ip4:192.0.2.0/24 -all' ) );
			}
		);
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
			'redirect'      => array( 'v=spf1 redirect=_spf.example.com' ),
			'ip ranges'     => array( 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 mx a -all' ),
		);
	}

	public function test_pass_reports_lookup_count_and_include_chain(): void {
		$result = $this->make_check( array( $this->txt( 'v=spf1 include:_spf.example.com mx ~all' ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'pass', $result->status );
		$this->assertStringContainsString( '2 of 10 DNS lookups', $result->message );
		$this->assertSame( array( '_spf.example.com' ), $result->raw['evaluation']['includes'] );
		$this->assertSame( 2, $result->raw['evaluation']['lookups'] );
	}

	// -------------------------------------------------------------------------
	// Deeper evaluation (RFC 7208 limits and weak policies)
	// -------------------------------------------------------------------------

	public function test_more_than_ten_lookups_across_nested_includes_fails(): void {
		$nested   = 'v=spf1 include:a.example.net include:b.example.net include:c.example.net include:d.example.net include:e.example.net ~all';
		$includes = array( '_spf.example.com' => array( $this->txt( $nested ) ) );
		$root     = 'v=spf1 include:_spf.example.com a mx include:x.example.org include:y.example.org include:z.example.org -all';
		$result   = $this->make_check( array( $this->txt( $root ) ), $includes )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'fail', $result->status );
		$this->assertStringContainsString( '11 DNS lookups', $result->message );
		$this->assertSame( 11, $result->raw['evaluation']['lookups'] );
	}

	public function test_redirect_is_ignored_when_all_is_present_and_counted_otherwise(): void {
		$with_all = $this->make_check( array( $this->txt( 'v=spf1 -all redirect=_spf.example.com' ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 0, $with_all->raw['evaluation']['lookups'] );
		$redirect = $this->make_check( array( $this->txt( 'v=spf1 redirect=_spf.example.com' ) ), array( '_spf.example.com' => array( $this->txt( 'v=spf1 ?all' ) ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'warn', $redirect->status, 'A redirected policy\'s "all" applies to the domain' );
	}

	public function test_include_without_spf_record_and_void_lookups_fail(): void {
		$result = $this->make_check( array( $this->txt( 'v=spf1 include:gone.example.net include:gone2.example.net include:gone3.example.net -all' ) ), array( 'gone.example.net' => array(), 'gone2.example.net' => array(), 'gone3.example.net' => array() ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'fail', $result->status );
		$this->assertSame( 3, $result->raw['evaluation']['void_lookups'] );
		$this->assertStringContainsString( 'void lookups', $result->evidence );
	}

	public function test_include_loop_fails_but_repeated_branch_includes_do_not(): void {
		$loop = $this->make_check( array( $this->txt( 'v=spf1 include:_spf.example.com -all' ) ), array( '_spf.example.com' => array( $this->txt( 'v=spf1 include:_spf.example.com -all' ) ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'fail', $loop->status );
		$this->assertStringContainsString( 'loops back', $loop->message );
		$shared = $this->make_check( array( $this->txt( 'v=spf1 include:one.example.net include:two.example.net -all' ) ), array( 'one.example.net' => array( $this->txt( 'v=spf1 include:shared.example.net -all' ) ), 'two.example.net' => array( $this->txt( 'v=spf1 include:shared.example.net -all' ) ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'pass', $shared->status );
	}

	/** @dataProvider weakOrBrokenProvider */
	public function test_weak_or_broken_policies( string $spf, string $status, string $text ): void {
		$result = $this->make_check( array( $this->txt( $spf ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( $status, $result->status );
		$this->assertStringContainsString( $text, $result->message );
	}

	public static function weakOrBrokenProvider(): array {
		return array(
			'plus all'        => array( 'v=spf1 +all', 'fail', '+all' ),
			'implicit plus'   => array( 'v=spf1 ip4:192.0.2.1 all', 'fail', '+all' ),
			'neutral all'     => array( 'v=spf1 include:_spf.example.com ?all', 'warn', '?all' ),
			'ptr'             => array( 'v=spf1 ptr -all', 'warn', 'ptr' ),
			'bad syntax'      => array( 'v=spf1 includes:_spf.example.com -all', 'fail', 'not valid syntax' ),
			'bad ip'          => array( 'v=spf1 ip4:999.1.1.1 -all', 'fail', 'not a valid network' ),
			'two redirects'   => array( 'v=spf1 redirect=a.example.net redirect=b.example.net', 'fail', 'more than one redirect' ),
			'macro lower bnd' => array( 'v=spf1 include:%{d}.spf.example.net -all', 'warn', 'lower bound' ),
		);
	}

	public function test_unresolvable_include_is_unknown_not_pass(): void {
		$result = $this->make_check( array( $this->txt( 'v=spf1 include:_spf.example.com -all' ) ), array( '_spf.example.com' => false ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'unknown', $result->status );
		$this->assertSame( array( '_spf.example.com' ), $result->raw['evaluation']['incomplete'] );
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

	public function test_evidence_contains_only_the_spf_record_text_and_include_domains(): void {
		$spf = 'v=spf1 include:_spf.example.com ~all';

		$result = $this->make_check( array( $this->txt( $spf ) ) )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( $spf . "\nIncludes/redirects: _spf.example.com", $result->evidence );
	}
}
