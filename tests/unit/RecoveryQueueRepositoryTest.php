<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;

final class RecoveryClaimWpdb extends WpdbStub {
	public string $last_error = '';
	public bool $read_failure = false;
	public bool $throw_read = false;
	public bool $early_failure = false;

	public function query( string $query ): int|false {
		if ( ! str_starts_with( $query, 'SELECT ' ) ) {
			return parent::query( $query );
		}
		$this->queries[] = $query;
		if ( $this->early_failure ) {
			return false; // Native wpdb can fail before clearing error/result state.
		}
		if ( $this->throw_read ) {
			throw new RuntimeException( 'PRIVATE simulated read exception' );
		}
		// Like wpdb, each executed read replaces the previous query's error state.
		$this->last_error = $this->read_failure ? 'PRIVATE simulated SQL failure and connection details' : '';
		$this->last_result = $this->read_failure || null === $this->get_row_return ? array() : array( (object) $this->get_row_return );
		return $this->read_failure ? false : count( $this->last_result );
	}
}

final class RecoveryQueueRepositoryTest extends TestCase {
	private RecoveryClaimWpdb $db;

	protected function setUp(): void {
		$GLOBALS['wpdb'] = $this->db = new RecoveryClaimWpdb();
		$GLOBALS['_test_wp_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['_test_wp_options'] = array();
	}

	public function test_failed_candidate_read_throws_safe_error_before_any_claim_write(): void {
		$this->db->read_failure = true;
		$error = null;
		try {
			( new RecoveryQueueRepository() )->claim( 1792540800 );
		} catch ( RuntimeException $caught ) {
			$error = $caught;
		}
		$this->assertInstanceOf( RuntimeException::class, $error, 'A failed read must not look like an empty queue.' );
		$this->assertSame( 'Recovery queue read is unavailable.', $error->getMessage() );
		$this->assertStringNotContainsString( 'PRIVATE', $error->getMessage() );
		$this->assertCount( 1, $this->db->queries );
		$this->assertStringStartsWith( 'SELECT ', $this->db->queries[0] );
		$this->assertSame( array(), $this->db->inserts );
		$this->assertSame( array(), $this->db->updates );
	}

	private function worker_log(): string {
		$GLOBALS['_test_wp_options'][\Scalyn\MailRelay\Recovery\RecoveryPolicyRepository::OPTION] = array( 'enabled' => true );
		$cipher = new \Scalyn\MailRelay\Core\CredentialCipher( base64_encode( str_repeat( 'q', 32 ) ) );
		$settings = new \Scalyn\MailRelay\Core\SettingsRepository( $cipher );
		$provider = $this->createMock( \Scalyn\MailRelay\Contracts\ProviderInterface::class );
		$provider->method( 'get_id' )->willReturn( 'smtp' );
		$provider->expects( $this->never() )->method( 'send' );
		$registry = new \Scalyn\MailRelay\Core\ProviderRegistry();
		$registry->register( $provider );
		$service = new \Scalyn\MailRelay\Recovery\RecoveryService(
			new RecoveryQueueRepository(),
			new \Scalyn\MailRelay\Recovery\RecoveryPolicyRepository(),
			new \Scalyn\MailRelay\Recovery\RecoveryPayload( $cipher ),
			new \Scalyn\MailRelay\Mail\MailDispatcher( $registry, $settings ),
			$settings
		);
		$handle = tmpfile();
		$previous = ini_set( 'error_log', stream_get_meta_data( $handle )['uri'] );
		try {
			$service->tick();
			rewind( $handle );
			return stream_get_contents( $handle );
		} finally {
			ini_set( 'error_log', $previous );
			fclose( $handle );
		}
	}

	private function assert_only_sweep_writes(): void {
		$this->assertCount( 3, $this->db->queries );
		$this->assertStringStartsWith( 'SELECT ', $this->db->queries[2] );
		$this->assertStringContainsString( "SET state=%s,reason=%s,payload='',claim_token=NULL", $this->db->queries[0] );
		$this->assertStringContainsString( 'WHERE state IN (%s,%s) AND claimed_at<=%s LIMIT 100', $this->db->queries[0] );
		$this->assertStringContainsString( "SET state=CASE WHEN state IN ('running','submitting')", $this->db->queries[1] );
		$this->assertStringContainsString( "WHERE expires_at<=%s AND payload<>'' LIMIT 100", $this->db->queries[1] );
	}

	public function test_worker_reports_thrown_read_without_claiming_or_leaking_exception(): void {
		$this->db->throw_read = true;
		$log = $this->worker_log();
		$this->assertStringContainsString( 'Scalyn Mail Relay: recovery worker could not complete.', $log );
		$this->assertStringNotContainsString( 'PRIVATE', $log );
		$this->assert_only_sweep_writes();
		$this->assertSame( array(), $this->db->inserts );
		$this->assertSame( array(), $this->db->updates );
	}

	public function test_worker_reports_failed_read_without_claiming_submitting_or_leaking_sql(): void {
		$this->db->read_failure = true;
		$log = $this->worker_log();
		$this->assertStringContainsString( 'Scalyn Mail Relay: recovery worker could not complete. Check recovery storage and review retained work.', $log );
		$this->assertStringNotContainsString( 'PRIVATE', $log );
		$this->assertSame( 1, substr_count( $log, 'Scalyn Mail Relay:' ) );
		// Only the existing bounded sweep queries are allowed, not a claim/finalize/retry.
		$this->assert_only_sweep_writes();
		$this->assertSame( array(), $this->db->inserts );
		$this->assertSame( array(), $this->db->updates );
	}

	public function test_worker_reports_early_read_failure_without_claiming_or_sending(): void {
		$this->db->early_failure = true;
		$this->db->last_result = array( (object) array( 'logical_uuid' => 'PRIVATE stale row' ) );
		$log = $this->worker_log();
		$this->assertStringContainsString( 'Scalyn Mail Relay: recovery worker could not complete.', $log );
		$this->assertStringNotContainsString( 'PRIVATE', $log );
		$this->assert_only_sweep_writes();
		$this->assertFalse( $this->db->errors_suppressed );
		$this->assertSame( array(), $this->db->inserts );
		$this->assertSame( array(), $this->db->updates );
	}

	public function test_worker_empty_queue_is_silent_and_does_not_submit(): void {
		$this->assertSame( '', $this->worker_log() );
		$this->assert_only_sweep_writes();
		$this->assertSame( array(), $this->db->inserts );
	}

	public function test_successful_empty_read_returns_null_without_claiming(): void {
		$this->assertNull( ( new RecoveryQueueRepository() )->claim( 1792540800 ) );
		$this->assertCount( 1, $this->db->queries );
		$this->assertStringStartsWith( 'SELECT ', $this->db->queries[0] );
	}

	public function test_previous_error_does_not_taint_successful_empty_read(): void {
		$this->db->last_error = 'PRIVATE previous query';
		$this->assertNull( ( new RecoveryQueueRepository() )->claim( 1792540800 ) );
		$this->assertSame( '', $this->db->last_error );
	}

	public function test_successful_candidate_read_still_requires_compare_and_set_claim(): void {
		$this->db->get_row_return = array( 'logical_uuid' => '11111111-1111-4111-8111-111111111111', 'payload' => 'synthetic encrypted content', 'attempt_count' => 0 );
		$row = ( new RecoveryQueueRepository() )->claim( 1792540800 );
		$this->assertSame( $this->db->get_row_return['logical_uuid'], $row['logical_uuid'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/', $row['claim_token'] );
		$this->assertCount( 2, $this->db->queries );
		$this->assertStringStartsWith( 'SELECT ', $this->db->queries[0] );
		$this->assertStringStartsWith( 'UPDATE ', $this->db->queries[1] );
		$this->assertStringContainsString( 'WHERE logical_uuid=%s AND state=%s', $this->db->queries[1] );
		$this->assertStringNotContainsString( "payload=''", $this->db->queries[1] );
	}

	public function test_lost_claim_race_returns_null_without_retrying(): void {
		$this->db->get_row_return = array( 'logical_uuid' => '11111111-1111-4111-8111-111111111111' );
		$this->db->query_returns = array( 0 );
		$this->assertNull( ( new RecoveryQueueRepository() )->claim( 1792540800 ) );
		$this->assertCount( 2, $this->db->queries );
		$this->assertStringStartsWith( 'UPDATE ', $this->db->queries[1] );
	}
}
