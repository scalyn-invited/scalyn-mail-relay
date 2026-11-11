<?php
/**
 * Offline real-wpdb recovery read regression. No database connection or SQL execution.
 * Usage: php -n tests/integration/recovery-read-wpdb.php WP_FIXTURE_ROOT [PLUGIN_ROOT]
 * wpdb must be the unmodified WordPress 6.5 fixture described in README.md.
 * Query preparation and driver outcomes are shims; native query(false), get_row,
 * get_results, suppress_errors and print_error behavior are exercised without mysqli.
 */
$fixture = $argv[1] ?? '';
$root = $argv[2] ?? dirname( __DIR__, 2 );
if ( ! is_file( $fixture . '/wp-includes/class-wpdb.php' ) || ! is_file( $root . '/includes/Database/RecoveryQueueRepository.php' ) ) {
	fwrite( STDERR, "Usage: recovery-read-wpdb.php WP_FIXTURE_ROOT [PLUGIN_ROOT]\n" );
	exit( 2 );
}
define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
define( 'SCALYN_MAIL_RELAY_PATH', rtrim( $root, '/' ) . '/' );
require dirname( __DIR__ ) . '/fixtures/wordpress/wp-stubs.php';
require $root . '/includes/Core/Autoloader.php';
Scalyn\MailRelay\Core\Autoloader::register();
require $fixture . '/wp-includes/class-wpdb.php';

final class OfflineRecoveryWpdb extends wpdb {
	public string $mode;
	public int $writes = 0;
	public int $reads = 0;
	public function __construct( string $mode ) {
		$this->mode = $mode;
		$this->prefix = 'fixture_';
		$this->ready = false;
		$this->check_current_query = false;
		$this->show_errors = false;
	}
	public function prepare( $query, ...$args ) { return $query; }
	public function get_caller() { return 'offline_recovery_fixture'; }
	public function query( $query ) {
		if ( ! str_starts_with( $query, 'SELECT ' ) ) {
			++$this->writes;
			return 1;
		}
		++$this->reads;
		if ( 'early' === $this->mode ) {
			return parent::query( $query ); // Native early false: no flush/error initialization.
		}
		$this->last_error = '';
		$this->last_result = array();
		if ( 'throw' === $this->mode ) {
			throw new RuntimeException( 'SYNTHETIC_PRIVATE_DRIVER_DETAIL' );
		}
		if ( 'sql' === $this->mode ) {
			$this->last_query = $query;
			$this->last_error = 'SYNTHETIC_PRIVATE_DRIVER_DETAIL';
			$this->print_error( $this->last_error ); // Native logging/suppression behavior.
			return false;
		}
		return 0;
	}
}

// Minimal display-error helpers; no WordPress bootstrap or translation loading.
function wp_load_translations_early() {}
function is_multisite() { return false; }

$checks = 0;
$failures = array();
foreach ( array( 'claim', 'history' ) as $reader ) {
	foreach ( array( false, true ) as $show_errors ) {
		foreach ( array( 'early', 'sql', 'throw', 'empty' ) as $mode ) {
			foreach ( array( false, true ) as $suppressed ) {
				$wpdb = new OfflineRecoveryWpdb( $mode );
				$wpdb->show_errors = $show_errors;
				$wpdb->suppress_errors( $suppressed );
				$wpdb->last_result = array( (object) array( 'logical_uuid' => 'SYNTHETIC_STALE_RESULT' ) );
				$log = tmpfile();
				$previous = ini_set( 'error_log', stream_get_meta_data( $log )['uri'] );
				$error = null;
				$result = null;
				ob_start();
				try {
					try {
						$result = 'claim' === $reader
							? ( new Scalyn\MailRelay\Database\RecoveryQueueRepository() )->claim( 1793397435 )
							: ( new Scalyn\MailRelay\Database\SubmissionAttemptRepository() )->history( '11111111-1111-4111-8111-111111111111' );
					} catch ( Throwable $caught ) { $error = $caught; }
					$output = ob_get_contents();
					rewind( $log );
					$logged = stream_get_contents( $log );
				} finally {
					ob_end_clean();
					ini_set( 'error_log', $previous );
					fclose( $log );
				}
				$expected = 'claim' === $reader ? 'Recovery queue read is unavailable.' : 'Submission history is unavailable.';
				$valid = 'empty' === $mode
					? null === $error && $result === ( 'claim' === $reader ? null : array() )
					: $error instanceof RuntimeException && $expected === $error->getMessage() && null === $error->getPrevious();
				$valid = $valid && '' === $output && '' === $logged && $suppressed === $wpdb->suppress_errors && $show_errors === $wpdb->show_errors && 0 === $wpdb->writes && 1 === $wpdb->reads;
				++$checks;
				if ( ! $valid ) { $failures[] = $reader . ':' . $mode . ':' . ( $suppressed ? 'suppressed' : 'normal' ) . ':' . ( $show_errors ? 'display' : 'hidden' ); }
			}
		}
	}
}
print json_encode( array( 'checks' => $checks, 'failures' => $failures, 'no_database_connection' => true, 'query_preparation_and_driver_outcomes_are_shims' => true ), JSON_PRETTY_PRINT ) . "\n";
exit( $failures ? 1 : 0 );
