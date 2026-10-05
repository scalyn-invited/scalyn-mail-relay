<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\Migrator;

require_once dirname(__DIR__) . '/fixtures/delivery-schema-wpdb.php';

final class TransactionalTablesSchemaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.6.0'];
		$GLOBALS['wpdb']=new class extends DeliverySchemaWpdbStub {
			public string $last_error='';
			public array $engines=['wp_scalyn_diagnostics'=>'MyISAM','wp_scalyn_health_scores'=>'MyISAM','wp_scalyn_mail_logs'=>'MyISAM','wp_scalyn_mail_timeline'=>'MyISAM'];
			public array $altered=[];
			public ?string $failure=null;
			public bool $metadataError=false;
			public bool $ignoreConversion=false;
			public function get_var(string $query): mixed {
				$call=end($this->prepare_calls);
				$this->last_error=$this->metadataError ? 'private database error' : '';
				return $this->engines[$call['args'][0]] ?? null;
			}
			public function query(string $query): int|false {
				$call=end($this->prepare_calls);
				$table=$call['args'][0];
				$this->altered[]=$table;
				if ($table===$this->failure) { $this->last_error='private database error'; return false; }
				if (!$this->ignoreConversion) { $this->engines[$table]='InnoDB'; }
				return 0;
			}
		};
	}
	protected function tearDown(): void {
		unset($GLOBALS['wpdb']);
		$GLOBALS['_test_wp_options']=[];
	}
	public function test_upgrade_converts_only_four_allowlisted_tables_and_is_idempotent(): void {
		$db=$GLOBALS['wpdb'];
		$db->engines['wp_unrelated']='MyISAM';
		Migrator::migrate();
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
		$this->assertSame(['wp_scalyn_diagnostics','wp_scalyn_health_scores','wp_scalyn_mail_logs','wp_scalyn_mail_timeline'],$db->altered);
		$this->assertSame('MyISAM',$db->engines['wp_unrelated']);
		foreach ($db->prepare_calls as $call) {
			$this->assertContains($call['query'],['ALTER TABLE %i ENGINE=InnoDB','SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','SHOW COLUMNS FROM %i','SHOW INDEX FROM %i WHERE Key_name = %s']);
		}
		Migrator::migrate();
		$this->assertCount(4,$db->altered);
	}
	public function test_existing_innodb_tables_require_no_ddl(): void {
		$db=$GLOBALS['wpdb'];
		$db->engines=array_fill_keys(array_keys($db->engines),'InnoDB');
		Migrator::migrate();
		$this->assertSame([],$db->altered);
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
	}
	public function test_partial_failure_keeps_version_and_resumes_without_reconverting_success(): void {
		$db=$GLOBALS['wpdb'];
		$db->failure='wp_scalyn_health_scores';
		$this->assertMigrationFails();
		$this->assertSame('InnoDB',$db->engines['wp_scalyn_diagnostics']);
		$db->failure=null;
		Migrator::migrate();
		$this->assertSame(['wp_scalyn_diagnostics','wp_scalyn_health_scores','wp_scalyn_health_scores','wp_scalyn_mail_logs','wp_scalyn_mail_timeline'],$db->altered);
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
	}
	public function test_missing_or_unexpected_engine_fails_closed(): void {
		foreach ([null,'MEMORY'] as $engine) {
			$GLOBALS['wpdb']->engines['wp_scalyn_diagnostics']=$engine;
			$this->assertMigrationFails();
			$this->assertSame([],$GLOBALS['wpdb']->altered);
		}
	}
	public function test_metadata_error_and_silent_conversion_failure_cannot_advance_version(): void {
		$GLOBALS['wpdb']->metadataError=true;
		$this->assertMigrationFails();
		$this->assertSame([],$GLOBALS['wpdb']->altered);
		$GLOBALS['wpdb']->metadataError=false;
		$GLOBALS['wpdb']->ignoreConversion=true;
		$this->assertMigrationFails();
	}
	private function assertMigrationFails(): void {
		try { Migrator::migrate(); $this->fail('Expected migration failure'); }
		catch(RuntimeException $error) { $this->assertSame('Transactional table migration failed.',$error->getMessage()); }
		$this->assertSame('0.6.0',get_option('scalyn_mail_relay_db_version'));
	}
}
