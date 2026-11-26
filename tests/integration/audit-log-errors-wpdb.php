<?php
/**
 * Offline native-wpdb audit migration error boundary; no DB connection or SQL execution.
 * Usage: php -n tests/integration/audit-log-errors-wpdb.php WP_FIXTURE_ROOT [PLUGIN_ROOT]
 * The WordPress fixture is documented in README.md. Preparation and query outcomes
 * are shims; get_var(), suppress_errors() and print_error() are unmodified core.
 */
$fixture = $argv[1] ?? '';
$root = $argv[2] ?? dirname(__DIR__, 2);
if (!is_file($fixture . '/wp-includes/class-wpdb.php')) {
	fwrite(STDERR, "Supply a local WordPress fixture root.\n");
	exit(2);
}
define('ABSPATH', dirname(__DIR__) . '/fixtures/wordpress/');
require dirname(__DIR__) . '/fixtures/wordpress/wp-stubs.php';
require $fixture . '/wp-includes/class-wpdb.php';
require $root . '/includes/Database/AuditLogSchema.php';
function wp_load_translations_early() {}
function is_multisite() { return false; }

final class OfflineAuditWpdb extends wpdb {
	public string $mode;
	public string $engine;
	public int $alters = 0;
	public int $reads = 0;
	public function __construct(string $mode) {
		$this->prefix = 'synthetic_';
		$this->mode = $mode;
		$this->engine = 'existing' === $mode ? 'InnoDB' : 'MyISAM';
	}
	public function prepare($query, ...$args) { return $query; }
	public function get_caller() { return 'offline_audit_fixture'; }
	public function query($query) {
		$this->last_query = $query;
		$this->last_result = [];
		$this->last_error = '';
		$alter = str_starts_with($query, 'ALTER TABLE');
		if ($alter) { ++$this->alters; } else { ++$this->reads; }
		if ('throw' === $this->mode) { throw new RuntimeException('SYNTHETIC_PRIVATE_DRIVER_DETAIL'); }
		if (('metadata' === $this->mode && !$alter)
			|| ('alter' === $this->mode && $alter)
			|| ('post-metadata' === $this->mode && $this->reads > 1)) {
			$this->last_error = 'SYNTHETIC_PRIVATE_DRIVER_DETAIL';
			$this->print_error($this->last_error);
			return false;
		}
		if ($alter) {
			if ('ineffective' !== $this->mode) { $this->engine = 'InnoDB'; }
			return 0;
		}
		$this->last_result = [(object) ['ENGINE' => $this->engine]];
		return 1;
	}
}

$checks = 0;
$failures = [];
foreach (['success', 'existing', 'metadata', 'alter', 'post-metadata', 'ineffective', 'throw'] as $mode) {
	foreach ([false, true] as $suppressed) {
		foreach ([false, true] as $display) {
			$wpdb = new OfflineAuditWpdb($mode);
			$wpdb->suppress_errors($suppressed);
			$wpdb->show_errors = $display;
			$log = tmpfile();
			$previous = ini_set('error_log', stream_get_meta_data($log)['uri']);
			$error = null;
			ob_start();
			try {
				try { Scalyn\MailRelay\Database\AuditLogSchema::migrate(); }
				catch (Throwable $caught) { $error = $caught; }
				$output = ob_get_contents();
				rewind($log);
				$logged = stream_get_contents($log);
			} finally {
				ob_end_clean();
				ini_set('error_log', $previous);
				fclose($log);
			}
			$success = in_array($mode, ['success', 'existing'], true);
			$valid = $success ? null === $error
				: $error instanceof RuntimeException && 'Audit log engine migration failed.' === $error->getMessage() && null === $error->getPrevious();
			$valid = $valid && '' === $output && '' === $logged
				&& $suppressed === $wpdb->suppress_errors && $display === $wpdb->show_errors;
			$valid = $valid && $wpdb->alters === (in_array($mode, ['existing', 'metadata', 'throw'], true) ? 0 : 1);
			++$checks;
			if (!$valid) { $failures[] = [$mode, $suppressed, $display]; }
		}
	}
}
print json_encode(['checks' => $checks, 'failures' => $failures, 'no_database_connection' => true, 'query_preparation_and_outcomes_are_shims' => true], JSON_PRETTY_PRINT) . "\n";
exit($failures ? 1 : 0);
