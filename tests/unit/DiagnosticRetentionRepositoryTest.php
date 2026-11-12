<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DiagnosticRetentionRepository;

/** Tests grouped diagnostic and independent health-score retention. */
final class DiagnosticRetentionRepositoryTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new WpdbStub();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_rejects_invalid_cutoff_before_transaction(): void {
		$this->expectException( \InvalidArgumentException::class );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-02-30 00:00:00' );
		} finally {
			$this->assertSame( array(), $this->wpdb->queries );
		}
	}

	public function test_empty_histories_commit_with_zero_counts(): void {
		$this->wpdb->get_col_returns = array( array(), array() );

		$result = ( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );

		$this->assertSame( 0, $result->selected_runs );
		$this->assertSame( 0, $result->deleted_diagnostic_rows );
		$this->assertSame( 0, $result->selected_health_scores );
		$this->assertSame( 0, $result->deleted_health_scores );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->wpdb->queries );
	}

	public function test_diagnostic_selection_expires_only_whole_groups(): void {
		$this->wpdb->get_col_returns = array( array(), array() );

		( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 20 );

		$query = $this->wpdb->prepare_calls[0];
		$this->assertStringContainsString( 'GROUP BY diagnostic_uuid', $query['query'] );
		$this->assertStringContainsString( 'HAVING MAX(created_at) < %s', $query['query'] );
		$this->assertStringContainsString( 'MIN(created_at) ASC, MIN(id) ASC', $query['query'] );
		$this->assertSame( array( '2026-08-01 00:00:00', 20 ), $query['args'] );
	}

	public function test_health_selection_is_independently_bounded_and_locked(): void {
		$this->wpdb->get_col_returns = array( array(), array() );

		( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 999 );

		$query = $this->wpdb->prepare_calls[1];
		$this->assertStringContainsString( 'created_at < %s', $query['query'] );
		$this->assertStringContainsString( 'LIMIT %d FOR UPDATE', $query['query'] );
		$this->assertSame( DiagnosticRetentionRepository::MAX_BATCH_SIZE, $query['args'][1] );
	}

	public function test_deletes_complete_runs_and_selected_health_snapshots_in_one_transaction(): void {
		$this->wpdb->get_col_returns = array(
			array( 'run-old-1', 'run-old-2' ),
			array( '1', '2', '3', '4' ),
			array( '10', '11' ),
		);
		$this->wpdb->query_returns = array( 1, 4, 2, 1 );

		$result = ( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00', 2 );

		$this->assertStringContainsString( 'scalyn_diagnostics', $this->wpdb->queries[1] );
		$this->assertStringContainsString( 'scalyn_health_scores', $this->wpdb->queries[2] );
		$this->assertSame( 'COMMIT', $this->wpdb->queries[3] );
		$this->assertSame( 2, $result->selected_runs );
		$this->assertSame( 4, $result->deleted_diagnostic_rows );
		$this->assertSame( 2, $result->selected_health_scores );
		$this->assertSame( 2, $result->deleted_health_scores );
	}

	public function test_selected_run_rows_are_locked_before_deletion(): void {
		$this->wpdb->get_col_returns = array( array( 'run-old-1' ), array( '1', '2' ), array() );
		$this->wpdb->query_returns   = array( 1, 2, 1 );

		( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );

		$this->assertStringContainsString( 'diagnostic_uuid IN', $this->wpdb->prepare_calls[1]['query'] );
		$this->assertStringContainsString( 'FOR UPDATE', $this->wpdb->prepare_calls[1]['query'] );
		$this->assertSame( array( 'run-old-1' ), $this->wpdb->prepare_calls[1]['args'] );
	}

	public function test_group_lock_mismatch_rolls_back_before_deletion(): void {
		$this->wpdb->get_col_returns = array( array( 'run-old-1', 'run-old-2' ), array( '1' ) );
		$this->wpdb->query_returns   = array( 1, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Diagnostic retention group lock failed.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->wpdb->queries );
		}
	}

	public function test_diagnostic_delete_failure_rolls_back_health_work(): void {
		$this->wpdb->get_col_returns = array( array( 'run-old-1' ), array( '1' ), array( '10' ) );
		$this->wpdb->query_returns   = array( 1, false, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Diagnostic retention delete failed.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_partial_diagnostic_row_delete_rolls_back_complete_group(): void {
		$this->wpdb->get_col_returns = array( array( 'run-old-1' ), array( '1', '2', '3' ), array() );
		$this->wpdb->query_returns   = array( 1, 2, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Diagnostic retention delete failed.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_health_delete_failure_rolls_back_diagnostic_delete(): void {
		$this->wpdb->get_col_returns = array( array( 'run-old-1' ), array( '1', '2' ), array( '10' ) );
		$this->wpdb->query_returns   = array( 1, 2, false, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Health score retention delete failed.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_health_delete_repeats_cutoff_and_requires_exact_count(): void {
		$this->wpdb->get_col_returns = array( array(), array( '10', '11' ) );
		$this->wpdb->query_returns   = array( 1, 1, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Health score retention delete failed.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertContains( '2026-08-01 00:00:00', $this->wpdb->prepare_calls[2]['args'] );
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_health_history_can_be_deleted_without_diagnostic_runs(): void {
		$this->wpdb->get_col_returns = array( array(), array( '10' ) );
		$this->wpdb->query_returns   = array( 1, 1, 1 );

		$result = ( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );

		$this->assertSame( 0, $result->selected_runs );
		$this->assertSame( 1, $result->selected_health_scores );
		$this->assertSame( 1, $result->deleted_health_scores );
	}

	public function test_diagnostic_runs_can_be_deleted_without_health_history(): void {
		$this->wpdb->get_col_returns = array( array( 'run-old-1' ), array( '1', '2' ), array() );
		$this->wpdb->query_returns   = array( 1, 2, 1 );

		$result = ( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );

		$this->assertSame( 1, $result->selected_runs );
		$this->assertSame( 2, $result->deleted_diagnostic_rows );
		$this->assertSame( 0, $result->selected_health_scores );
	}

	public function test_transaction_start_failure_returns_fixed_safe_error(): void {
		$this->wpdb->query_returns = array( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Diagnostic retention transaction could not start.' );

		( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
	}

	public function test_commit_failure_rolls_back_with_fixed_safe_error(): void {
		$this->wpdb->get_col_returns = array( array(), array() );
		$this->wpdb->query_returns   = array( 1, false, 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Diagnostic retention transaction could not commit.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}

	public function test_unexpected_database_error_is_normalized(): void {
		$this->wpdb = new class() extends WpdbStub {
			public function get_col( string $query ): array {
				throw new \RuntimeException( 'Sensitive database detail.' );
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Diagnostic retention cleanup failed.' );

		try {
			( new DiagnosticRetentionRepository() )->delete_expired_batch( '2026-08-01 00:00:00' );
		} finally {
			$this->assertSame( 'ROLLBACK', end( $this->wpdb->queries ) );
		}
	}
}
