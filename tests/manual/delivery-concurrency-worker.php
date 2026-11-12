<?php
/** CLI-only worker for disposable synthetic database concurrency checks. */
if (PHP_SAPI !== 'cli' || !preg_match('/^scalyn_qa_[a-f0-9]{12}_$/D',$argv[1] ?? '')) { exit(2); }
require dirname(__DIR__,5) . '/wp-load.php';

global $wpdb;
$wpdb->prefix=$argv[1];
$wpdb->suppress_errors(true);
try {
	// The parent fixture supplies synthetic data only; no live table names are allowed.
	$event=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE event_key=%s',$wpdb->prefix.'scalyn_delivery_events',str_repeat('e',64)),ARRAY_A);
	if(!$event) { throw new RuntimeException(); }
	unset($event['id']);
	$event['schema_version']=(int)$event['schema_version'];
	$event['event_key']=str_repeat('c',64);
	$event['occurred_at']=str_replace(' ','T',$event['occurred_at']).'Z';
	$event['received_at']=str_replace(' ','T',$event['received_at']).'Z';
	echo "READY\n";
	flush();
	$result=(new \Scalyn\MailRelay\Database\DeliveryEventRepository())->append($event,'2000-01-01 00:00:00');
	echo $result."\n";
} catch(Throwable $error) {
	fwrite(STDERR,"Synthetic concurrency worker failed.\n");
	exit(1);
}
