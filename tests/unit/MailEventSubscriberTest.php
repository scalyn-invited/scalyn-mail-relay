<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Logging\MailEventSubscriber;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\MailStatus;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

/**
 * Unit tests for MailEventSubscriber.
 *
 * Verifies that:
 *  - register() wires the correct WordPress actions.
 *  - MAIL_SENT maps to MailStatus::ACCEPTED (not SENT or any other status).
 *  - MAIL_FAILED maps to MailStatus::FAILED.
 *  - SendResult::$metadata is never persisted in event_data.
 *  - Subject, recipient addresses, and body are never stored.
 *  - Persistence failures are caught and do not propagate to callers.
 *
 * Testing approach: real MailLogRepository and TimelineRepository instances are
 * used with a WpdbStub global. The stub records all INSERT/UPDATE calls, allowing
 * assertions on the exact data written to each table without a live database.
 */
final class MailEventSubscriberTest extends TestCase {

	private WpdbStub $wpdb;

	protected function setUp(): void {
		$this->wpdb                        = new WpdbStub();
		$GLOBALS['wpdb']                   = $this->wpdb;
		$GLOBALS['_test_wp_added_actions'] = array();
		$GLOBALS['_test_current_time']     = '2026-08-21 15:00:00';
	}

	protected function tearDown(): void {
		$GLOBALS['_test_current_time']     = null;
		$GLOBALS['_test_wp_added_actions'] = array();
		unset( $GLOBALS['wpdb'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_subscriber(): MailEventSubscriber {
		return new MailEventSubscriber(
			new MailLogRepository(),
			new TimelineRepository()
		);
	}

	private function make_message( array $overrides = array() ): MailMessage {
		return new MailMessage(
			uuid: $overrides['uuid'] ?? 'sub-test-uuid-001',
			from: $overrides['from'] ?? 'sender@example.com',
			to: $overrides['to'] ?? array( 'recipient@example.com' ),
			subject: $overrides['subject'] ?? 'Subscriber Test Subject',
			body: $overrides['body'] ?? '<p>Subscriber test body</p>',
			content_type: 'text/html',
			headers: array(),
			attachments: array(),
			context: $overrides['context'] ?? array(
				'source_type' => 'test',
				'source_name' => 'PHPUnit',
			)
		);
	}

	private function make_success_result( array $overrides = array() ): SendResult {
		return new SendResult(
			success: true,
			provider: $overrides['provider'] ?? 'smtp',
			provider_message_id: null,
			response_code: $overrides['response_code'] ?? null,
			response_message: 'Message accepted by the configured SMTP server.',
			retryable: false,
			failure_category: null,
			metadata: $overrides['metadata'] ?? array()
		);
	}

	private function make_failed_result( array $overrides = array() ): SendResult {
		return new SendResult(
			success: false,
			provider: $overrides['provider'] ?? 'smtp',
			provider_message_id: null,
			response_code: $overrides['response_code'] ?? null,
			response_message: 'SMTP transport failed.',
			retryable: $overrides['retryable'] ?? false,
			failure_category: $overrides['failure_category'] ?? 'network',
			metadata: $overrides['metadata'] ?? array()
		);
	}

	/**
	 * Returns all INSERT data arrays across all recorded INSERTs for a given table.
	 *
	 * @param string $table_fragment Partial table name to filter by (e.g. 'mail_logs').
	 * @return array<int, array<string, mixed>>
	 */
	private function inserts_for_table( string $table_fragment ): array {
		return array_values(
			array_filter(
				array_map( fn( $i ) => $i['data'], $this->wpdb->inserts ),
				fn( $data, $idx ) => false !== strpos( $this->wpdb->inserts[ $idx ]['table'], $table_fragment ),
				ARRAY_FILTER_USE_BOTH
			)
		);
	}

	// -------------------------------------------------------------------------
	// register()
	// -------------------------------------------------------------------------

	public function test_register_adds_action_for_mail_sent(): void {
		$this->make_subscriber()->register();

		$this->assertArrayHasKey( HookNames::MAIL_SENT, $GLOBALS['_test_wp_added_actions'] );
	}

	public function test_register_adds_action_for_mail_failed(): void {
		$this->make_subscriber()->register();

		$this->assertArrayHasKey( HookNames::MAIL_FAILED, $GLOBALS['_test_wp_added_actions'] );
	}

	public function test_register_mail_sent_action_accepts_two_arguments(): void {
		$this->make_subscriber()->register();

		$registered = $GLOBALS['_test_wp_added_actions'][ HookNames::MAIL_SENT ][0];
		$this->assertSame( 2, $registered['accepted_args'] );
	}

	public function test_register_mail_failed_action_accepts_two_arguments(): void {
		$this->make_subscriber()->register();

		$registered = $GLOBALS['_test_wp_added_actions'][ HookNames::MAIL_FAILED ][0];
		$this->assertSame( 2, $registered['accepted_args'] );
	}

	// -------------------------------------------------------------------------
	// on_mail_sent() — log row
	// -------------------------------------------------------------------------

	/**
	 * MAIL_SENT must record MailStatus::ACCEPTED — not SENT or any intermediate status.
	 *
	 * Evidence: SmtpProvider returns success=true only after PHPMailer receives
	 * the SMTP 250 DATA response. MailStatus::ACCEPTED is explicitly defined as
	 * "For SMTP this corresponds to a 250 response."
	 */
	public function test_on_mail_sent_writes_accepted_status_to_log(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		$log_inserts = $this->inserts_for_table( 'mail_logs' );
		$this->assertNotEmpty( $log_inserts );
		$this->assertSame( MailStatus::ACCEPTED, $log_inserts[0]['status'] );
	}

	public function test_on_mail_sent_does_not_write_intermediate_lifecycle_stages(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		// Only ACCEPTED is a valid terminal status for a successful send.
		// No CONNECTED, AUTHENTICATED, SENT, PREPARED, or GENERATED rows may appear.
		$log_inserts = $this->inserts_for_table( 'mail_logs' );
		$this->assertCount( 1, $log_inserts, 'Exactly one log insert for a single MAIL_SENT event.' );
		$invalid_statuses = array(
			MailStatus::CONNECTED,
			MailStatus::AUTHENTICATED,
			MailStatus::SENT,
			MailStatus::PREPARED,
			MailStatus::GENERATED,
		);
		foreach ( $invalid_statuses as $invalid ) {
			$this->assertNotSame( $invalid, $log_inserts[0]['status'], "Status must not be '{$invalid}'." );
		}
	}

	/**
	 * Privacy: subject must never appear in any insert for any table.
	 */
	public function test_on_mail_sent_does_not_store_subject(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'subject' => 'Highly Sensitive Subject' ) );

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $message );

		foreach ( $this->wpdb->inserts as $insert ) {
			$this->assertArrayNotHasKey( 'subject', $insert['data'] );
		}
	}

	/**
	 * Privacy: recipient addresses must never appear in any insert for any table.
	 */
	public function test_on_mail_sent_does_not_store_recipient(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'to' => array( 'private@example.com' ) ) );

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $message );

		foreach ( $this->wpdb->inserts as $insert ) {
			$this->assertArrayNotHasKey( 'to', $insert['data'] );
			$this->assertArrayNotHasKey( 'recipient', $insert['data'] );
		}
	}

	/**
	 * Privacy: message body must never appear in any insert for any table.
	 */
	public function test_on_mail_sent_does_not_store_body(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'body' => 'Sensitive body content here.' ) );

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $message );

		foreach ( $this->wpdb->inserts as $insert ) {
			$this->assertArrayNotHasKey( 'body', $insert['data'] );
		}
	}

	// -------------------------------------------------------------------------
	// on_mail_sent() — timeline event
	// -------------------------------------------------------------------------

	public function test_on_mail_sent_writes_timeline_event(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertNotEmpty( $timeline_inserts );
	}

	public function test_on_mail_sent_timeline_event_type_is_mail_sent(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertSame( 'mail_sent', $timeline_inserts[0]['event_type'] );
	}

	public function test_on_mail_sent_timeline_event_status_is_accepted(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertSame( MailStatus::ACCEPTED, $timeline_inserts[0]['event_status'] );
	}

	/**
	 * SendResult::$metadata must never appear in event_data.
	 *
	 * The @security annotation on SendResult states metadata may contain
	 * credential-adjacent provider data. The allowlist in build_sent_event_data()
	 * must explicitly exclude it.
	 */
	public function test_on_mail_sent_event_data_excludes_metadata(): void {
		$this->wpdb->get_var_return = null;
		$result                     = $this->make_success_result(
			array(
				'metadata' => array(
					'api_key'  => 'secret-api-key',
					'password' => 'smtp-password',
				),
			)
		);

		$this->make_subscriber()->on_mail_sent( $result, $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$event_data_json  = $timeline_inserts[0]['event_data'];
		$this->assertIsString( $event_data_json );

		$decoded = json_decode( $event_data_json, true );
		$this->assertArrayNotHasKey( 'metadata', $decoded );
		$this->assertArrayNotHasKey( 'api_key', $decoded );
		$this->assertArrayNotHasKey( 'password', $decoded );
	}

	public function test_on_mail_sent_event_data_contains_only_allowlisted_fields(): void {
		$this->wpdb->get_var_return = null;
		$result                     = $this->make_success_result(
			array(
				'provider'      => 'smtp',
				'response_code' => '250',
				'metadata'      => array( 'extra' => 'should-not-appear' ),
			)
		);

		$this->make_subscriber()->on_mail_sent( $result, $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$decoded          = json_decode( $timeline_inserts[0]['event_data'], true );

		$allowed_keys = array( 'provider', 'response_code' );
		$extra_keys   = array_diff( array_keys( $decoded ), $allowed_keys );
		$this->assertEmpty( $extra_keys, 'event_data for MAIL_SENT must contain only allowlisted keys.' );
	}

	// -------------------------------------------------------------------------
	// on_mail_failed() — log row
	// -------------------------------------------------------------------------

	public function test_on_mail_failed_writes_failed_status_to_log(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $this->make_message() );

		$log_inserts = $this->inserts_for_table( 'mail_logs' );
		$this->assertNotEmpty( $log_inserts );
		$this->assertSame( MailStatus::FAILED, $log_inserts[0]['status'] );
	}

	// -------------------------------------------------------------------------
	// on_mail_failed() — timeline event
	// -------------------------------------------------------------------------

	public function test_on_mail_failed_writes_timeline_event(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertNotEmpty( $timeline_inserts );
	}

	public function test_on_mail_failed_timeline_event_type_is_mail_failed(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertSame( 'mail_failed', $timeline_inserts[0]['event_type'] );
	}

	public function test_on_mail_failed_timeline_event_status_is_failed(): void {
		$this->wpdb->get_var_return = null;

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertSame( MailStatus::FAILED, $timeline_inserts[0]['event_status'] );
	}

	public function test_on_mail_failed_event_data_excludes_metadata(): void {
		$this->wpdb->get_var_return = null;
		$result                     = $this->make_failed_result(
			array( 'metadata' => array( 'smtp_transcript' => 'AUTH user pass\r\n535 AUTH failed' ) )
		);

		$this->make_subscriber()->on_mail_failed( $result, $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$decoded          = json_decode( $timeline_inserts[0]['event_data'], true );
		$this->assertArrayNotHasKey( 'metadata', $decoded );
		$this->assertArrayNotHasKey( 'smtp_transcript', $decoded );
	}

	public function test_on_mail_failed_event_data_contains_only_allowlisted_fields(): void {
		$this->wpdb->get_var_return = null;
		$result                     = $this->make_failed_result(
			array(
				'provider'         => 'smtp',
				'response_code'    => null,
				'failure_category' => 'auth',
				'retryable'        => false,
				'metadata'         => array( 'raw_error' => 'should-not-appear' ),
			)
		);

		$this->make_subscriber()->on_mail_failed( $result, $this->make_message() );

		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$decoded          = json_decode( $timeline_inserts[0]['event_data'], true );

		$allowed_keys = array( 'provider', 'response_code', 'failure_category', 'retryable' );
		$extra_keys   = array_diff( array_keys( $decoded ), $allowed_keys );
		$this->assertEmpty( $extra_keys, 'event_data for MAIL_FAILED must contain only allowlisted keys.' );
	}

	// -------------------------------------------------------------------------
	// Failure isolation — repository errors must not propagate
	// -------------------------------------------------------------------------

	/**
	 * When the DB layer throws, on_mail_sent() must catch and not re-throw.
	 *
	 * Persistence failure must never interrupt mail delivery or expose
	 * credential-adjacent exception details to callers.
	 */
	public function test_on_mail_sent_does_not_throw_when_repository_throws(): void {
		$this->wpdb->throw_on_insert = true;

		// If the subscriber re-throws, this test will fail with an unexpected exception.
		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		$this->addToAssertionCount( 1 ); // Reached here = exception was caught internally.
	}

	/**
	 * When the DB layer throws, on_mail_failed() must catch and not re-throw.
	 */
	public function test_on_mail_failed_does_not_throw_when_repository_throws(): void {
		$this->wpdb->throw_on_insert = true;

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $this->make_message() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When $wpdb->insert() returns false, the repository throws RuntimeException.
	 * The subscriber must absorb it — mail delivery must remain fail-open.
	 */
	public function test_on_mail_sent_does_not_throw_when_wpdb_returns_false(): void {
		$this->wpdb->get_var_return         = null;
		$this->wpdb->return_false_on_insert = true;

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $this->make_message() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Same path for on_mail_failed: false return from $wpdb must not propagate.
	 */
	public function test_on_mail_failed_does_not_throw_when_wpdb_returns_false(): void {
		$this->wpdb->get_var_return         = null;
		$this->wpdb->return_false_on_insert = true;

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $this->make_message() );

		$this->addToAssertionCount( 1 );
	}

	// -------------------------------------------------------------------------
	// UUID correlation
	// -------------------------------------------------------------------------

	public function test_on_mail_sent_uses_message_uuid_as_correlation_key(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'uuid' => 'corr-uuid-xyz' ) );

		$this->make_subscriber()->on_mail_sent( $this->make_success_result(), $message );

		$log_inserts      = $this->inserts_for_table( 'mail_logs' );
		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );

		$this->assertSame( 'corr-uuid-xyz', $log_inserts[0]['message_uuid'] );
		$this->assertSame( 'corr-uuid-xyz', $timeline_inserts[0]['message_uuid'] );
	}

	public function test_on_mail_failed_uses_message_uuid_as_correlation_key(): void {
		$this->wpdb->get_var_return = null;
		$message                    = $this->make_message( array( 'uuid' => 'corr-uuid-fail' ) );

		$this->make_subscriber()->on_mail_failed( $this->make_failed_result(), $message );

		$log_inserts      = $this->inserts_for_table( 'mail_logs' );
		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );

		$this->assertSame( 'corr-uuid-fail', $log_inserts[0]['message_uuid'] );
		$this->assertSame( 'corr-uuid-fail', $timeline_inserts[0]['message_uuid'] );
	}

	// -------------------------------------------------------------------------
	// Integration: register() → add_action() → dispatch() → do_action() → subscriber
	// -------------------------------------------------------------------------

	/**
	 * Builds a minimal ProviderInterface stub that returns the given SendResult.
	 *
	 * @param SendResult $result Result to return from send().
	 * @return ProviderInterface
	 */
	private function make_stub_provider( SendResult $result ): ProviderInterface {
		return new class( $result ) implements ProviderInterface {
			public function __construct( private SendResult $r ) {}
			public function get_id(): string {
				return 'smtp'; }
			public function get_label(): string {
				return 'SMTP'; }
			public function get_capabilities(): array {
				return array(); }
			public function validate_config( array $config ): ValidationResult {
				return new ValidationResult( true ); }
			public function test_connection( array $config ): ConnectionResult {
				return new ConnectionResult( true ); }
			public function send( MailMessage $message, array $config ): SendResult {
				return $this->r; }
		};
	}

	/**
	 * Configures _test_wp_options so SettingsRepository returns 'smtp' as the
	 * active provider and returns a ready MailDispatcher.
	 */
	private function make_dispatcher( ProviderInterface $provider ): MailDispatcher {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return new MailDispatcher( $registry, new SettingsRepository() );
	}

	/**
	 * Full failure chain:
	 *
	 * register() → add_action(MAIL_FAILED)
	 * → dispatch() → do_action(MAIL_FAILED)
	 * → on_mail_failed()
	 * → MailLogRepository::upsert()  → failed log row
	 * → TimelineRepository::insert_event() → mail_failed timeline row
	 *
	 * Verifies: failed log written, timeline written, UUID correlated,
	 * callback fires exactly once, no metadata/credentials in event_data.
	 */
	public function test_failure_chain_invokes_subscriber_via_dispatch(): void {
		$this->wpdb->get_var_return = null;

		$subscriber = $this->make_subscriber();
		$subscriber->register();

		$auth_fail = new SendResult(
			success: false,
			provider: 'smtp',
			provider_message_id: null,
			response_code: null,
			response_message: 'SMTP authentication failed.',
			retryable: false,
			failure_category: 'auth',
			metadata: array( 'smtp_transcript' => 'should-not-persist' )
		);

		$dispatcher = $this->make_dispatcher( $this->make_stub_provider( $auth_fail ) );
		$message    = $this->make_message( array( 'uuid' => 'integ-fail-uuid-01' ) );

		$dispatcher->dispatch( $message );

		// Exactly one failed mail_logs insert.
		$log_inserts = $this->inserts_for_table( 'mail_logs' );
		$this->assertCount( 1, $log_inserts, 'on_mail_failed() must fire exactly once.' );
		$this->assertSame( MailStatus::FAILED, $log_inserts[0]['status'] );
		$this->assertSame( 'integ-fail-uuid-01', $log_inserts[0]['message_uuid'] );
		$this->assertNotNull( $log_inserts[0]['failed_at'] );
		$this->assertNull( $log_inserts[0]['sent_at'] );

		// Exactly one mail_failed timeline insert.
		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertCount( 1, $timeline_inserts, 'Exactly one timeline event per failed dispatch.' );
		$this->assertSame( 'mail_failed', $timeline_inserts[0]['event_type'] );
		$this->assertSame( MailStatus::FAILED, $timeline_inserts[0]['event_status'] );

		// UUID correlation.
		$this->assertSame( $log_inserts[0]['message_uuid'], $timeline_inserts[0]['message_uuid'] );

		// event_data: only allowlisted keys, no metadata or credentials.
		$decoded = json_decode( $timeline_inserts[0]['event_data'], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayNotHasKey( 'metadata', $decoded );
		$this->assertArrayNotHasKey( 'smtp_transcript', $decoded );
		$this->assertArrayNotHasKey( 'password', $decoded );
		$this->assertArrayNotHasKey( 'username', $decoded );
		$allowed_keys = array( 'provider', 'response_code', 'failure_category', 'retryable' );
		$this->assertEmpty(
			array_diff( array_keys( $decoded ), $allowed_keys ),
			'event_data must contain only allowlisted keys.'
		);
	}

	/**
	 * Full success chain:
	 *
	 * register() → add_action(MAIL_SENT)
	 * → dispatch() → do_action(MAIL_SENT)
	 * → on_mail_sent()
	 * → MailLogRepository::upsert()  → accepted log row
	 * → TimelineRepository::insert_event() → mail_sent timeline row
	 *
	 * Verifies: accepted log written, timeline written, UUID correlated,
	 * callback fires exactly once, no metadata in event_data.
	 */
	public function test_success_chain_invokes_subscriber_via_dispatch(): void {
		$this->wpdb->get_var_return = null;

		$subscriber = $this->make_subscriber();
		$subscriber->register();

		$accepted = new SendResult(
			success: true,
			provider: 'smtp',
			provider_message_id: null,
			response_code: '250',
			response_message: 'Message accepted by the configured SMTP server.',
			retryable: false,
			failure_category: null,
			metadata: array( 'api_key' => 'should-not-persist' )
		);

		$dispatcher = $this->make_dispatcher( $this->make_stub_provider( $accepted ) );
		$message    = $this->make_message( array( 'uuid' => 'integ-sent-uuid-01' ) );

		$dispatcher->dispatch( $message );

		// Exactly one accepted mail_logs insert.
		$log_inserts = $this->inserts_for_table( 'mail_logs' );
		$this->assertCount( 1, $log_inserts, 'on_mail_sent() must fire exactly once.' );
		$this->assertSame( MailStatus::ACCEPTED, $log_inserts[0]['status'] );
		$this->assertSame( 'integ-sent-uuid-01', $log_inserts[0]['message_uuid'] );
		$this->assertNotNull( $log_inserts[0]['sent_at'] );
		$this->assertNull( $log_inserts[0]['failed_at'] );

		// Exactly one mail_sent timeline insert.
		$timeline_inserts = $this->inserts_for_table( 'mail_timeline' );
		$this->assertCount( 1, $timeline_inserts, 'Exactly one timeline event per successful dispatch.' );
		$this->assertSame( 'mail_sent', $timeline_inserts[0]['event_type'] );
		$this->assertSame( MailStatus::ACCEPTED, $timeline_inserts[0]['event_status'] );

		// UUID correlation.
		$this->assertSame( $log_inserts[0]['message_uuid'], $timeline_inserts[0]['message_uuid'] );

		// event_data: only allowlisted keys, no metadata or credentials.
		$decoded = json_decode( $timeline_inserts[0]['event_data'], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayNotHasKey( 'metadata', $decoded );
		$this->assertArrayNotHasKey( 'api_key', $decoded );
		$allowed_keys = array( 'provider', 'response_code' );
		$this->assertEmpty(
			array_diff( array_keys( $decoded ), $allowed_keys ),
			'event_data must contain only allowlisted keys.'
		);
	}
}
