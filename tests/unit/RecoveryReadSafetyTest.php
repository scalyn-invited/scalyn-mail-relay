<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;
use Scalyn\MailRelay\Database\SubmissionAttemptRepository;

/** Models core readers discarding query(false), including failures before flush. */
final class RecoveryReadSafetyWpdb extends WpdbStub {
	public string $last_error = '';
	public string $mode = 'empty';
	public int $reads = 0;

	public function query( string $query ): int|false {
		if ( ! str_starts_with( $query, 'SELECT ' ) ) {
			return parent::query( $query );
		}
		++$this->reads;
		$this->queries[] = $query;
		if ( 'early' === $this->mode ) {
			return false;
		}
		$this->last_error = '';
		$this->last_result = array();
		if ( 'throw' === $this->mode ) {
			throw new RuntimeException( 'PRIVATE synthetic driver exception' );
		}
		if ( 'sql' === $this->mode ) {
			$this->last_error = 'PRIVATE synthetic SQL error';
			if ( ! $this->errors_suppressed ) {
				echo 'PRIVATE synthetic SQL error';
				error_log( 'PRIVATE synthetic SQL error' );
			}
			return false;
		}
		if ( 'malformed' === $this->mode ) {
			$this->last_result = array( null );
			return 1;
		}
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $this->get_results_return );
		return count( $this->last_result );
	}

	public function get_row( string $query, string $output = OBJECT ): array|object|null {
		$this->query( $query );
		return isset( $this->last_result[0] ) ? get_object_vars( $this->last_result[0] ) : null;
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		$this->query( $query );
		return array_map( 'get_object_vars', $this->last_result );
	}
}

final class RecoveryReadSafetyTest extends TestCase {
	private RecoveryReadSafetyWpdb $db;

	protected function setUp(): void {
		$GLOBALS['wpdb'] = $this->db = new RecoveryReadSafetyWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/** @dataProvider failures */
	public function test_claim_failure_is_private_and_restores_error_policy( string $mode, bool $suppressed ): void {
		$this->assert_private_failure( 'claim', $mode, $suppressed );
	}

	/** @dataProvider failures */
	public function test_submission_history_failure_is_private_and_restores_error_policy( string $mode, bool $suppressed ): void {
		$this->assert_private_failure( 'history', $mode, $suppressed );
	}

	private function assert_private_failure( string $reader, string $mode, bool $suppressed ): void {
		$this->db->mode = $mode;
		$this->db->errors_suppressed = $suppressed;
		$this->db->last_result = array( (object) array( 'logical_uuid' => 'PRIVATE stale row' ) );
		$log = tmpfile();
		$previous = ini_set( 'error_log', stream_get_meta_data( $log )['uri'] );
		$error = null;
		ob_start();
		try {
			try {
				if ( 'claim' === $reader ) {
					( new RecoveryQueueRepository() )->claim( 1793397435 );
				} else {
					( new SubmissionAttemptRepository() )->history( '11111111-1111-4111-8111-111111111111' );
				}
			} catch ( Throwable $caught ) {
				$error = $caught;
			}
			$output = ob_get_contents();
			rewind( $log );
			$logged = stream_get_contents( $log );
		} finally {
			ob_end_clean();
			ini_set( 'error_log', $previous );
			fclose( $log );
		}
		$this->assertSame( '', $output );
		$this->assertSame( '', $logged );
		$this->assertInstanceOf( RuntimeException::class, $error );
		$this->assertSame( 'claim' === $reader ? 'Recovery queue read is unavailable.' : 'Submission history is unavailable.', $error->getMessage() );
		$this->assertNull( $error->getPrevious() );
		$this->assertSame( $suppressed, $this->db->errors_suppressed );
		$this->assertSame( 1, $this->db->reads );
		$this->assertCount( 1, $this->db->queries, 'No claim or submission write after read failure.' );
	}

	public static function failures(): array {
		return array(
			array( 'early', false ), array( 'early', true ),
			array( 'sql', false ), array( 'sql', true ),
			array( 'throw', false ), array( 'throw', true ),
			array( 'malformed', false ), array( 'malformed', true ),
		);
	}

	/** @dataProvider successes */
	public function test_successful_reads_preserve_rows_and_error_policy( bool $suppressed, bool $populated ): void {
		$this->db->errors_suppressed = $suppressed;
		$this->db->last_error = 'PRIVATE stale error cleared by successful query';
		$row = array( 'logical_uuid' => '11111111-1111-4111-8111-111111111111', 'payload' => 'synthetic encrypted fixture', 'attempt_count' => 0 );
		$this->db->get_results_return = $populated ? array( $row ) : array();
		$result = ( new RecoveryQueueRepository() )->claim( 1793397435 );
		if ( $populated ) {
			$this->assertSame( $row['logical_uuid'], $result['logical_uuid'] );
			$this->assertSame( $row['payload'], $result['payload'] );
			$this->assertTrue( SubmissionAttemptRepository::uuid( $result['claim_token'] ) );
			$this->assertCount( 2, $this->db->queries );
		} else {
			$this->assertNull( $result );
			$this->assertCount( 1, $this->db->queries );
		}
		$this->assertSame( $suppressed, $this->db->errors_suppressed );
		$this->assertSame( '', $this->db->last_error );
		$this->db->queries = array();
		$metadata = array( 'attempt_uuid' => '22222222-2222-4222-8222-222222222222', 'status' => 'Prepared' );
		$this->db->get_results_return = $populated ? array( $metadata ) : array();
		$this->assertSame( $populated ? array( $metadata ) : array(), ( new SubmissionAttemptRepository() )->history( $row['logical_uuid'] ) );
		$this->assertSame( $suppressed, $this->db->errors_suppressed );
		$this->assertCount( 1, $this->db->queries );
		$this->assertStringNotContainsString( 'payload', $this->db->queries[0] );
		$this->assertStringContainsString( 'ORDER BY attempt_number LIMIT 3', $this->db->queries[0] );
		$this->assertSame( array( 'wp_scalyn_submission_attempts', $row['logical_uuid'] ), $this->db->prepare_calls[ count( $this->db->prepare_calls ) - 1 ]['args'] );
	}

	public static function successes(): array {
		return array( array( false, false ), array( false, true ), array( true, false ), array( true, true ) );
	}

	public function test_invalid_history_identifier_never_queries_or_changes_error_policy(): void {
		$this->assertSame( array(), ( new SubmissionAttemptRepository() )->history( 'not-a-uuid' ) );
		$this->assertSame( array(), $this->db->queries );
		$this->assertFalse( $this->db->errors_suppressed );
	}

	public function test_claim_early_false_cannot_look_like_empty_work(): void {
		$this->db->mode = 'early';
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Recovery queue read is unavailable.' );
		( new RecoveryQueueRepository() )->claim( 1793397435 );
	}
}
