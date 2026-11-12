<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Logging\MailRetentionRepository;

/** Tests the bounded, transactional mail-retention repository contract. */
final class MailRetentionRepositoryTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new WpdbStub();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_rejects_invalid_cutoff_before_starting_transaction(): void {
		$this->expectException( \InvalidArgumentException::class );

		try {
			( new MailRetentionRepository() )->delete_expired_batch( '2026-02-30 00:00:00' );
		} finally {
			$this->assertSame( array(), $this->wpdb->queries );
		}
	}

	public function test_empty_batch_commits_and_returns_zero_counts(): void {
		$result = ( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );

		$this->assertSame( 0, $result->selected_messages );
		$this->assertSame( 0, $result->deleted_timeline_events );
		$this->assertSame( 0, $result->deleted_mail_logs );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->wpdb->queries );
	}

	public function test_selection_uses_exclusive_cutoff_oldest_first_and_row_lock(): void {
		( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 20 );

		$prepare = $this->wpdb->prepare_calls[0];
		$this->assertStringContainsString( 'created_at < %s', $prepare['query'] );
		$this->assertStringContainsString( 'created_at ASC, id ASC', $prepare['query'] );
		$this->assertStringContainsString( 'LIMIT %d FOR UPDATE', $prepare['query'] );
		$this->assertSame( array( '2026-08-01 00:00:00', 20 ), $prepare['args'] );
	}

	public function test_batch_size_is_clamped_to_repository_maximum(): void {
		( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 9999 );

		$this->assertSame( MailRetentionRepository::MAX_BATCH_SIZE, $this->wpdb->prepare_calls[0]['args'][1] );
	}

	public function test_batch_size_is_clamped_to_one(): void {
		( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 0 );

		$this->assertSame( 1, $this->wpdb->prepare_calls[0]['args'][1] );
	}

	public function test_deletes_related_timeline_before_expired_mail_logs_and_commits(): void {
		$this->wpdb->get_col_return = array( 'uuid-old-1', 'uuid-old-2' );
		$this->wpdb->query_returns  = array( 1, 3, 2, 1 );

		$result = ( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 2 );

		$this->assertStringContainsString( 'scalyn_mail_timeline', $this->wpdb->queries[1] );
		$this->assertStringContainsString( 'scalyn_mail_logs', $this->wpdb->queries[2] );
		$this->assertStringContainsString( 'created_at < %s', $this->wpdb->queries[2] );
		$this->assertSame( 'COMMIT', $this->wpdb->queries[3] );
		$this->assertSame( 2, $result->selected_messages );
		$this->assertSame( 3, $result->deleted_timeline_events );
		$this->assertSame( 2, $result->deleted_mail_logs );
		$this->assertSame( '2026-08-01 00:00:00', $result->cutoff );
	}

	public function test_all_selected_uuids_are_prepared_for_both_deletes(): void {
		$this->wpdb->get_col_return = array( 'uuid-old-1', 'uuid-old-2' );
		$this->wpdb->query_returns  = array( 1, 2, 2, 1 );

		( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 2 );

		$this->assertSame( array( 'uuid-old-1', 'uuid-old-2' ), $this->wpdb->prepare_calls[1]['args'] );
		$this->assertSame(
			array( 'uuid-old-1', 'uuid-old-2', '2026-08-01 00:00:00' ),
			$this->wpdb->prepare_calls[2]['args']
		);
	}

	public function test_timeline_delete_failure_rolls_back_without_deleting_logs(): void {
		$this->wpdb->get_col_return = array( 'uuid-old-1' );
		$this->wpdb->query_returns  = array( 1, false, 1 );

		try {
			( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
			$this->fail( 'Expected RuntimeException.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Mail timeline retention delete failed.', $error->getMessage() );
		}

		$this->assertCount( 3, $this->wpdb->queries );
		$this->assertSame( 'ROLLBACK', $this->wpdb->queries[2] );
	}

	public function test_mail_log_delete_failure_rolls_back(): void {
		$this->wpdb->get_col_return = array( 'uuid-old-1' );
		$this->wpdb->query_returns  = array( 1, 2, false, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Mail log retention delete failed.' );

		try {
			( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_partial_parent_delete_rolls_back_instead_of_reporting_success(): void {
		$this->wpdb->get_col_return = array( 'uuid-old-1', 'uuid-old-2' );
		$this->wpdb->query_returns  = array( 1, 2, 1, 1 );

		$this->expectException( \RuntimeException::class );

		try {
			( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_transaction_start_failure_returns_safe_error_without_sql_details(): void {
		$this->wpdb->query_returns = array( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Mail retention transaction could not start.' );

		( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
	}

	public function test_commit_failure_rolls_back_and_returns_safe_error(): void {
		$this->wpdb->query_returns = array( 1, false, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Mail retention transaction could not commit.' );

		try {
			( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_unexpected_database_exception_is_normalized_without_sql_details(): void {
		$this->wpdb = new class() extends WpdbStub {
			private int $query_count = 0;

			public function query( string $query ): int|false {
				$this->queries[] = $query;
				++$this->query_count;
				if ( 2 === $this->query_count ) {
					throw new \RuntimeException( 'Sensitive SQL driver detail.' );
				}
				return 1;
			}
		};
		$this->wpdb->get_col_return = array( 'uuid-old-1' );
		$GLOBALS['wpdb']             = $this->wpdb;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Mail retention cleanup failed.' );

		try {
			( new MailRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_failed_batch_can_be_retried_with_same_cutoff(): void {
		$repository                = new MailRetentionRepository();
		$this->wpdb->get_col_return = array( 'uuid-old-1' );
		$this->wpdb->query_returns  = array( 1, false, 1 );

		try {
			$repository->delete_expired_batch( '2026-08-01 00:00:00' );
			$this->fail( 'Expected the first attempt to fail.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Mail timeline retention delete failed.', $error->getMessage() );
		}

		$this->wpdb->query_returns = array( 1, 2, 1, 1 );
		$result                    = $repository->delete_expired_batch( '2026-08-01 00:00:00' );

		$this->assertSame( 1, $result->selected_messages );
		$this->assertSame( 1, $result->deleted_mail_logs );
		$this->assertSame( 'COMMIT', end( $this->wpdb->queries ) );
	}

	public function test_later_invocation_resumes_with_next_oldest_candidate(): void {
		$repository                 = new MailRetentionRepository();
		$this->wpdb->get_col_return = array( 'uuid-old-1' );
		$this->wpdb->query_returns  = array( 1, 1, 1, 1 );
		$first                      = $repository->delete_expired_batch( '2026-08-01 00:00:00', 1 );

		$this->wpdb->get_col_return = array( 'uuid-old-2' );
		$this->wpdb->query_returns  = array( 1, 1, 1, 1 );
		$second                     = $repository->delete_expired_batch( '2026-08-01 00:00:00', 1 );

		$this->assertSame( 1, $first->selected_messages );
		$this->assertSame( 1, $second->selected_messages );
		$this->assertContains( 'uuid-old-1', $this->wpdb->prepare_calls[1]['args'] );
		$this->assertContains( 'uuid-old-2', $this->wpdb->prepare_calls[4]['args'] );
	}
}
