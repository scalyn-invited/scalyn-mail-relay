<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\Migrator;

final class AlertEngineSchemaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = ['scalyn_mail_relay_db_version' => '0.14.0'];
		$GLOBALS['wpdb'] = new class extends WpdbStub {
			public string $last_error = '';
			public array $engines = ['wp_scalyn_alerts' => 'MyISAM', 'wp_scalyn_alert_notifications' => 'InnoDB', 'wp_unrelated' => 'MyISAM'];
			public array $altered = [];
			public string $fault = '';
			public int $reads = 0;
			public function get_var(string $query): mixed {
				$call = end($this->prepare_calls);
				++$this->reads;
				$this->last_error = ('metadata' === $this->fault || ('post-metadata' === $this->fault && $this->reads > 1)) ? 'private driver details' : '';
				return $this->engines[$call['args'][0]] ?? null;
			}
			public function query(string $query): int|false {
				$call = end($this->prepare_calls);
				$table = $call['args'][0];
				$this->altered[] = $table;
				$this->last_error = in_array($this->fault, ['alter', 'alter-error'], true) ? 'private driver details' : '';
				if ('alter' === $this->fault) { return false; }
				if ('ineffective' !== $this->fault) { $this->engines[$table] = 'InnoDB'; }
				return 0;
			}
		};
	}
	protected function tearDown(): void {
		unset($GLOBALS['wpdb']);
		$GLOBALS['_test_wp_options'] = [];
	}
	public function test_failures_keep_installed_version_and_do_not_leak_driver_details(): void {
		foreach (['metadata', 'alter', 'alter-error', 'post-metadata', 'ineffective'] as $fault) {
			$this->setUp();
			$GLOBALS['wpdb']->fault = $fault;
			try { Migrator::migrate(); $this->fail('Expected failure: ' . $fault); }
			catch (RuntimeException $error) { $this->assertSame('Alert engine migration failed.', $error->getMessage()); }
			$this->assertSame('0.14.0', get_option('scalyn_mail_relay_db_version'));
			$this->assertSame('metadata' === $fault ? [] : ['wp_scalyn_alerts'], $GLOBALS['wpdb']->altered);
		}
	}
	public function test_missing_or_unsupported_engine_never_attempts_conversion(): void {
		foreach ([null, 'MEMORY', 'Aria', ''] as $engine) {
			$GLOBALS['wpdb']->engines['wp_scalyn_alerts'] = $engine;
			try { Migrator::migrate(); $this->fail('Expected failure'); }
			catch (RuntimeException $error) { $this->assertSame('Alert engine migration failed.', $error->getMessage()); }
			$this->assertSame('0.14.0', get_option('scalyn_mail_relay_db_version'));
			$this->assertSame([], $GLOBALS['wpdb']->altered);
		}
	}
	public function test_existing_innodb_requires_no_alter(): void {
		$GLOBALS['wpdb']->engines['wp_scalyn_alerts'] = 'InnoDB';
		Migrator::migrate();
		$this->assertSame([], $GLOBALS['wpdb']->altered);
		$this->assertSame('0.15.0', get_option('scalyn_mail_relay_db_version'));
	}
	public function test_failed_version_write_retries_without_reconverting(): void {
		$GLOBALS['_test_wp_option_write_failures']['scalyn_mail_relay_db_version'] = true;
		try {
			try { Migrator::migrate(); $this->fail('Expected version write failure'); }
			catch (RuntimeException $error) { $this->assertSame('Database version could not be saved.', $error->getMessage()); }
			$this->assertSame('0.14.0', get_option('scalyn_mail_relay_db_version'));
			$this->assertSame('InnoDB', $GLOBALS['wpdb']->engines['wp_scalyn_alerts']);
		} finally { unset($GLOBALS['_test_wp_option_write_failures']['scalyn_mail_relay_db_version']); }
		Migrator::migrate();
		$this->assertSame(['wp_scalyn_alerts'], $GLOBALS['wpdb']->altered);
		$this->assertSame('0.15.0', get_option('scalyn_mail_relay_db_version'));
	}
	public function test_fresh_alert_creation_explicitly_requests_innodb(): void {
		$GLOBALS['_test_dbdelta_queries'] = [];
		$GLOBALS['wpdb'] = new class extends WpdbStub {
			public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
		};
		(new ReflectionMethod(Migrator::class, 'create_foundation_tables'))->invoke(null);
		$alerts = array_values(array_filter($GLOBALS['_test_dbdelta_queries'], fn($sql) => str_contains($sql, 'CREATE TABLE wp_scalyn_alerts ')));
		$this->assertCount(1, $alerts);
		$this->assertStringContainsString('ENGINE=InnoDB', $alerts[0]);
	}
	public function test_installed_014_myisam_alerts_are_repaired_without_touching_other_tables(): void {
		$db = $GLOBALS['wpdb'];
		Migrator::migrate();
		$this->assertSame('InnoDB', $db->engines['wp_scalyn_alerts']);
		$this->assertSame(['wp_scalyn_alerts'], $db->altered);
		$this->assertSame('MyISAM', $db->engines['wp_unrelated']);
		$this->assertSame('0.15.0', get_option('scalyn_mail_relay_db_version'));
		Migrator::migrate();
		$this->assertSame(['wp_scalyn_alerts'], $db->altered);
	}
}
