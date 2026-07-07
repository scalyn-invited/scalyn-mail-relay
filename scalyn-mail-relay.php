<?php
/**
 * Plugin Name: Scalyn Mail Relay
 * Plugin URI: https://github.com/scalyn-invited/scalyn-mail-relay
 * Description: WordPress email delivery, diagnostics, monitoring and remediation platform.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Update URI: https://github.com/scalyn-invited/scalyn-mail-relay
 * Author: Scalyn Studio
 * Text Domain: scalyn-mail-relay
 * Domain Path: /languages
 *
 * @package ScalynMailRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCALYN_MAIL_RELAY_VERSION', '0.1.0' );
define( 'SCALYN_MAIL_RELAY_FILE', __FILE__ );
define( 'SCALYN_MAIL_RELAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCALYN_MAIL_RELAY_URL', plugin_dir_url( __FILE__ ) );

require_once SCALYN_MAIL_RELAY_PATH . 'includes/Core/Plugin.php';

register_activation_hook(
	__FILE__,
	array( '\Scalyn\MailRelay\Core\Plugin', 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( '\Scalyn\MailRelay\Core\Plugin', 'deactivate' )
);

add_action(
	'plugins_loaded',
	static function () {
		\Scalyn\MailRelay\Core\Plugin::instance()->init();
	}
);
