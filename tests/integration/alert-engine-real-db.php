<?php
/**
 * Opt-in disposable real-DB regression (not auto-run by PHPUnit).
 * Usage: php -d extension=mysqli tests/integration/alert-engine-real-db.php WP_FIXTURE_ROOT SOCKET [upgrade|fresh] [PLUGIN_ROOT]
 * WP_FIXTURE_ROOT supplies unmodified WordPress 6.5 class-wpdb.php and upgrade.php.
 * Its admin.php/schema.php may be empty bootstrap shims: only dbDelta is invoked.
 * No WordPress installation, providers, HTTP requests, mail or recovery are booted.
 */

$repository = dirname(__DIR__, 2);
$root = $argv[4] ?? $repository;
$wp = rtrim($argv[1] ?? '', '/') . '/';
$socket = $argv[2] ?? '';
$mode = $argv[3] ?? 'upgrade';
if (@filetype($socket) !== 'socket') { throw new RuntimeException('A local Unix socket is required.'); }
if (!in_array($mode, ['upgrade', 'fresh'], true)) { throw new RuntimeException('Unknown scenario.'); }
define('ABSPATH', $wp);
define('SCALYN_MAIL_RELAY_PATH', $root . '/');
define('WP_CONTENT_DIR', $wp . 'nonexistent-content');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$source = file_get_contents($root . '/scalyn-mail-relay.php');
preg_match("/define\( 'SCALYN_MAIL_RELAY_DB_VERSION', '([^']+)' \)/", $source, $match);
define('SCALYN_MAIL_RELAY_DB_VERSION', $match[1]);

function get_option(string $name, $default = false) {
	global $wpdb;
	$value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->prefix . 'options', $name));
	return null === $value ? $default : unserialize($value);
}
function update_option(string $name, $value, $autoload = null): bool {
	global $wpdb;
	return false !== $wpdb->query($wpdb->prepare('REPLACE INTO %i (option_name,option_value) VALUES (%s,%s)', $wpdb->prefix . 'options', $name, serialize($value)));
}
require $repository . '/tests/fixtures/wordpress/wp-stubs.php';
function has_filter($name, $callback = false) {
	foreach ($GLOBALS['_test_wp_filters'][$name] ?? [] as $priority => $callbacks) {
		foreach ($callbacks as [$registered]) { if ($registered === $callback) { return $priority; } }
	}
	return false;
}
function is_multisite() { return false; }
function wp_load_translations_early() {}
function wp_debug_backtrace_summary(...$args) { return ''; }
function _doing_it_wrong(...$args) { throw new RuntimeException('Unexpected WordPress API misuse.'); }
require $wp . 'wp-includes/class-wpdb.php';
require $root . '/includes/Core/Autoloader.php';
\Scalyn\MailRelay\Core\Autoloader::register();

$schema = 'scalyn_alert_engine_test_' . bin2hex(random_bytes(6));
$db = new mysqli('localhost', 'jayryan_ai', '', '', 0, $socket);
if (1 !== (int)$db->query('SELECT @@skip_networking')->fetch_row()[0]) { throw new RuntimeException('Server must be socket-only.'); }
$db->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
$checks = 0;
function check(bool $condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	++$checks;
}
try {
	$wpdb = new wpdb('jayryan_ai', '', $schema, 'localhost:' . $socket);
	$wpdb->set_prefix('wppf_');
	$wpdb->suppress_errors(true);
	check('1' === (string)$wpdb->get_var('SELECT @@skip_networking'), 'Server must be socket-only.');
	$wpdb->query('SET SESSION default_storage_engine=MyISAM');
	$wpdb->query('CREATE TABLE wppf_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL) ENGINE=InnoDB');
	$wpdb->query('CREATE TABLE wppf_unrelated (id int PRIMARY KEY) ENGINE=MyISAM');
	if ('upgrade' === $mode) {
		// Build exactly the installed foundation/outbox definitions, without running newer migrations.
		foreach (['create_foundation_tables', 'create_alert_notifications'] as $method) {
			(new ReflectionMethod(\Scalyn\MailRelay\Database\Migrator::class, $method))->invoke(null);
		}
		$wpdb->query('ALTER TABLE wppf_scalyn_alerts ENGINE=MyISAM');
		$wpdb->query("INSERT INTO wppf_scalyn_alerts (alert_uuid,alert_type,severity,title,created_at) VALUES ('00000000-0000-4000-8000-000000000001','health','high','Synthetic fixture','2026-01-01 00:00:00')");
		update_option('scalyn_mail_relay_db_version', '0.14.0');
		$before = $wpdb->get_results('SELECT * FROM wppf_scalyn_alerts', ARRAY_A);
		$indexes = $wpdb->get_results('SHOW INDEX FROM wppf_scalyn_alerts', ARRAY_A);
		foreach ($indexes as &$index) { unset($index['Cardinality']); } unset($index);
		$repository = new \Scalyn\MailRelay\Alerts\AlertRepository();
		check(!$repository->exclusive(static function () { throw new RuntimeException('Must not run before repair.'); }), 'MyISAM must fail the alert guard.');
	}
	if ('fresh' === $mode) {
		(new ReflectionMethod(\Scalyn\MailRelay\Database\Migrator::class, 'create_foundation_tables'))->invoke(null);
		check('InnoDB' === $wpdb->get_var("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wppf_scalyn_alerts'"), 'Fresh alerts must explicitly use InnoDB before repair.');
	}
	\Scalyn\MailRelay\Database\Migrator::migrate();
	foreach (['scalyn_alerts', 'scalyn_alert_notifications'] as $suffix) {
		check('InnoDB' === $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'wppf_' . $suffix)), "$suffix must be InnoDB");
	}
	check('0.15.0' === get_option('scalyn_mail_relay_db_version'), 'Verified upgrade must advance to 0.15.0.');
	check('MyISAM' === $wpdb->get_var("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wppf_unrelated'"), 'Unrelated table must be untouched.');
	if ('upgrade' === $mode) {
		check($before === $wpdb->get_results('SELECT * FROM wppf_scalyn_alerts', ARRAY_A), 'Alert rows must be preserved.');
		$afterIndexes = $wpdb->get_results('SHOW INDEX FROM wppf_scalyn_alerts', ARRAY_A);
		foreach ($afterIndexes as &$index) { unset($index['Cardinality']); } unset($index);
		check($indexes === $afterIndexes, 'Alert index contract must be preserved.');
	}
	$repository = new \Scalyn\MailRelay\Alerts\AlertRepository();
	$ran = false;
	check($repository->exclusive(static function () use (&$ran) { $ran = true; }), 'Repaired alert exclusion must succeed.');
	check($ran && 'completed' === $repository->status()['state'], 'Local no-op evaluation must complete.');
	$queries = $wpdb->num_queries;
	\Scalyn\MailRelay\Database\Migrator::migrate();
	check($wpdb->num_queries === $queries + 1, 'Current version retry must only read the version.');
	update_option('scalyn_mail_relay_db_version', '0.14.0');
	$wpdb->query('RENAME TABLE wppf_scalyn_alerts TO wppf_scalyn_alerts_hidden');
	try {
		try { \Scalyn\MailRelay\Database\Migrator::migrate(); throw new LogicException('Missing alert table must fail.'); }
		catch (RuntimeException $error) { check('Alert engine migration failed.' === $error->getMessage(), 'Migration failure must be generic.'); }
		check('0.14.0' === get_option('scalyn_mail_relay_db_version'), 'Missing table must not advance installed version.');
	} finally { $wpdb->query('RENAME TABLE wppf_scalyn_alerts_hidden TO wppf_scalyn_alerts'); }
	\Scalyn\MailRelay\Database\Migrator::migrate();
	check('0.15.0' === get_option('scalyn_mail_relay_db_version'), 'Retry after metadata recovery must succeed.');
	echo "PASS $mode: $checks checks; WordPress 6.5 wpdb/dbDelta; MariaDB " . $wpdb->db_version() . "; session default MyISAM\n";
} finally {
	$db->query("DROP DATABASE `$schema`");
	echo "Dropped dedicated schema $schema\n";
}
