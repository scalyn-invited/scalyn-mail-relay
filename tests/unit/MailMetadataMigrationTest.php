<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\Migrator;
require_once dirname(__DIR__) . '/fixtures/delivery-schema-wpdb.php';

final class MailMetadataMigrationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array('scalyn_mail_relay_db_version'=>'0.2.0');
		$GLOBALS['_test_dbdelta_queries'] = array();
		$GLOBALS['wpdb'] = new class extends DeliverySchemaWpdbStub {
			public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
		};
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_options'] = array();
		unset($GLOBALS['wpdb'], $GLOBALS['_test_dbdelta_queries']);
	}
	public function test_additive_upgrade_advances_only_after_column_verification_and_is_idempotent(): void {
		$GLOBALS['wpdb']->get_col_return=array('id','logged_recipients','logged_subject');
		$GLOBALS['wpdb']->get_col_returns=array(array('id','logged_recipients','logged_subject'),array('id','configuration_id','provider_id','sending_domain'));
		$GLOBALS['wpdb']->get_results_return=array(array(),array(),array());
		Migrator::migrate();
		$this->assertSame('0.9.0',get_option('scalyn_mail_relay_db_version'));
		$this->assertStringContainsString('logged_recipients text NULL',$GLOBALS['_test_dbdelta_queries'][0]);
		$this->assertStringContainsString('logged_subject varchar(255) NULL',$GLOBALS['_test_dbdelta_queries'][0]);
		$this->assertStringNotContainsString('DROP',$GLOBALS['_test_dbdelta_queries'][0]);
		Migrator::migrate();
		$this->assertCount(9,$GLOBALS['_test_dbdelta_queries']);
	}
	public function test_failed_verification_does_not_advance_version(): void {
		try { Migrator::migrate(); $this->fail('Expected migration failure'); }
		catch(RuntimeException $error) { $this->assertSame('Mail metadata migration failed.',$error->getMessage()); }
		$this->assertSame('0.2.0',get_option('scalyn_mail_relay_db_version'));
	}
}
