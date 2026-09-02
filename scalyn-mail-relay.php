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

defined( 'ABSPATH' ) || exit;

define( 'SCALYN_MAIL_RELAY_VERSION', '0.1.0' );
define( 'SCALYN_MAIL_RELAY_DB_VERSION', '0.1.0' );
define( 'SCALYN_MAIL_RELAY_FILE', __FILE__ );
define( 'SCALYN_MAIL_RELAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCALYN_MAIL_RELAY_URL', plugin_dir_url( __FILE__ ) );
define( 'SCALYN_MAIL_RELAY_REST_NAMESPACE', 'scalyn-mail-relay/v1' );

$composer_autoload = SCALYN_MAIL_RELAY_PATH . 'vendor/autoload.php';

if ( is_readable( $composer_autoload ) ) {
	require_once $composer_autoload;
} else {
	// Development/source fallback. Production packages should include Composer's autoloader.
	require_once SCALYN_MAIL_RELAY_PATH . 'includes/Core/Autoloader.php';
	\Scalyn\MailRelay\Core\Autoloader::register();
}

register_activation_hook( __FILE__, array( '\\Scalyn\\MailRelay\\Core\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\Scalyn\\MailRelay\\Core\\Lifecycle', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Scalyn\MailRelay\Core\Plugin::instance()->boot();
	}
);

// Register the built-in SMTP provider once Core services are ready.
// The scalyn_mail_relay_booted action fires after ProviderRegistry is
// registered in the container and before any mail dispatch occurs.
// No network call is made during registration.
add_action(
	'scalyn_mail_relay_booted',
	static function ( \Scalyn\MailRelay\Core\Container $container ): void {
		$container->get( \Scalyn\MailRelay\Core\ProviderRegistry::class )
			->register( new \Scalyn\MailRelay\Providers\Smtp\SmtpProvider() );
	}
);

// Register the SMTP/TLS diagnostic check (S1). Registered here rather than
// in Plugin.php because DiagnosticCheckRegistry is explicitly designed for
// third-party/module-owned registration via this action, keeping the check
// implementation inside Providers/Mail (Saturn-owned) with no Core edits.
add_action(
	'scalyn_mail_relay_booted',
	static function ( \Scalyn\MailRelay\Core\Container $container ): void {
		$container->get( \Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry::class )
			->register( new \Scalyn\MailRelay\Providers\Mail\SmtpTlsCheck() );
	}
);

// Register the DKIM and DMARC diagnostic checks (Y3). Registered here rather
// than in Plugin.php for the same reason as SmtpTlsCheck above: the checks
// stay inside Diagnostics/Checks (Yaj-owned) with no Core edits.
add_action(
	'scalyn_mail_relay_booted',
	static function ( \Scalyn\MailRelay\Core\Container $container ): void {
		$registry = $container->get( \Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry::class );
		$registry->register( new \Scalyn\MailRelay\Diagnostics\Checks\DkimCheck() );
		$registry->register( new \Scalyn\MailRelay\Diagnostics\Checks\DmarcCheck() );
	}
);
