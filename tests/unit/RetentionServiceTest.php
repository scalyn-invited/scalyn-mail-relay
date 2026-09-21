<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\RetentionService;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Database\RetentionStateRepository;
use Scalyn\MailRelay\Database\DiagnosticRetentionRepository;
use Scalyn\MailRelay\Logging\MailRetentionRepository;

final class RetentionServiceTest extends TestCase {
	private WpdbStub $db;
	private RetentionStateRepository $state;
	private RetentionService $service;

	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_cron'] = array();
		$GLOBALS['_test_current_time'] = '2026-09-21 12:00:00';
		$GLOBALS['_test_timezone'] = 'Asia/Manila';
		$this->db = new WpdbStub();
		$this->db->get_var_return = '1';
		$GLOBALS['wpdb'] = $this->db;
		$this->state = new RetentionStateRepository();
		$this->service = new RetentionService( new SettingsRepository(), new MailRetentionRepository(), new DiagnosticRetentionRepository(), $this->state, new \Scalyn\MailRelay\Audit\AuditRepository() );
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_time'] = null;
		unset( $GLOBALS['_test_timezone'] );
	}

	public function test_schedule_is_idempotent_and_does_not_delete(): void {
		RetentionService::ensure_scheduled();
		$first = $GLOBALS['_test_wp_cron'];
		RetentionService::ensure_scheduled();
		$this->assertSame( $first, $GLOBALS['_test_wp_cron'] );
		$this->assertSame( array( ScheduledHooks::CLEANUP ), array_keys( $first ) );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_busy_or_unavailable_lock_does_not_touch_histories_or_status(): void {
		foreach ( array( '0', null ) as $response ) {
			$this->db->get_var_return = $response;
			$this->service->run();
			$this->assertSame( array(), $this->db->queries );
			$this->assertSame( 'never', $this->state->get()['state'] );
		}
	}

	public function test_empty_histories_complete_with_a_single_site_time_cutoff(): void {
		$this->service->run();
		$status = $this->state->get();
		$this->assertSame( 'complete', $status['state'] );
		$this->assertGreaterThan( 0, $status['last_success_at'] );
		$this->assertSame( 0, $status['mail_logs'] );
		$cutoffs = array_filter( $this->db->prepare_calls, fn( $call ) => str_contains( $call['query'], 'created_at' ) );
		$this->assertCount( 4, $cutoffs );
		foreach ( $cutoffs as $call ) {
			$this->assertSame( '2026-08-22 12:00:00', $call['args'][0] );
			if (isset($call['args'][1])) { $this->assertSame( 100, $call['args'][1] ); }
		}
		$this->assertSame( 3, count( array_filter( $this->db->queries, fn( $q ) => 'COMMIT' === $q ) ) );
	}

	public function test_failed_batch_records_safe_failure_and_next_tick_resumes(): void {
		$this->db->query_returns = array( false );
		$this->service->run();
		$this->assertSame( 'failed', $this->state->get()['state'] );
		$this->assertSame( 0, $this->state->get()['last_success_at'] );
		$this->db->query_returns = array();
		$this->service->run();
		$this->assertSame( 'complete', $this->state->get()['state'] );
	}

	public function test_diagnostics_failure_keeps_committed_mail_counts(): void {
		$this->db->get_col_returns = array( array( 'message-uuid' ) );
		$this->db->query_returns = array( 0, 3, 1, 0, false );
		$this->service->run();
		$this->assertSame( 'failed', $this->state->get()['state'] );
		$this->assertSame( 1, $this->state->get()['mail_logs'] );
		$this->assertSame( 3, $this->state->get()['timeline_events'] );
	}

	public function test_full_batch_reports_possible_remaining_work_without_looping(): void {
		$this->db->get_col_returns = array( array_map( fn( $n ) => 'uuid-' . $n, range( 1, 100 ) ) );
		$this->db->query_returns = array( 0, 100, 100, 0, 0, 0 );
		$this->service->run();
		$this->assertSame( 'more_pending', $this->state->get()['state'] );
		$this->assertSame( 100, $this->state->get()['mail_logs'] );
		$this->assertSame( 3, count( array_filter( $this->db->queries, fn( $q ) => 'START TRANSACTION' === $q ) ) );
	}

	public function test_state_excludes_unknown_keys_and_unsafe_strings(): void {
		$this->state->save( array( 'state' => 'password=secret', 'exception' => 'secret', 'mail_logs' => -3, 'started_at' => 'secret' ) );
		$this->assertStringNotContainsString( 'secret', json_encode( $this->state->get() ) );
		$this->assertSame( 0, $this->state->get()['mail_logs'] );
	}

	public function test_legacy_daily_schedule_is_replaced(): void {
		wp_schedule_event( 123, 'daily', ScheduledHooks::CLEANUP );
		RetentionService::ensure_scheduled();
		$this->assertSame( 'hourly', wp_get_schedule( ScheduledHooks::CLEANUP ) );
		$this->assertGreaterThan( time(), wp_next_scheduled( ScheduledHooks::CLEANUP ) );
	}

	public function test_database_selection_error_is_not_an_empty_success(): void {
		$db = new class extends WpdbStub {
			public string $last_error = '';
			public function get_col( string $query ): array {
				$this->last_error = 'private SQL details';
				return array();
			}
		};
		$db->get_var_return = '1';
		$GLOBALS['wpdb'] = $db;
		$this->service->run();
		$this->assertSame( 'failed', $this->state->get()['state'] );
		$this->assertContains( 'ROLLBACK', $db->queries );
		$this->assertStringNotContainsString( 'private', json_encode( $this->state->get() ) );
	}

	public function test_diagnostic_selection_error_rolls_back_without_false_completion(): void {
		$db = new class extends WpdbStub {
			public string $last_error = '';
			public function get_col( string $query ): array {
				if ( str_contains( $query, 'scalyn_diagnostics' ) ) {
					$this->last_error = 'private SQL details';
				}
				return array();
			}
		};
		$db->get_var_return = '1';
		$GLOBALS['wpdb'] = $db;
		$this->service->run();
		$this->assertSame( 'failed', $this->state->get()['state'] );
		$this->assertContains( 'COMMIT', $db->queries );
		$this->assertContains( 'ROLLBACK', $db->queries );
	}
}
