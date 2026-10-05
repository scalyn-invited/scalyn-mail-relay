<?php
/** CLI-only disposable-table check. Never connects to a mail provider or sends mail. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,5) . '/wp-load.php';

use Scalyn\MailRelay\Database\ConnectionEvidenceSchema;
use Scalyn\MailRelay\Database\ConnectionEvidenceRepository;

global $wpdb;
$originalPrefix=$wpdb->prefix;
$testPrefix='scalyn_qa_connection_'.bin2hex(random_bytes(6)).'_';
$table=$testPrefix.'scalyn_connection_evidence';
$suppression=$wpdb->suppress_errors(true);
$ready=static fn()=> '0.9.0';
$passed=false;
$cleanup=false;
try {
	$wpdb->prefix=$testPrefix;
	ConnectionEvidenceSchema::migrate();
	ConnectionEvidenceSchema::migrate();
	add_filter('pre_option_scalyn_mail_relay_db_version',$ready);
	$repo=new ConnectionEvidenceRepository();
	$rev='11111111-1111-4111-8111-111111111111';
	$other='22222222-2222-4222-8222-222222222222';
	$repo->record($rev,'smtp','passed','2026-10-05 00:00:00.000000','2026-10-05 00:01:00.000000');
	$repo->record($rev,'smtp','failed','2026-10-05 00:02:00.000000','2026-10-05 00:03:00.000000');
	// Delayed older success must not replace a newer failure.
	$repo->record($rev,'smtp','passed','2026-10-05 00:00:00.000000','2026-10-05 00:04:00.000000');
	if ($repo->find($rev,'smtp')!==['status'=>'failed','started_at'=>'2026-10-05 00:02:00.000000','checked_at'=>'2026-10-05 00:03:00.000000']) { throw new RuntimeException('Ordering failed'); }
	if (null!==$repo->find($other,'smtp') || null!==$repo->find($rev,'postmark')) { throw new RuntimeException('Scope failed'); }
	$repo->record($other,'smtp','unknown','2026-10-05 00:04:00.000000','2026-10-05 00:05:00.000000');
	if (1!==$repo->delete_expired('2026-10-05 00:04:00.000000') || null!==$repo->find($rev,'smtp') || 'unknown'!==$repo->find($other,'smtp')['status']) { throw new RuntimeException('Retention failed'); }
	$passed=true;
} catch (Throwable $error) {
	// Never expose provider data, SQL or local database details.
	$passed=false;
} finally {
	remove_filter('pre_option_scalyn_mail_relay_db_version',$ready);
	$wpdb->prefix=$originalPrefix;
	if (preg_match('/^scalyn_qa_connection_[0-9a-f]{12}_scalyn_connection_evidence$/D',$table)) {
		$cleanup=false!==$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$table));
	}
	$wpdb->suppress_errors($suppression);
}
echo $passed && $cleanup ? "PASS: schema, scoped reads, late-result ordering and retention; disposable table removed.\n" : "FAIL: connection evidence smoke or disposable cleanup failed.\n";
exit($passed && $cleanup ? 0 : 1);
