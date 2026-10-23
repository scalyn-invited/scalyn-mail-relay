<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Components\RecoveryPanel;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;

/** Models wpdb query return/result semantics; real-core checks live in tests/manual. */
final class RecoveryHistoryWpdb extends WpdbStub {
	public string $last_error = '';
	public array $last_result = array();
	public string $mode = 'empty';
	public bool $show_errors = true;
	public int $history_reads = 0;

	public function query( string $query ): int|false {
		++$this->history_reads;
		$this->queries[] = $query;
		if ( 'early' === $this->mode ) {
			return false; // No flush, no last_error, possibly stale last_result.
		}
		$this->last_error = '';
		$this->last_result = array();
		if ( 'throw' === $this->mode ) {
			throw new RuntimeException( 'PRIVATE driver failure' );
		}
		if ( 'sql' === $this->mode ) {
			$this->last_error = 'PRIVATE SQL connection details';
			if ( $this->show_errors && ! $this->errors_suppressed ) {
				echo 'PRIVATE SQL connection details ' . $query;
			}
			return false;
		}
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $this->get_results_return );
		return count( $this->last_result );
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		$this->query( $query ); // Like WP 6.8, deliberately discards false.
		return array_map( 'get_object_vars', $this->last_result );
	}
}

final class RecoveryHistoryReadTest extends TestCase {
	private RecoveryHistoryWpdb $db;

	protected function setUp(): void {
		$GLOBALS['wpdb'] = $this->db = new RecoveryHistoryWpdb();
		$GLOBALS['_test_wp_options'] = array( 'scalyn_mail_relay_db_version' => '0.13.0' );
		$GLOBALS['_test_current_user_can'] = array( Capabilities::MANAGE_SETTINGS => true );
		$_GET = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_current_user_can'] = array();
		$_GET = array();
	}

	private function panel(): string {
		ob_start();
		try {
			RecoveryPanel::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** @dataProvider failures */
	public function test_failure_is_safe_and_restores_error_state( string $mode, bool $suppressed ): void {
		$this->db->mode = $mode;
		$this->db->errors_suppressed = $suppressed;
		$this->db->last_result = array( (object) array( 'reason' => 'PRIVATE stale result' ) );
		$error = null;
		ob_start();
		try {
			try {
				( new RecoveryQueueRepository() )->summaries();
			} catch ( RuntimeException $caught ) {
				$error = $caught;
			}
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertSame( '', $output, 'wpdb must not print SQL or driver errors.' );
		$this->assertInstanceOf( RuntimeException::class, $error );
		$this->assertSame( 'Recovery history is unavailable.', $error->getMessage() );
		$this->assertNull( $error->getPrevious(), 'Do not retain private driver exceptions.' );
		$this->assertSame( $suppressed, $this->db->errors_suppressed );
		$this->assertTrue( $this->db->show_errors );
		$this->assertSame( 1, $this->db->history_reads );
	}

	public static function failures(): array {
		return array(
			array( 'early', false ), array( 'early', true ),
			array( 'sql', false ), array( 'sql', true ),
			array( 'throw', false ), array( 'throw', true ),
		);
	}

	/** @dataProvider successes */
	public function test_success_preserves_rows_and_error_state( array $rows, bool $suppressed ): void {
		$this->db->get_results_return = $rows;
		$this->db->errors_suppressed = $suppressed;
		$this->db->last_error = 'Old unrelated error cleared by query';
		$this->assertSame( $rows, ( new RecoveryQueueRepository() )->summaries() );
		$this->assertSame( '', $this->db->last_error );
		$this->assertSame( $suppressed, $this->db->errors_suppressed );
		$this->assertTrue( $this->db->show_errors );
		$this->assertSame( 1, $this->db->history_reads );
		$this->assertCount( 1, $this->db->prepare_calls );
		$this->assertSame( array( 'wp_scalyn_recovery_queue', '9999-12-31 23:59:59' ), $this->db->prepare_calls[0]['args'] );
		$this->assertSame( 'SELECT logical_uuid,state,attempt_count,next_at,expires_at,created_at,reason FROM %i WHERE created_at<%s ORDER BY created_at DESC,logical_uuid DESC LIMIT 50', $this->db->prepare_calls[0]['query'] );
	}

	public static function successes(): array {
		$row = array( 'logical_uuid' => '11111111-1111-4111-8111-111111111111', 'state' => 'failed', 'attempt_count' => '1', 'next_at' => null, 'expires_at' => '2026-10-22 00:00:00', 'created_at' => '2026-10-21 00:00:00', 'reason' => 'unconfirmed' );
		return array( array( array(), false ), array( array(), true ), array( array( $row ), false ), array( array( $row ), true ) );
	}

	/** @dataProvider panel_failures */
	public function test_panel_shows_safe_unavailable_not_empty( string $mode ): void {
		$this->db->mode = $mode;
		$html = $this->panel();
		$this->assertSame( 1, $this->db->history_reads );
		$this->assertStringContainsString( 'Recovery storage is unavailable', $html );
		$this->assertStringNotContainsString( 'No retained recovery work.', $html );
		$this->assertStringNotContainsString( 'PRIVATE', $html );
		$this->assertStringNotContainsString( 'SELECT ', $html );
		$this->assertFalse( $this->db->errors_suppressed );
	}

	public static function panel_failures(): array {
		return array( array( 'early' ), array( 'sql' ), array( 'throw' ) );
	}

	public function test_base_stub_select_populates_object_rows_for_history_read(): void {
		$GLOBALS['wpdb'] = $db = new WpdbStub();
		$rows = self::successes()[2][0];
		$db->get_results_return = $rows;
		$this->assertSame( 1, $db->query( 'SELECT logical_uuid FROM wp_scalyn_recovery_queue' ) );
		$this->assertTrue( property_exists( $db, 'last_result' ), 'The shared stub must expose wpdb SELECT results.' );
		$this->assertInstanceOf( stdClass::class, $db->last_result[0] );
		$this->assertSame( $rows[0], get_object_vars( $db->last_result[0] ) );
		$this->assertSame( $rows, ( new RecoveryQueueRepository() )->summaries() );
		$this->assertFalse( $db->errors_suppressed );
		$db->get_results_return = array();
		$this->assertSame( 0, $db->query( 'SELECT logical_uuid FROM wp_scalyn_recovery_queue' ) );
		$this->assertSame( array(), $db->last_result, 'Empty SELECT must replace stale populated results.' );
		$this->assertSame( array(), ( new RecoveryQueueRepository() )->summaries() );
		$db->query_returns = array( false );
		$this->assertFalse( $db->query( 'SELECT logical_uuid FROM wp_scalyn_recovery_queue' ) );
		$this->assertSame( 1, $db->query( 'UPDATE wp_scalyn_recovery_queue SET state=state' ) );
	}

	public function test_panel_shows_empty_history_after_successful_empty_read(): void {
		$html = $this->panel();
		$this->assertSame( 1, $this->db->history_reads );
		$this->assertStringContainsString( 'No retained recovery work.', $html );
		$this->assertStringNotContainsString( 'Recovery storage is unavailable', $html );
	}
}
