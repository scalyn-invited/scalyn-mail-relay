<?php
/** CLI-only: real dbDelta against one disposable table. No email or live-row writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,5) . '/wp-load.php';

use Scalyn\MailRelay\Database\MailConfigurationSchema;

global $wpdb;
$originalPrefix=$wpdb->prefix;
$testPrefix='scalyn_qa_revision_'.bin2hex(random_bytes(6)).'_';
$table=$testPrefix.'scalyn_mail_logs';
$suppression=$wpdb->suppress_errors(true);
$passed=false;
$cleanup=false;
try {
	if (false===$wpdb->query($wpdb->prepare('CREATE TABLE %i (id bigint unsigned NOT NULL AUTO_INCREMENT, message_uuid char(36) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB',$table))) {
		throw new RuntimeException('Setup failed');
	}
	if (false===$wpdb->insert($table,['message_uuid'=>'11111111-1111-4111-8111-111111111111','created_at'=>'2026-10-05 00:00:00'])) { throw new RuntimeException('Fixture failed'); }
	$wpdb->prefix=$testPrefix;
	MailConfigurationSchema::migrate();
	MailConfigurationSchema::migrate();
	$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=1',$table),ARRAY_A);
	if (!array_key_exists('configuration_id',$row) || null!==$row['configuration_id']) { throw new RuntimeException('Legacy row was attributed'); }
	$revision='22222222-2222-4222-8222-222222222222';
	if (false===$wpdb->insert($table,['message_uuid'=>'33333333-3333-4333-8333-333333333333','configuration_id'=>$revision,'created_at'=>'2026-10-05 00:01:00'])) { throw new RuntimeException('Scoped fixture failed'); }
	if ('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE configuration_id=%s',$table,$revision))) { throw new RuntimeException('Scope failed'); }
	$passed=true;
} catch (Throwable $error) {
	// Fixed output only; never emit SQL, credentials or exception messages.
	$passed=false;
} finally {
	$wpdb->prefix=$originalPrefix;
	if (preg_match('/^scalyn_qa_revision_[0-9a-f]{12}_scalyn_mail_logs$/D',$table)) {
		$cleanup=false!==$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table));
	}
	$wpdb->suppress_errors($suppression);
}
echo $passed && $cleanup ? "PASS: nullable migration, idempotency, legacy exclusion; disposable table removed.\n" : "FAIL: migration smoke or disposable cleanup failed.\n";
exit($passed && $cleanup ? 0 : 1);
