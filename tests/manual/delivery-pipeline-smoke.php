<?php
/**
 * CLI-only end-to-end check on disposable tables: dispatch association ->
 * acknowledgement -> authenticated receiver -> atomic evidence -> coverage.
 * Never sends mail, registers a provider webhook or saves plugin settings.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

require dirname( __DIR__, 5 ) . '/wp-load.php';

use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;
use Scalyn\MailRelay\Database\DeliveryCoverageRepository;
use Scalyn\MailRelay\Database\DeliveryEventRepository;
use Scalyn\MailRelay\Database\DeliveryEvidenceSchema;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Database\WebhookRateLimitRepository;
use Scalyn\MailRelay\Delivery\DeliveryCoverage;
use Scalyn\MailRelay\Delivery\DeliveryTracker;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
use Scalyn\MailRelay\Rest\PostmarkWebhookEndpoint;

global $wpdb;
$original_prefix = $wpdb->prefix;
$old_suppression = $wpdb->suppress_errors( true );
$test_prefix     = 'scalyn_qa_' . bin2hex( random_bytes( 6 ) ) . '_';
$tables          = array();
foreach ( array_keys( DeliveryEvidenceSchema::definitions() ) as $suffix ) { $tables[] = $test_prefix . $suffix; }
$tables[] = $test_prefix . 'scalyn_mail_timeline';
$passed   = false;
$cleanup  = true;
$step     = 'setup';
$source   = strtolower( wp_generate_uuid4() );
$budget   = 'scalyn_webhook_budget_' . $source;

function pipeline_assert( bool $condition ): void {
	if ( ! $condition ) { throw new RuntimeException( 'Pipeline assertion failed.' ); }
}
function pipeline_count( string $table ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . $table ) );
}

try {
	$cipher   = new CredentialCipher( base64_encode( random_bytes( 32 ) ) );
	$revision = strtolower( wp_generate_uuid4() );
	$user     = 'qa_' . bin2hex( random_bytes( 8 ) );
	$password = bin2hex( random_bytes( 20 ) );
	$state    = array(
		'version'           => 1,
		'id'                => $source,
		'server_id'         => 4242,
		'stream'            => 'outbound',
		'allowed_ips'       => array( '192.0.2.10' ),
		'credentials'       => $cipher->encrypt( wp_json_encode( array( 'username' => $user, 'password' => $password ) ), 'postmark-webhook' ),
		'enabled'           => true,
		'verified_revision' => $revision,
		'verified_at'       => gmdate( 'Y-m-d H:i:s' ),
	);
	// Isolated in-memory settings: real options are never read for these or written.
	add_filter( 'pre_option_' . PostmarkWebhookSettings::OPTION, static function () use ( &$state ) { return $state; } );
	add_filter( 'pre_option_' . SettingsRepository::OPTION_KEY, static fn() => array( 'provider' => array( 'active' => 'postmark' ), 'diagnostic_revision' => $revision, 'advanced' => array( 'log_retention_days' => 30 ) ) );
	add_filter( 'pre_option_scalyn_mail_relay_db_version', static fn() => '0.6.0' );

	$wpdb->prefix = $test_prefix;
	DeliveryEvidenceSchema::migrate();
	DeliveryEvidenceSchema::migrate_key_retirement();
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, message_uuid char(36) NOT NULL, event_type varchar(50), event_status varchar(32), event_label varchar(191), event_message text, event_data longtext, created_at datetime) ENGINE=InnoDB', $test_prefix . 'scalyn_mail_timeline' ) );
	pipeline_assert( empty( $wpdb->last_error ) );

	$keys              = new DeliveryKeyRepository( $cipher );
	$state['key_version'] = $keys->provision();
	$mail              = new SettingsRepository( $cipher );
	$sources           = new PostmarkWebhookSettings( $cipher, $keys, $mail );
	$attempts          = new DeliveryAttemptRepository();
	$tracker           = new DeliveryTracker( $sources, $keys, $attempts, new PostmarkProvider() );
	$endpoint          = new PostmarkWebhookEndpoint( $sources, $mail, new WebhookRateLimitRepository(), $attempts, $keys, new DeliveryEventRepository() );
	$coverage          = new DeliveryCoverage( new DeliveryCoverageRepository(), $sources );

	$step    = 'dispatch';
	$message = strtolower( wp_generate_uuid4() );
	$pm_id   = strtolower( wp_generate_uuid4() );
	$assoc   = $tracker->prepare( new MailMessage( $message, 'sender@example.com', array( 'Synthetic One <one@example.com>' ), 'S', 'B', 'text/plain', array( 'Bcc: two@example.com' ) ), 'postmark' );
	pipeline_assert( array( 'source_id' => $source, 'message_uuid' => $message ) === $assoc );
	pipeline_assert( 'awaiting' === $coverage->summarize( $message, 'accepted' )['state'] );
	$tracker->acknowledge( $assoc, new SendResult( true, 'postmark', strtoupper( $pm_id ) ) );
	pipeline_assert( $pm_id === $wpdb->get_var( $wpdb->prepare( 'SELECT provider_message_id FROM %i WHERE message_uuid=%s', $test_prefix . 'scalyn_delivery_attempts', $message ) ) );

	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	$_SERVER['HTTPS']       = 'on';
	$call = static function ( array $payload, string $secret = '' ) use ( $endpoint, $source, $user, $password ): int {
		$body    = wp_json_encode( $payload );
		$request = new WP_REST_Request( 'POST', '/scalyn-mail-relay/v1/webhooks/postmark/' . $source );
		$request->set_param( 'source', $source );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( $user . ':' . ( '' !== $secret ? $secret : $password ) ) );
		$request->set_body( $body );
		return $endpoint->handle( $request )->get_status();
	};
	$delivery = array( 'RecordType' => 'Delivery', 'ServerID' => 4242, 'MessageStream' => 'outbound', 'MessageID' => $pm_id, 'Recipient' => 'one@example.com', 'DeliveredAt' => gmdate( 'Y-m-d\TH:i:s' ) . '.1234567Z', 'Metadata' => array( 'scalyn_message_uuid' => $message ), 'Details' => 'private provider text' );

	$step = 'authentication';
	pipeline_assert( 401 === $call( $delivery, str_repeat( 'x', 40 ) ) );
	$_SERVER['HTTPS'] = 'off';
	pipeline_assert( 401 === $call( $delivery ) );
	$_SERVER['HTTPS']       = 'on';
	$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
	pipeline_assert( 401 === $call( $delivery ) );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	pipeline_assert( 0 === pipeline_count( 'scalyn_delivery_events' ) );

	$step = 'ingest';
	pipeline_assert( 200 === $call( $delivery ) );
	pipeline_assert( 200 === $call( $delivery ) );
	pipeline_assert( 1 === pipeline_count( 'scalyn_delivery_events' ) && 1 === pipeline_count( 'scalyn_mail_timeline' ) );
	pipeline_assert( 'partial' === $coverage->summarize( $message, 'accepted' )['state'] );
	$bounce = array( 'RecordType' => 'Bounce', 'ID' => 991, 'Type' => 'HardBounce', 'ServerID' => 4242, 'MessageStream' => 'outbound', 'MessageID' => $pm_id, 'Email' => 'two@example.com', 'BouncedAt' => gmdate( 'Y-m-d\TH:i:s' ) . 'Z', 'Content' => 'private bounce content' );
	pipeline_assert( 200 === $call( $bounce ) );
	pipeline_assert( 200 === $call( array_replace( $delivery, array( 'Recipient' => 'stranger@example.com' ) ) ) );
	pipeline_assert( 200 === $call( array_replace( $delivery, array( 'Recipient' => 'ONE@example.com' ) ) ) );
	pipeline_assert( 400 === $call( array_replace( $delivery, array( 'ServerID' => 1 ) ) ) );
	pipeline_assert( 2 === pipeline_count( 'scalyn_delivery_events' ) && 2 === pipeline_count( 'scalyn_mail_timeline' ) );
	$summary = $coverage->summarize( $message, 'accepted' );
	pipeline_assert( 'mixed' === $summary['state'] && 2 === $summary['expected'] );
	pipeline_assert( str_contains( $summary['explanation'], '1 of 2' ) );
	$stored = wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $test_prefix . 'scalyn_delivery_events' ), ARRAY_A ) ) . wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $test_prefix . 'scalyn_mail_timeline' ), ARRAY_A ) );
	foreach ( array( 'one@', 'two@', 'stranger', 'private', $user ) as $private ) { pipeline_assert( ! str_contains( $stored, $private ) ); }

	$step             = 'disabled';
	$state['enabled'] = false;
	pipeline_assert( 200 === $call( array_replace( $delivery, array( 'DeliveredAt' => gmdate( 'Y-m-d\TH:i:s' ) . '.2Z' ) ) ) );
	pipeline_assert( 2 === pipeline_count( 'scalyn_delivery_events' ) );
	pipeline_assert( null === $tracker->prepare( new MailMessage( strtolower( wp_generate_uuid4() ), 'sender@example.com', array( 'one@example.com' ), 'S', 'B' ), 'postmark' ) );
	pipeline_assert( 1 === pipeline_count( 'scalyn_delivery_attempts' ) );

	$step = 'application-passwords';
	$_SERVER['REQUEST_URI'] = '/bernz/wp-json/scalyn-mail-relay/v1/webhooks/postmark/' . $source;
	pipeline_assert( false === PostmarkWebhookEndpoint::exclude_application_passwords( true ) );
	$_SERVER['REQUEST_URI'] = '/bernz/wp-json/wp/v2/posts';
	pipeline_assert( true === PostmarkWebhookEndpoint::exclude_application_passwords( true ) );
	$passed = true;
} catch ( Throwable $error ) {
	// Never echo SQL, credentials, addresses or raw exceptions.
	fwrite( STDERR, "Delivery pipeline smoke check failed at step: {$step}.\n" );
} finally {
	foreach ( array_reverse( $tables ) as $table ) {
		if ( ! preg_match( '/^scalyn_qa_[a-f0-9]{12}_scalyn_(delivery_(keys|attempts|recipients|events)|mail_timeline)$/D', $table ) || strpos( $table, $test_prefix ) !== 0 ) { $cleanup = false; continue; }
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { $cleanup = false; }
	}
	$wpdb->prefix = $original_prefix;
	// The budget row uses the real options table; remove this synthetic source's row.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name=%s', $wpdb->options, $budget ) );
	$cleanup = $cleanup && null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $budget ) );
	$wpdb->suppress_errors( $old_suppression );
}
echo $cleanup ? "Disposable QA tables and budget row removed.\n" : "QA cleanup incomplete; inspect scalyn_qa_ tables and scalyn_webhook_budget_ options.\n";
if ( $passed && $cleanup ) { echo "PASS: dispatch association, acknowledgement, authenticated receiver, duplicate/bounce/unmatched handling, coverage, disabled-source and Application Password exclusion. No mail sent; no settings saved.\n"; }
exit( $passed && $cleanup ? 0 : 1 );
