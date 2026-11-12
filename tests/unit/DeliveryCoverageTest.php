<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Database\DeliveryCoverageRepository;
use Scalyn\MailRelay\Delivery\DeliveryCoverage;

final class CoverageDb extends WpdbStub {
	public string $last_error='';
	public bool $fail=false;
	public function get_row( string $query, string $output=OBJECT ): array|object|null {
		if ( $this->fail ) { $this->last_error='private SQL'; }
		return parent::get_row( $query, $output );
	}
}

final class DeliveryCoverageTest extends TestCase {
	private const MSG='aaaaaaaa-1111-4111-8111-111111111111';
	private CoverageDb $db;
	protected function setUp(): void {
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['wpdb']=$this->db=new CoverageDb();
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }
	private function coverage( bool $enabled=true ): DeliveryCoverage {
		if ( $enabled ) {
			$GLOBALS['_test_wp_options'][ PostmarkWebhookSettings::OPTION ]=[ 'id'=>'bbbbbbbb-2222-4222-8222-222222222222', 'credentials'=>'envelope', 'enabled'=>true ];
		}
		return new DeliveryCoverage( new DeliveryCoverageRepository(), new PostmarkWebhookSettings( new CredentialCipher( base64_encode( str_repeat( 'a', 32 ) ) ) ) );
	}
	private function tracked( int $expected, array $rows ): void {
		$this->db->get_row_return=[ 'expected_recipients'=>(string) $expected, 'created_at'=>'2026-10-03 00:00:00' ];
		$this->db->get_results_return=$rows;
		$this->db->get_var_return=$rows ? '2026-10-03 01:02:03.123456' : null;
	}

	public static function states(): array {
		return [
			'awaiting'            => [ 2, [], 'accepted', 'awaiting', 'No authenticated delivery or bounce report has been received yet for 2 tracked recipients.' ],
			'failed send'         => [ 1, [], 'failed', 'unavailable', 'The send attempt failed' ],
			'all delivered'       => [ 2, [ [ 'delivered'=>'1', 'bounced'=>'0' ], [ 'delivered'=>'1', 'bounced'=>'0' ] ], 'accepted', 'delivered', 'Delivery confirmed for 2 of 2 recipients. Recipient-server delivery does not prove inbox placement.' ],
			'partial'             => [ 3, [ [ 'delivered'=>'1', 'bounced'=>'0' ] ], 'accepted', 'partial', 'Delivery confirmed for 1 of 3 recipients; remaining outcomes unknown for 2.' ],
			'bounce only'         => [ 1, [ [ 'delivered'=>'0', 'bounced'=>'1' ] ], 'accepted', 'bounced', 'Delivery confirmed for 0 of 1 recipients; bounce reported for 1.' ],
			'mixed recipients'    => [ 2, [ [ 'delivered'=>'1', 'bounced'=>'0' ], [ 'delivered'=>'0', 'bounced'=>'1' ] ], 'accepted', 'mixed', 'Delivery confirmed for 1 of 2 recipients; bounce reported for 1.' ],
			'contradictory facts' => [ 1, [ [ 'delivered'=>'1', 'bounced'=>'1' ] ], 'accepted', 'mixed', 'both delivery and bounce reported for 1' ],
		];
	}

	/** @dataProvider states */
	public function test_tracked_attempts_report_explicit_coverage( int $expected, array $rows, string $status, string $state, string $text ): void {
		$this->tracked( $expected, $rows );
		$summary=$this->coverage()->summarize( self::MSG, $status );
		$this->assertSame( $state, $summary['state'] );
		$this->assertStringContainsString( $text, $summary['explanation'] );
		$this->assertSame( $expected, $summary['expected'] );
	}

	public function test_untracked_messages_distinguish_not_enabled_from_unavailable(): void {
		$this->assertSame( 'not_enabled', $this->coverage( false )->summarize( self::MSG, 'accepted' )['state'] );
		$summary=$this->coverage()->summarize( self::MSG, 'accepted' );
		$this->assertSame( 'unavailable', $summary['state'] );
		$this->assertStringContainsString( 'never inferred', $summary['explanation'] );
		$this->assertNull( $summary['expected'] );
	}

	public function test_storage_errors_are_safe_and_never_claim_delivery(): void {
		$this->tracked( 1, [ [ 'delivered'=>'1', 'bounced'=>'0' ] ] );
		$this->db->fail=true;
		$summary=$this->coverage()->summarize( self::MSG, 'accepted' );
		$this->assertSame( 'unavailable', $summary['state'] );
		$this->assertStringNotContainsString( 'SQL', $summary['explanation'] );
	}

	public function test_invalid_identifier_never_queries_storage(): void {
		$this->assertNull( ( new DeliveryCoverageRepository() )->find( "x' OR 1=1" ) );
		$this->assertSame( [], $this->db->prepare_calls );
	}
}
