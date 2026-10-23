<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;

/** In-memory evaluation of the prepared keyset predicate, not a real SQL engine. */
final class RecoveryCursorWpdb extends WpdbStub {
	public array $rows = array();
	public array $reads = array();
	public string $last_error = '';

	public function query( string $query ): int|false {
		$this->reads[] = $query;
		$args = end( $this->prepare_calls )['args'];
		$before = $args[1];
		$uuid = str_contains( $query, 'logical_uuid<%s' ) ? $args[3] : null;
		$rows = array_filter( $this->rows, static fn( $row ) => $row['created_at'] < $before || ( null !== $uuid && $row['created_at'] === $before && $row['logical_uuid'] < $uuid ) );
		usort( $rows, static fn( $left, $right ) => strcmp( $right['created_at'], $left['created_at'] ) ?: strcmp( $right['logical_uuid'], $left['logical_uuid'] ) );
		$this->last_error = '';
		$this->last_result = array_map( static fn( $row ) => (object) $row, array_slice( $rows, 0, 50 ) );
		return count( $this->last_result );
	}
}

final class RecoveryHistoryCursorTest extends TestCase {
	private RecoveryCursorWpdb $db;
	private const AT = '2026-10-21 01:00:00';

	protected function setUp(): void {
		$GLOBALS['wpdb'] = $this->db = new RecoveryCursorWpdb();
		for ( $id = 60; $id >= 1; --$id ) {
			$this->db->rows[] = array( 'created_at' => self::AT, 'logical_uuid' => sprintf( '11111111-1111-4111-8111-%012d', $id ) );
		}
		$this->db->rows[] = array( 'created_at' => '2026-10-20 01:00:00', 'logical_uuid' => '22222222-2222-4222-8222-222222222222' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/** @dataProvider invalid_cursors */
	public function test_invalid_cursor_is_rejected_before_query_without_reflecting_input( string $before, ?string $uuid ): void {
		$error = null;
		try {
			( new RecoveryQueueRepository() )->summaries( $before, $uuid );
		} catch ( InvalidArgumentException $caught ) {
			$error = $caught;
		}
		$this->assertInstanceOf( InvalidArgumentException::class, $error );
		$this->assertSame( 'Recovery history cursor is invalid.', $error->getMessage() );
		$this->assertSame( array(), $this->db->prepare_calls );
		$this->assertSame( array(), $this->db->reads );
	}

	public static function invalid_cursors(): array {
		return array(
			array( '', null ),
			array( 'PRIVATE cursor input', null ),
			array( '2026-02-29 01:00:00', null ),
			array( '2026-13-01 01:00:00', null ),
			array( '2026-10-32 01:00:00', null ),
			array( '2026-10-21 24:00:00', null ),
			array( '2026-10-21 01:00:60', null ),
			array( '2026-10-21T01:00:00Z', null ),
			array( '0000-00-00 00:00:00', null ),
			array( self::AT . ' PRIVATE', null ),
			array( self::AT, '' ),
			array( self::AT, 'PRIVATE UUID input' ),
			array( self::AT, '11111111-1111-1111-8111-111111111111' ),
			array( self::AT, 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA' ),
			array( self::AT, "11111111-1111-4111-8111-111111111111\n" ),
		);
	}

	public function test_legacy_timestamp_only_keeps_exclusive_timestamp_semantics(): void {
		$rows = ( new RecoveryQueueRepository() )->summaries( self::AT );
		$this->assertSame( array( end( $this->db->rows ) ), $rows );
		$this->assertSame( array( 'wp_scalyn_recovery_queue', self::AT ), $this->db->prepare_calls[0]['args'] );
	}

	public function test_valid_leap_day_and_empty_composite_page_are_supported(): void {
		$this->assertSame( array(), ( new RecoveryQueueRepository() )->summaries( '2024-02-29 23:59:59', '11111111-1111-4111-8111-111111111111' ) );
	}

	public function test_composite_cursor_retains_same_second_rows_across_page_boundary(): void {
		$repo = new RecoveryQueueRepository();
		$first = $repo->summaries();
		$this->assertCount( 50, $first );
		$last = end( $first );
		$second = $repo->summaries( $last['created_at'], $last['logical_uuid'] );
		$this->assertSame( $this->db->rows, array_merge( $first, $second ), 'No equal-timestamp rows may be skipped or repeated.' );
		$this->assertSame( array(), $repo->summaries( end( $second )['created_at'], end( $second )['logical_uuid'] ) );
		$this->assertStringContainsString( 'created_at<%s OR (created_at=%s AND logical_uuid<%s)', $this->db->reads[1] );
		$this->assertStringContainsString( 'ORDER BY created_at DESC,logical_uuid DESC LIMIT 50', $this->db->reads[1] );
		$this->assertStringStartsWith( 'SELECT logical_uuid,state,attempt_count,next_at,expires_at,created_at,reason FROM ', $this->db->reads[1] );
		$this->assertSame( array( 'wp_scalyn_recovery_queue', self::AT, self::AT, $last['logical_uuid'] ), $this->db->prepare_calls[1]['args'] );
	}
}
