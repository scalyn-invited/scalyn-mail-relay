<?php
// Explicit fourth-argument marker writer uses the mocked test add_option backend, not native wpdb.


use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\{Capabilities, CredentialCipher, LocalCredentialKeyStore, ProviderRegistry, SettingsRepository};
use Scalyn\MailRelay\Database\{RecoveryQueueRepository, SubmissionAttemptRepository};
use Scalyn\MailRelay\Mail\{MailDispatcher, MailMessage, SendResult, WordPressMailBridge};
use Scalyn\MailRelay\Recovery\{RecoveryPayload, RecoveryPolicyRepository, RecoveryService};

/** Driver-only simulation: real repositories retain validation, SQL and CAS decisions. Not a real SQL engine. */
final class LocalRecoveryFlowWpdb extends WpdbStub {
	public string $last_error = '';
	public ?array $row = null;
	public array $transitions = array();

	public function insert( string $table, array $data, mixed $format = null ): int|false {
		if ( str_ends_with( $table, 'scalyn_recovery_queue' ) ) {
			if ( null !== $this->row ) {
				return false;
			}
			$this->row = $data;
		}
		return parent::insert( $table, $data, $format );
	}

	public function get_row( string $query, string $output = OBJECT ): array|object|null {
		if ( str_contains( $query, 'COUNT(*) AS total' ) ) {
			return $this->get_row_return ?? array( 'total' => null === $this->row ? 0 : 1, 'bytes' => strlen( $this->row['payload'] ?? '' ) );
		}
		return parent::get_row( $query, $output );
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		$args = end( $this->prepare_calls )['args'];
		$this->last_error = '';
		if ( str_starts_with( $query, 'SELECT ' ) ) {
			$this->last_result = array();
			if ( null === $this->row ) {
				return 0;
			}
			if ( str_starts_with( $query, 'SELECT * ' ) ) {
				if ( 'pending' !== $this->row['state'] || $this->row['next_at'] > $args[2] || $this->row['expires_at'] <= $args[3] || $this->row['attempt_count'] >= 3 ) {
					return 0;
				}
				$this->last_result[] = (object) $this->row;
			} else {
				// Project the actual SELECT list, not an independently hardcoded safe result.
				$columns = explode( ',', substr( $query, 7, strpos( $query, ' FROM ' ) - 7 ) );
				$this->last_result[] = (object) array_intersect_key( $this->row, array_flip( $columns ) );
			}
			return count( $this->last_result );
		}
		if ( str_contains( $query, 'LIMIT 100' ) ) {
			return 0; // Fresh fixture rows are neither crashed nor expired.
		}
		if ( str_contains( $query, 'SET state=%s,claim_token=%s' ) ) {
			if ( null === $this->row || $this->row['logical_uuid'] !== $args[5] || $this->row['state'] !== $args[6] || $this->row['next_at'] > $args[7] || $this->row['expires_at'] <= $args[8] || $this->row['attempt_count'] >= 3 ) {
				return 0;
			}
			$this->row['state'] = $args[1];
			$this->row['claim_token'] = $args[2];
		} elseif ( str_contains( $query, 'attempt_count=attempt_count+1' ) ) {
			if ( $this->row['logical_uuid'] !== $args[3] || $this->row['state'] !== $args[4] || $this->row['claim_token'] !== $args[5] || $this->row['expires_at'] <= $args[6] || $this->row['attempt_count'] !== $args[7] || $this->row['attempt_count'] >= 3 ) {
				return 0;
			}
			$this->row['state'] = $args[1];
			++$this->row['attempt_count'];
		} elseif ( str_contains( $query, 'SET state=%s,reason=%s,next_at=%s' ) ) {
			if ( $this->row['logical_uuid'] !== $args[5] || $this->row['claim_token'] !== $args[6] || ! in_array( $this->row['state'], array( $args[7], $args[8] ), true ) ) {
				return 0;
			}
			$this->row['state'] = $args[1];
			$this->row['reason'] = $args[2];
			$this->row['next_at'] = $args[3];
			$this->row['claim_token'] = null;
			if ( str_contains( $query, "payload=''" ) ) {
				$this->row['payload'] = '';
			}
		} else {
			throw new LogicException( 'Unexpected local recovery fixture SQL.' );
		}
		$this->transitions[] = $this->row['state'];
		return 1;
	}
}

final class LocalRecoveryFlowTest extends TestCase {
	private string $root;
	private LocalRecoveryFlowWpdb $db;
	private LocalCredentialKeyStore $store;
	private CredentialCipher $cipher;
	private SettingsRepository $settings;
	private RecoveryService $service;
	private MailDispatcher $dispatcher;
	private object $provider;
	private mixed $previous_db;

	protected function setUp(): void {
		$this->previous_db = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $this->db = new LocalRecoveryFlowWpdb();
		$GLOBALS['_test_wp_options'] = array( 'scalyn_mail_relay_db_version' => '0.15.0' );
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$GLOBALS['_test_wp_filters'] = array();
		$GLOBALS['_test_blog_id'] = 1;
		$GLOBALS['_test_current_user_can'] = array( Capabilities::MANAGE_SETTINGS => true );
		$this->root = sys_get_temp_dir() . '/scalyn-local-recovery-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root, 0700 );
		$this->store = new LocalCredentialKeyStore( $this->root, static fn() => false, static fn() => get_option( LocalCredentialKeyStore::STATE_OPTION, null ) , static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
		$this->cipher = new CredentialCipher( null, $this->store );
		$this->cipher->initialize_for_write();
		$this->settings = new SettingsRepository( $this->cipher );
		$this->settings->save( array( 'provider' => array( 'active' => 'smtp' ), 'smtp' => array( 'host' => 'smtp.example.invalid', 'from_email' => 'sender@example.invalid', 'username' => 'synthetic-login', 'password' => 'synthetic-password' ) ) );
		$this->settings->configure( 'import' );
		$policy = new RecoveryPolicyRepository();
		$policy->save( true, true, '', $this->settings, $this->cipher );
		$this->provider = new class implements \Scalyn\MailRelay\Contracts\ProviderInterface {
			public array $calls = array();
			public function get_id(): string { return 'smtp'; }
			public function get_label(): string { return 'Offline synthetic transport'; }
			public function get_capabilities(): array { return array(); }
			public function validate_config( array $config ): \Scalyn\MailRelay\Providers\ValidationResult { return new \Scalyn\MailRelay\Providers\ValidationResult( true ); }
			public function test_connection( array $config ): \Scalyn\MailRelay\Providers\ConnectionResult { throw new LogicException( 'No network allowed.' ); }
			public function send( MailMessage $message, array $config ): SendResult {
				$this->calls[] = array( 'message' => $message, 'config' => $config, 'state' => $GLOBALS['wpdb']->row['state'] );
				return new SendResult( true, 'smtp' );
			}
		};
		$registry = new ProviderRegistry();
		$registry->register( $this->provider );
		$this->dispatcher = new MailDispatcher( $registry, $this->settings, null, new SubmissionAttemptRepository() );
		$this->service = new RecoveryService( new RecoveryQueueRepository(), $policy, new RecoveryPayload( $this->cipher ), $this->dispatcher, $this->settings );
		// Ordinary ownership and cron reads are not administrator writes.
		$GLOBALS['_test_current_user_can'] = array();
	}

	protected function tearDown(): void {
		if ( isset( $this->root ) && is_dir( $this->root ) ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->root );
		}
		$GLOBALS['wpdb'] = $this->previous_db;
		foreach ( array( '_test_wp_options', '_test_wp_option_write_failures', '_test_current_user_can', '_test_wp_added_actions', '_test_wp_filters' ) as $name ) {
			$GLOBALS[ $name ] = array();
		}
		$GLOBALS['_test_blog_id'] = 1;
	}

	private function message(): MailMessage {
		return new MailMessage( wp_generate_uuid4(), 'sender@example.invalid', array( 'recipient@example.invalid' ), 'Synthetic private subject', 'Synthetic private body' );
	}

	public function test_local_v2_policy_ownership_and_worker_use_real_queue_without_synchronous_send(): void {
		$this->assertTrue( $this->service->enabled() );
		$message = $this->message();
		$this->assertTrue( $this->service->own( $message ), 'Enabled local-v2 recovery must acknowledge the durable INSERT.' );
		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( 'pending', $this->db->row['state'] );
		$this->assertTrue( str_starts_with( $this->db->row['payload'], 'v2:' ) );
		$this->assertFalse( str_contains( $this->db->row['payload'], $message->body ) );
		$this->assertFalse( array_key_exists( RecoveryQueueRepository::LOCK, $GLOBALS['_test_wp_options'] ) );
		$pending_history = ( new RecoveryQueueRepository() )->summaries();
		$this->assertSame( array( 'logical_uuid', 'state', 'attempt_count', 'next_at', 'expires_at', 'created_at', 'reason' ), array_keys( $pending_history[0] ) );
		$this->assertFalse( str_contains( json_encode( $pending_history ), $message->body ) );
		$state = get_option( LocalCredentialKeyStore::STATE_OPTION );
		$stat = stat( $this->store->path() );
		$this->service->tick();
		$this->service->tick();
		$this->assertCount( 1, $this->provider->calls );
		$this->assertSame( 'submitting', $this->provider->calls[0]['state'] );
		$this->assertSame( $message->body, $this->provider->calls[0]['message']->body );
		$this->assertSame( $message->uuid, $this->provider->calls[0]['message']->context['logical_uuid'] );
		$this->assertSame( 1, $this->provider->calls[0]['message']->context['attempt_number'] );
		$this->assertSame( array( 'running', 'submitting', 'accepted' ), $this->db->transitions );
		$this->assertSame( '', $this->db->row['payload'] );
		$this->assertSame( 1, $this->db->row['attempt_count'] );
		$this->assertSame( $state, get_option( LocalCredentialKeyStore::STATE_OPTION ) );
		clearstatcache();
		$this->assertSame( $stat['ino'], stat( $this->store->path() )['ino'] );
		$this->assertSame( $stat['mtime'], stat( $this->store->path() )['mtime'] );
		$history = ( new RecoveryQueueRepository() )->summaries();
		$this->assertSame( array( 'logical_uuid', 'state', 'attempt_count', 'next_at', 'expires_at', 'created_at', 'reason' ), array_keys( $history[0] ) );
		$this->assertSame( 'accepted', $history[0]['state'] );
		$this->assertCount( 2, $this->db->inserts ); // Queue ownership and one durable submission evidence row.
	}

	public function test_bridge_short_circuits_core_mail_with_local_v2_ownership(): void {
		$bridge = new WordPressMailBridge( $this->settings, $this->dispatcher, $this->service );
		$bridge->register();
		$result = apply_filters( 'pre_wp_mail', null, array( 'to' => array( 'recipient@example.invalid' ), 'subject' => 'Synthetic private subject', 'message' => 'Synthetic private body', 'headers' => array(), 'attachments' => array() ) );
		$this->assertTrue( $result, 'A non-null true pre_wp_mail result owns the message; WordPress must not fall through to core mail.' );
		$this->assertSame( array(), $this->provider->calls );
		$this->assertTrue( str_starts_with( $this->db->row['payload'], 'v2:' ) );
		$this->service->tick();
		$this->service->tick();
		$this->assertCount( 1, $this->provider->calls );
	}

	public function test_unknown_or_malformed_version_prefixes_fail_before_storage_or_lock(): void {
		foreach ( array( '', 'v0:synthetic', 'v3:synthetic', 'V2:synthetic', 'v02:synthetic', 'v20:synthetic', 'v2', 'v2;synthetic', ' v2:synthetic', 'v1;synthetic' ) as $payload ) {
			$rejected = false;
			try {
				( new RecoveryQueueRepository() )->enqueue( wp_generate_uuid4(), $payload, time() );
			} catch ( RuntimeException $error ) {
				$rejected = true;
			}
			$this->assertTrue( $rejected, 'Only exact supported envelope version prefixes may reach storage.' );
			$this->assertSame( array(), $this->db->inserts );
			$this->assertSame( array(), $this->db->prepare_calls );
			$this->assertFalse( array_key_exists( RecoveryQueueRepository::LOCK, $GLOBALS['_test_wp_options'] ) );
		}
	}

	public function test_external_v1_authenticated_envelopes_remain_enqueueable(): void {
		$external = new CredentialCipher( base64_encode( str_repeat( 'e', 32 ) ) );
		$message = $this->message();
		$target = $this->settings->configuration_target( $this->settings->get_active_configuration_id() );
		$payload = new RecoveryPayload( $external );
		$sealed = $payload->seal( $message, array( $target ), 'unknown/default' );
		( new RecoveryQueueRepository() )->enqueue( $message->uuid, $sealed, time() );
		$this->assertTrue( str_starts_with( $this->db->row['payload'], 'v1:' ) );
		$this->assertSame( $message->body, $payload->open( $sealed, $message->uuid )['body'] );
	}

	public function test_cipher_still_authenticates_version_and_context_with_identical_key_material(): void {
		// Identical material proves rejection is source/version AAD, not merely a wrong key.
		$external = new CredentialCipher( base64_encode( $this->store->read() ), $this->store );
		$sealed = $this->cipher->encrypt( 'Synthetic private payload', 'recovery-payload' );
		$this->assertTrue( hash_equals( 'Synthetic private payload', $external->decrypt( $sealed, 'recovery-payload' ) ) );
		foreach ( array( array( 'v1:' . substr( $sealed, 3 ), 'recovery-payload' ), array( $sealed, 'smtp' ) ) as list( $envelope, $context ) ) {
			$rejected = false;
			try {
				$external->decrypt( $envelope, $context );
			} catch ( RuntimeException $error ) {
				$rejected = true;
			}
			$this->assertTrue( $rejected, 'Cipher must authenticate envelope source/version and purpose after queue admission.' );
		}
	}

	public function test_corrupted_local_payload_stops_worker_before_submission_and_erases_content(): void {
		$this->assertTrue( $this->service->own( $this->message() ) );
		$binary = base64_decode( substr( $this->db->row['payload'], 3 ), true );
		$binary[28] = chr( ord( $binary[28] ) ^ 1 );
		$this->db->row['payload'] = 'v2:' . base64_encode( $binary );
		$log = tmpfile();
		$previous = ini_set( 'error_log', stream_get_meta_data( $log )['uri'] );
		try {
			$this->service->tick();
			$this->service->tick();
			rewind( $log );
			$this->assertSame( 'Scalyn Mail Relay: recovery worker could not complete. Check recovery storage and review retained work.', trim( preg_replace( '/^\[[^\]]+\] /', '', stream_get_contents( $log ) ) ) );
		} finally {
			ini_set( 'error_log', $previous );
			fclose( $log );
		}
		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( array( 'running', 'review' ), $this->db->transitions );
		$this->assertSame( 0, $this->db->row['attempt_count'] );
		$this->assertSame( '', $this->db->row['payload'] );
		$this->assertCount( 1, $this->db->inserts ); // No submission evidence, hence no adapter entry.
	}

	public function test_local_v2_does_not_bypass_uuid_size_or_capacity_lock(): void {
		foreach ( array( array( 'invalid', 'v2:synthetic' ), array( wp_generate_uuid4(), 'v2:' . str_repeat( 'x', 12000000 ) ) ) as list( $logical, $payload ) ) {
			$rejected = false;
			try {
				( new RecoveryQueueRepository() )->enqueue( $logical, $payload, time() );
			} catch ( RuntimeException $error ) {
				$rejected = true;
			}
			$this->assertTrue( $rejected );
		}
		add_option( RecoveryQueueRepository::LOCK, true, '', false );
		$this->assertFalse( $this->service->own( $this->message() ) );
		$this->assertTrue( get_option( RecoveryQueueRepository::LOCK ), 'A failed contender must not release another owner\'s lock.' );
		$this->assertSame( array(), $this->db->inserts );
		delete_option( RecoveryQueueRepository::LOCK );
	}

	public function test_worker_missing_local_key_never_recreates_it_or_submits(): void {
		$this->assertTrue( $this->service->own( $this->message() ) );
		$marker = get_option( LocalCredentialKeyStore::STATE_OPTION );
		unlink( $this->store->path() );
		$log = tmpfile();
		$previous = ini_set( 'error_log', stream_get_meta_data( $log )['uri'] );
		try {
			$this->service->tick();
			$this->service->tick();
		} finally {
			ini_set( 'error_log', $previous );
			fclose( $log );
		}
		$this->assertFileDoesNotExist( $this->store->path() );
		$this->assertSame( $marker, get_option( LocalCredentialKeyStore::STATE_OPTION ) );
		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( 'review', $this->db->row['state'] );
		$this->assertSame( '', $this->db->row['payload'] );
		$this->assertSame( 0, $this->db->row['attempt_count'] );
	}

	public function test_local_v2_capacity_limits_reject_ownership_and_release_own_lock(): void {
		foreach ( array( array( 'total' => 100, 'bytes' => 0 ), array( 'total' => 0, 'bytes' => 20971520 ) ) as $capacity ) {
			$this->db->get_row_return = $capacity;
			$this->assertFalse( $this->service->own( $this->message() ) );
			$this->assertSame( array(), $this->db->inserts );
			$this->assertSame( array(), $this->provider->calls );
			$this->assertFalse( array_key_exists( RecoveryQueueRepository::LOCK, $GLOBALS['_test_wp_options'] ) );
		}
	}
}
