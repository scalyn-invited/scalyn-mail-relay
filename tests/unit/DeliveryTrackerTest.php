<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Delivery\DeliveryTracker;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
use Scalyn\MailRelay\Providers\ValidationResult;

/** In-memory delivery tables with transaction snapshots. */
final class TrackerDb extends WpdbStub {
	public array $keys=[];
	public array $attempts=[];
	public array $members=[];
	public string $last_error='';
	public bool $failMembership=false;
	private array $snapshot=[];
	public function get_var( string $query ): mixed {
		$a=end( $this->prepare_calls )['args'] ?? [];
		if ( str_contains( $query, 'information_schema' ) ) { return 3; }
		if ( str_contains( $query, 'SELECT key_version' ) ) { return isset( $this->keys[ $a[1] ] ) ? $a[1] : null; }
		if ( str_contains( $query, 'SELECT key_envelope' ) ) { return $this->keys[ $a[1] ] ?? null; }
		if ( str_contains( $query, 'SELECT provider_message_id' ) ) { return $this->attempts[ $a[1] ]['provider_message_id'] ?? null; }
		return null;
	}
	public function insert( string $table, array $data, mixed $format=null ): int|false {
		if ( str_ends_with( $table, 'delivery_keys' ) ) { $this->keys[ $data['key_version'] ]=$data['key_envelope']; return 1; }
		if ( str_ends_with( $table, 'delivery_attempts' ) ) { $this->attempts[ $data['message_uuid'] ]=$data+['provider_message_id'=>null]; return 1; }
		if ( $this->failMembership ) { return false; }
		$this->members[ $data['message_uuid'] ][]=$data['recipient_token'];
		return 1;
	}
	public function query( string $query ): int|false {
		$this->queries[]=$query;
		if ( 'START TRANSACTION'===$query ) { $this->snapshot=[ $this->attempts, $this->members ]; }
		if ( 'ROLLBACK'===$query ) { [ $this->attempts, $this->members ]=$this->snapshot; }
		if ( str_starts_with( $query, 'UPDATE' ) ) {
			$a=end( $this->prepare_calls )['args'];
			if ( isset( $this->attempts[ $a[2] ] ) && null===$this->attempts[ $a[2] ]['provider_message_id'] ) { $this->attempts[ $a[2] ]['provider_message_id']=$a[1]; }
		}
		return 1;
	}
}

final class DeliveryTrackerTest extends TestCase {
	private const REV='11111111-1111-4111-8111-111111111111';
	private const MSG='aaaaaaaa-1111-4111-8111-111111111111';
	private const PM='bbbbbbbb-2222-4222-8222-222222222222';
	private CredentialCipher $cipher;
	private TrackerDb $db;
	private PostmarkWebhookSettings $sources;

	protected function setUp(): void {
		foreach ( [ '_test_wp_options', '_test_wp_actions', '_test_wp_added_actions', '_test_wp_option_write_failures' ] as $key ) { $GLOBALS[ $key ]=[]; }
		$GLOBALS['_test_current_user_can']=[ Capabilities::MANAGE_MAIL=>true ];
		$GLOBALS['_test_wp_nonce_valid']=true;
		$GLOBALS['wpdb']=$this->db=new TrackerDb();
		$this->cipher=new CredentialCipher( base64_encode( str_repeat( 'a', 32 ) ) );
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.6.0';
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ]=[ 'provider'=>[ 'active'=>'postmark' ], 'postmark'=>[ 'key_cipher'=>$this->cipher->encrypt( 'synthetic-server-token-0123', 'postmark' ), 'from_email'=>'sender@example.com', 'from_name'=>'' ], 'diagnostic_revision'=>self::REV ];
		$this->sources=new PostmarkWebhookSettings( $this->cipher, new DeliveryKeyRepository( $this->cipher ), new SettingsRepository( $this->cipher ) );
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['_test_current_user_can']=[];
	}
	private function enable(): string {
		$this->sources->save( [ 'server_id'=>7, 'stream'=>'outbound', 'allowed_ips'=>[ '192.0.2.1' ], 'credential_action'=>'replace', 'username'=>'synthetic_username', 'password'=>'synthetic_password_01234567890123456789' ], 'valid' );
		$this->sources->verify( 'valid', new class extends PostmarkProvider { public function live_server_id( array $config ): ?int { return 7; } } );
		$this->sources->enable( true, 'valid' );
		return get_option( PostmarkWebhookSettings::OPTION )['id'];
	}
	private function tracker(): DeliveryTracker {
		return new DeliveryTracker( $this->sources, new DeliveryKeyRepository( $this->cipher ), new DeliveryAttemptRepository(), new PostmarkProvider() );
	}
	private function message( array $headers=[] ): MailMessage {
		return new MailMessage( self::MSG, 'sender@example.com', [ 'Alice <Alice@Example.com>', 'bob@example.com' ], 'Subject', 'Body', 'text/plain', $headers );
	}

	public function test_untracked_when_collection_off_or_provider_is_not_postmark(): void {
		$this->assertNull( $this->tracker()->prepare( $this->message(), 'postmark' ) );
		$this->enable();
		$this->assertNull( $this->tracker()->prepare( $this->message(), 'sendgrid' ) );
		$this->assertSame( [], $this->db->attempts );
	}

	public function test_prepare_stores_deduplicated_membership_without_addresses(): void {
		$source=$this->enable();
		$association=$this->tracker()->prepare( $this->message( [ 'Cc: bob@example.com', 'Bcc: hidden@example.com' ] ), 'postmark' );
		$this->assertSame( [ 'source_id'=>$source, 'message_uuid'=>self::MSG ], $association );
		$attempt=$this->db->attempts[ self::MSG ];
		$this->assertSame( [ $source, self::REV, 3 ], [ $attempt['source_id'], $attempt['configuration_id'], $attempt['expected_recipients'] ] );
		$this->assertCount( 3, $this->db->members[ self::MSG ] );
		$stored=json_encode( [ $this->db->attempts, $this->db->members ] );
		foreach ( [ 'alice', 'Alice', 'bob@', 'hidden' ] as $private ) { $this->assertStringNotContainsString( $private, $stored ); }
		$keys=new DeliveryKeyRepository( $this->cipher );
		$this->assertContains( $keys->token( $attempt['key_version'], $source, self::MSG, 'Alice@example.com' ), $this->db->members[ self::MSG ], 'Domain lowercased, local part preserved' );
		$this->assertNotContains( $keys->token( $attempt['key_version'], $source, self::MSG, 'alice@example.com' ), $this->db->members[ self::MSG ] );
	}

	public function test_unparseable_recipients_or_storage_failure_leave_send_untracked(): void {
		$this->enable();
		$this->assertNull( $this->tracker()->prepare( new MailMessage( self::MSG, 'sender@example.com', [ 'not-an-address' ], 'S', 'B' ), 'postmark' ) );
		$this->db->failMembership=true;
		$this->assertNull( $this->tracker()->prepare( $this->message(), 'postmark' ) );
		$this->assertSame( [], $this->db->attempts, 'Partial association rolled back' );
	}

	public function test_acknowledge_binds_only_successful_postmark_identifier(): void {
		$this->enable();
		$tracker=$this->tracker();
		$association=$tracker->prepare( $this->message(), 'postmark' );
		$tracker->acknowledge( $association, new SendResult( false, 'postmark', self::PM ) );
		$tracker->acknowledge( null, new SendResult( true, 'postmark', self::PM ) );
		$this->assertNull( $this->db->attempts[ self::MSG ]['provider_message_id'] );
		$tracker->acknowledge( $association, new SendResult( true, 'postmark', strtoupper( self::PM ) ) );
		$this->assertSame( self::PM, $this->db->attempts[ self::MSG ]['provider_message_id'] );
	}

	public function test_dispatcher_associates_before_submission_and_never_changes_outcome(): void {
		$this->enable();
		$db=$this->db;
		$provider=new class( $db ) implements ProviderInterface {
			public ?bool $associated_before_send=null;
			public function __construct( private TrackerDb $db ) {}
			public function get_id(): string { return 'postmark'; }
			public function get_label(): string { return 'Postmark API'; }
			public function validate_config( array $config ): ValidationResult { return new ValidationResult( true ); }
			public function test_connection( array $config ): ConnectionResult { return new ConnectionResult( true ); }
			public function get_capabilities(): array { return []; }
			public function send( MailMessage $message, array $config ): SendResult {
				$this->associated_before_send=isset( $this->db->attempts[ $message->uuid ] );
				return new SendResult( true, 'postmark', 'bbbbbbbb-2222-4222-8222-222222222222' );
			}
		};
		$registry=new ProviderRegistry();
		$registry->register( $provider );
		$result=( new MailDispatcher( $registry, new SettingsRepository( $this->cipher ), $this->tracker() ) )->dispatch( $this->message() );
		$this->assertTrue( $result->success );
		$this->assertTrue( $provider->associated_before_send );
		$this->assertSame( self::PM, $this->db->attempts[ self::MSG ]['provider_message_id'] );

		$this->db->attempts=[];
		$this->db->failMembership=true;
		$provider->associated_before_send=null;
		$again=( new MailDispatcher( $registry, new SettingsRepository( $this->cipher ), $this->tracker() ) )->dispatch( $this->message() );
		$this->assertTrue( $again->success, 'Tracking failure never blocks or alters the send' );
		$this->assertFalse( $provider->associated_before_send );
	}
}
