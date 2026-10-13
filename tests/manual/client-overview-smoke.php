<?php
/** CLI-only real WordPress read-model/view QA; no mail, account or option writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 5).'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Scalyn\MailRelay\Agency\ManagedAccess;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Admin\ClientOverview;
use Scalyn\MailRelay\Admin\ClientReportController;
use Scalyn\MailRelay\Admin\Pages\DashboardPage;

$original_user=wp_get_current_user();
$actor=new WP_User(); $actor->ID=999999917; $actor->allcaps=['read'=>true];
$metadata=static fn($value,$id,$key,$single)=>$id===$actor->ID && $key===ManagedAccess::profile_key() ? 'client' : $value;
$access=new ManagedAccess();
$passed=false;
$stage='permissions';
add_filter('get_user_metadata',$metadata,10,4);
$access->register();
try {
	$GLOBALS['current_user']=$actor;
	if (!current_user_can(Capabilities::VIEW_CLIENT_REPORTS) || current_user_can(Capabilities::EXPORT_REPORTS)) throw new RuntimeException();
	$stage='summary read';
	$summary=ClientOverview::load();
	$stage='summary encoding';
	$csv=ClientReportController::encode($summary,'Test agency');
	if (!str_contains($csv,'not confirmed inbox delivery') || str_contains($csv,'message_uuid')) throw new RuntimeException();
	$stage='dashboard render';
	ob_start();
	try { (new DashboardPage())->render(); $html=ob_get_contents(); }
	finally { ob_end_clean(); }
	$stage='dashboard assertions';
	if (!str_contains($html,'Email Overview') || !str_contains($html,'Download summary CSV') || str_contains($html,'scalyn-run-diagnostics') || str_contains($html,'scalyn_deliverability_assess')) throw new RuntimeException();
	$passed=true;
} catch(Throwable $error) {
	fwrite(STDERR,'Client overview WordPress smoke failed at '.$stage.' ('.get_class($error)."); no mail was sent.\n");
} finally {
	$GLOBALS['current_user']=$original_user;
	remove_filter('get_user_metadata',$metadata,10);
	remove_filter('user_has_cap',[$access,'filter_capabilities'],20);
}
if($passed) echo "Client overview WordPress smoke passed (real read model and dashboard; synthetic actor; no sends/account/option writes).\n";
exit($passed ? 0 : 1);
