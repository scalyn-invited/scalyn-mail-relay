<?php
/**
 * Opt-in, socket-only recovery-history pagination regression against real WordPress wpdb/MariaDB.
 * Usage: php -d extension=/path/to/mysqli.so tests/integration/recovery-history-real-db.php WP_FIXTURE_ROOT SOCKET [REPOSITORY_PHP] [DB_USER]
 * REPOSITORY_PHP defaults to this checkout's includes/Database/RecoveryQueueRepository.php;
 * pass a separately materialized origin/develop version to verify the regression is caught.
 * Creates and drops only its own random schema. No WordPress installation, HTTP, mail, or providers.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$wp = $argv[1] ?? '';
$socket = $argv[2] ?? '';
$source = $argv[3] ?? $root . '/includes/Database/RecoveryQueueRepository.php';
$db_user = $argv[4] ?? getenv('USER');
if (!is_file($wp . '/wp-includes/class-wpdb.php') || !is_file($source) || @filetype($socket) !== 'socket') {
    throw new RuntimeException('A WordPress wpdb fixture, repository PHP file, and local Unix socket are required.');
}
if (!is_string($db_user) || '' === $db_user) {
    throw new RuntimeException('A local database username is required.');
}
if (!extension_loaded('mysqli')) {
    throw new RuntimeException('The mysqli extension is required.');
}

define('ABSPATH', rtrim($wp, '/') . '/');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
require $root . '/tests/fixtures/wordpress/wp-stubs.php';
function has_filter($name, $callback = false) {
    foreach ($GLOBALS['_test_wp_filters'][$name] ?? [] as $priority => $callbacks) {
        foreach ($callbacks as [$registered]) {
            if ($registered === $callback) {
                return $priority;
            }
        }
    }
    return false;
}
function is_multisite(): bool { return false; }
function wp_load_translations_early(): void {}
function wp_debug_backtrace_summary(...$args): string { return ''; }
function _doing_it_wrong(...$args): void { throw new RuntimeException('Unexpected wpdb API misuse.'); }
require ABSPATH . 'wp-includes/class-wpdb.php';
require $root . '/includes/Database/SubmissionAttemptRepository.php';
require $source;

function assert_history(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Connect over the supplied socket; reject any daemon which also accepts TCP before creating data.
$db = new mysqli('localhost', $db_user, '', '', 0, $socket);
assert_history('1' === (string) $db->query('SELECT @@skip_networking')->fetch_row()[0], 'Server must have @@skip_networking=1.');
assert_history(realpath($socket) === realpath((string) $db->query('SELECT @@socket')->fetch_row()[0]), 'Connected server socket differs from requested socket.');
$schema = 'scalyn_qa07_history_' . bin2hex(random_bytes(8));
assert_history((bool) preg_match('/^scalyn_qa07_history_[0-9a-f]{16}$/D', $schema), 'Unsafe test schema name.');
// Never enter the DROP path unless this invocation created the exact schema.
if (true !== $db->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4")) {
    $db->close();
    throw new RuntimeException('Could not create dedicated history schema.');
}
try {
    // A private wpdb instance and prefix: never load an installed WordPress site.
    $wpdb = new wpdb($db_user, '', $schema, 'localhost:' . $socket);
    $wpdb->set_prefix('qa07_');
    $wpdb->suppress_errors(true);
    assert_history('1' === (string) $wpdb->get_var('SELECT @@skip_networking'), 'wpdb must use the socket-only daemon.');
    assert_history(realpath($socket) === realpath((string) $wpdb->get_var('SELECT @@socket')), 'wpdb socket differs from requested socket.');
    assert_history($schema === $wpdb->get_var('SELECT DATABASE()'), 'wpdb must be in the dedicated schema.');
    $table = $wpdb->prefix . 'scalyn_recovery_queue';
    // Read-model columns and a synthetic, non-secret payload to test projection safety.
    assert_history(false !== $wpdb->query("CREATE TABLE `$table` (
        logical_uuid char(36) NOT NULL PRIMARY KEY,
        payload mediumtext NOT NULL,
        state varchar(16) NOT NULL,
        attempt_count tinyint unsigned NOT NULL,
        next_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        created_at datetime NOT NULL,
        reason varchar(32) NOT NULL,
        KEY created_at (created_at, logical_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=ascii"), 'Could not create private history table.');

    $same = '2026-10-21 01:00:00';
    $older = '2026-10-20 01:00:00';
    $expected = [];
    for ($id = 60; $id >= 1; --$id) {
        $expected[] = sprintf('11111111-1111-4111-8111-%012d', $id);
    }
    $old_uuid = '22222222-2222-4222-8222-222222222222';
    foreach (array_merge($expected, [$old_uuid]) as $uuid) {
        $at = $uuid === $old_uuid ? $older : $same;
        assert_history(1 === $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (logical_uuid,payload,state,attempt_count,next_at,expires_at,created_at,reason) VALUES (%s,%s,%s,%d,%s,%s,%s,%s)',
            $table, $uuid, 'synthetic-no-mail', 'accepted', 0, $at, $at, $at, 'accepted'
        )), 'Synthetic insert failed.');
    }
    $expected[] = $old_uuid;
    assert_history(61 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)), 'Expected 61 persisted synthetic rows.');

    $repository = new \Scalyn\MailRelay\Database\RecoveryQueueRepository();
    $first = $repository->summaries();
    assert_history(50 === count($first), 'First page must be exactly 50 rows.');
    assert_history(!array_key_exists('payload', $first[0]), 'History summary must not project the payload.');
    $boundary = end($first);
    assert_history($same === $boundary['created_at'] && $expected[49] === $boundary['logical_uuid'], 'Page boundary must fall inside the shared timestamp.');
    $second = $repository->summaries($boundary['created_at'], $boundary['logical_uuid']);
    $ids = array_column(array_merge($first, $second), 'logical_uuid');
    assert_history(11 === count($second), 'Composite cursor must preserve 10 same-second rows and one older row.');
    assert_history($expected === $ids, 'Composite pages must contain all rows in order, with no skips or duplicates.');
    assert_history(count($ids) === count(array_unique($ids)), 'Composite pages must have distinct UUIDs.');
    $last = end($second);
    assert_history([] === $repository->summaries($last['created_at'], $last['logical_uuid']), 'Cursor after final row must be empty.');
    $legacy = $repository->summaries($same);
    assert_history([$old_uuid] === array_column($legacy, 'logical_uuid'), 'Timestamp-only cursor must exclude every equal-timestamp row.');
    assert_history([] === $repository->summaries($older), 'Timestamp-only cursor must exclude the exact timestamp.');
    echo "PASS: 61 persisted rows; 50/11 composite pages; no skips/duplicates; payload excluded; legacy timestamp-exclusive; socket-only wpdb/MariaDB\n";
} finally {
    // Drop exactly the generated schema, even if an assertion fails, and verify absence independently.
    try {
        $db->query("DROP DATABASE `$schema`");
        assert_history(0 === (int) $db->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$schema'")->fetch_row()[0], 'Dedicated schema remains after DROP.');
        echo "CLEANUP: dedicated schema dropped and absence read back ($schema)\n";
    } finally {
        if (isset($wpdb)) {
            $wpdb->close();
        }
        $db->close();
    }
}
