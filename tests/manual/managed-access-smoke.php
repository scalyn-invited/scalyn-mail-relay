<?php
/** CLI-only WordPress capability integration. Synthetic actor, no account or policy writes. */
if ( PHP_SAPI !== 'cli' ) { http_response_code(404); exit; }
require dirname(__DIR__, 5) . '/wp-load.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Scalyn\MailRelay\Agency\AgencyRepository;
use Scalyn\MailRelay\Agency\ManagedAccess;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;

$original_user = wp_get_current_user();
$actor = new WP_User();
$actor->ID = 999999917;
$profile = 'client';
$policy = ['managed' => true, 'smtp' => true, 'providers' => true, 'critical' => true];
$metadata = static function($value, $id, $key, $single) use (&$profile, $actor) {
	return $id === $actor->ID && $key === ManagedAccess::profile_key() ? $profile : $value;
};
$read_policy = static function() use (&$policy) { return $policy; };
$access = new ManagedAccess();
$check = static function(bool $value):void { if (!$value) throw new RuntimeException('Capability assertion failed'); };
$passed = false;
add_filter('get_user_metadata', $metadata, 10, 4);
add_filter('pre_option_' . AgencyRepository::OPTION, $read_policy);
$access->register();
try {
	$GLOBALS['current_user'] = $actor;
	foreach (['client' => [Capabilities::VIEW_DASHBOARD], 'support' => [Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_LOGS, Capabilities::RUN_DIAGNOSTICS, Capabilities::VIEW_AUDIT], 'auditor' => [Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_LOGS, Capabilities::VIEW_AUDIT], 'agency' => Capabilities::all()] as $name => $allowed) {
		$profile = $name;
		$actor->allcaps = ['read' => true];
		foreach (Capabilities::all() as $cap) $check(current_user_can($cap) === in_array($cap, $allowed, true));
		$check(!current_user_can('manage_options'));
		$check(current_user_can('read'));
	}
	$profile = 'none';
	$actor->allcaps = [Capabilities::MANAGE_SETTINGS => true, Capabilities::MANAGE_MAIL => true];
	$check(ManagedAccess::is_locked('critical'));
	try {
		(new SettingsRepository())->save(['advanced' => ['log_retention_days' => 12]]);
		throw new LogicException('Locked write unexpectedly succeeded');
	} catch (RuntimeException $error) {
		$check(str_contains($error->getMessage(), 'managed'));
	}
	$actor->allcaps = ['manage_options' => true];
	$profile = 'client';
	$check(!ManagedAccess::is_locked('critical'));
	foreach (Capabilities::all() as $cap) $check(current_user_can($cap));
	$passed = true;
} catch (Throwable $error) {
	fwrite(STDERR, "Managed access WordPress smoke failed; no mail was sent.\n");
} finally {
	$GLOBALS['current_user'] = $original_user;
	remove_filter('get_user_metadata', $metadata, 10);
	remove_filter('pre_option_' . AgencyRepository::OPTION, $read_policy);
	remove_filter('user_has_cap', [$access, 'filter_capabilities'], 20);
}
if ($passed) echo "Managed access WordPress smoke passed (synthetic actor; no policy/account writes or mail sends).\n";
exit($passed ? 0 : 1);
