<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DeliveryEvidenceSchema;
use Scalyn\MailRelay\Database\Migrator;

require_once dirname(__DIR__) . '/fixtures/delivery-schema-wpdb.php';

final class DeliveryEvidenceSchemaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();
		$GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.4.0'];
		$GLOBALS['_test_dbdelta_queries']=[];
	}
	protected function tearDown(): void {
		unset($GLOBALS['wpdb']);
		$GLOBALS['_test_wp_options']=[];
		$GLOBALS['_test_dbdelta_queries']=[];
	}
	public function test_additive_transactional_schema_is_verified_and_idempotent(): void {
		Migrator::migrate();
		$this->assertSame('0.7.0',get_option('scalyn_mail_relay_db_version'));
		$this->assertCount(5,$GLOBALS['_test_dbdelta_queries']);
		$sql=implode("\n",$GLOBALS['_test_dbdelta_queries']);
		$this->assertSame(5,substr_count($sql,'ENGINE=InnoDB'));
		$this->assertStringContainsString('UNIQUE KEY source_event (source_id,event_key)',$sql);
		$this->assertStringContainsString('PRIMARY KEY  (message_uuid,recipient_token)',$sql);
		$this->assertStringContainsString('KEY source_message (source_id,provider,provider_message_id)',$sql);
		$this->assertStringContainsString('occurred_at datetime(6)',$sql);
		$this->assertStringNotContainsString('UPDATE',$sql);
		$this->assertStringNotContainsString('DROP',$sql);
		$this->assertSame([],$GLOBALS['wpdb']->inserts);
		Migrator::migrate();
		$this->assertCount(5,$GLOBALS['_test_dbdelta_queries']);
	}
	public function test_partial_schema_failure_never_advances_version_and_retry_is_safe(): void {
		foreach (['engine'=>'MyISAM','missingColumn'=>true,'brokenIndex'=>true] as $property=>$value) {
			$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();
			$GLOBALS['wpdb']->$property=$value;
			try { Migrator::migrate(); $this->fail('Migration must fail'); }
			catch (RuntimeException $error) { $this->assertSame('Delivery evidence migration failed.',$error->getMessage()); }
			$this->assertSame('0.4.0',get_option('scalyn_mail_relay_db_version'));
		}
		$GLOBALS['wpdb']=new DeliverySchemaWpdbStub();
		Migrator::migrate();
		$this->assertSame('0.7.0',get_option('scalyn_mail_relay_db_version'));
	}
	public function test_key_retirement_upgrade_from_applied_05_is_additive_and_fail_closed(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.5.0';
		$GLOBALS['wpdb']->missingRetirement=true;
		try { Migrator::migrate(); $this->fail('Must verify new column'); }
		catch(RuntimeException $e) { $this->assertSame('Delivery key retirement migration failed.',$e->getMessage()); }
		$this->assertSame('0.5.0',get_option('scalyn_mail_relay_db_version'));
		$GLOBALS['wpdb']->missingRetirement=false;
		Migrator::migrate();
		$this->assertSame('0.7.0',get_option('scalyn_mail_relay_db_version'));
		$this->assertStringContainsString('retired_at datetime NULL',$GLOBALS['_test_dbdelta_queries'][0]);
		$this->assertCount(2,$GLOBALS['_test_dbdelta_queries']);
	}
}
