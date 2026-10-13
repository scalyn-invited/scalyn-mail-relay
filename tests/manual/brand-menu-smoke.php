<?php
/** CLI-only native WordPress menu QA; branding is overridden only in this process. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,5).'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Scalyn\MailRelay\Admin\{AdminMenu,AgencyBranding};
use Scalyn\MailRelay\Agency\BrandingRepository;

global $menu,$submenu,$admin_page_hooks,$_registered_pages,$_parent_pages;
$menu=[];$submenu=[];$admin_page_hooks=[];$_registered_pages=[];$_parent_pages=[];
$repository=new BrandingRepository();$candidate=$repository->get()['logo_id'];
$icon=0;$name=BrandingRepository::defaults()['name'];
$fixture=static function()use(&$name,&$icon){return ['name'=>$name,'menu_icon_id'=>$icon];};
add_filter('pre_option_'.BrandingRepository::OPTION,$fixture);
$passed=false;
try {
    (new AdminMenu())->add_menu();
    $key=null;foreach($menu as $index=>$item){if(($item[2]??'')==='scalyn-mail-relay'){$key=$index;break;}}
    if(null===$key)throw new RuntimeException();
    $original=$menu[$key];$hooks=$admin_page_hooks;$children=$submenu['scalyn-mail-relay'];
    AgencyBranding::apply_menu_label();if($menu[$key][0]!=='Mail Relay')throw new RuntimeException();
    $name='Agency & Co';AgencyBranding::apply_menu_label();
    if($menu[$key][0]!=='Agency &amp; Co'||array_slice($original,1)!==array_slice($menu[$key],1)||$hooks!==$admin_page_hooks||$children!==$submenu['scalyn-mail-relay'])throw new RuntimeException();
    $name='<script>invalid</script>';AgencyBranding::apply_menu_label();if($menu[$key][0]!=='Mail Relay')throw new RuntimeException();
    if($candidate){
        $icon=$candidate;AgencyBranding::apply_menu_label();
        if($menu[$key][6]!==esc_url($repository->menu_icon_url())||!$repository->menu_icon_url()||$hooks!==$admin_page_hooks||$children!==$submenu['scalyn-mail-relay'])throw new RuntimeException();
        $icon=0;AgencyBranding::apply_menu_label();if($menu[$key][6]!=='dashicons-email-alt2')throw new RuntimeException();
    }
    $passed=true;
}catch(Throwable $error){fwrite(STDERR,"Native menu branding smoke failed; no branding saved or mail sent.\n");}
finally{remove_filter('pre_option_'.BrandingRepository::OPTION,$fixture);}
echo $passed?"PASS: native WordPress brand labels, escaping, raster icon/fallback, unchanged submenus, hooks, slug and capability. No branding/account/provider writes or sends.\n":"FAIL: native menu branding QA.\n";
exit($passed?0:1);
