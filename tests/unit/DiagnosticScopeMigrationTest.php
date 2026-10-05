<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\Migrator;
require_once dirname(__DIR__) . '/fixtures/delivery-schema-wpdb.php';

final class DiagnosticScopeMigrationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.3.0'];
		$GLOBALS['_test_dbdelta_queries']=[];
		$GLOBALS['wpdb']=new class extends DeliverySchemaWpdbStub {
			public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
		};
	}
	public function test_additive_scope_migration_preserves_legacy_nulls_and_is_idempotent(): void {
		$GLOBALS['wpdb']->get_col_return=['configuration_id','provider_id','sending_domain'];
		$GLOBALS['wpdb']->get_results_return=[[],[],[]];
		Migrator::migrate();
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
		$sql=$GLOBALS['_test_dbdelta_queries'][0];
		$this->assertStringContainsString('configuration_id char(36) NULL',$sql);
		$this->assertStringContainsString('KEY configuration_created (configuration_id,created_at,id)',$sql);
		$this->assertStringNotContainsString('DROP',$sql);
		Migrator::migrate();
		$this->assertCount(8,$GLOBALS['_test_dbdelta_queries']);
	}
	public function test_missing_index_does_not_advance_schema_version(): void {
		$GLOBALS['wpdb']->get_col_return=['configuration_id','provider_id','sending_domain'];
		try { Migrator::migrate(); $this->fail('Expected failure'); }
		catch(RuntimeException $error) { $this->assertSame('Diagnostic scope migration failed.',$error->getMessage()); }
		$this->assertSame('0.3.0',get_option('scalyn_mail_relay_db_version'));
	}
}
