<?php
/**
 * PHPUnit bootstrap for isolated unit tests.
 *
 * Defines ABSPATH and plugin path constants, loads WordPress function stubs,
 * then registers the source-tree autoloader. No WordPress installation required.
 */

define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'SCALYN_MAIL_RELAY_PATH', dirname( __DIR__ ) . '/' );
define( 'SCALYN_MAIL_RELAY_FILE', SCALYN_MAIL_RELAY_PATH . 'scalyn-mail-relay.php' );
define( 'SCALYN_MAIL_RELAY_DB_VERSION', '0.1.0' );
define( 'SCALYN_MAIL_RELAY_VERSION', '0.1.0' );

// WordPress DB output-format constants used by wpdb::get_row() / get_results().
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once __DIR__ . '/fixtures/wordpress/wp-stubs.php';

// Load PHPMailer stub classes before any provider code runs.
// PhpMailerLoader::load() checks class_exists() first and skips the
// WordPress-bundled file require when the classes are already in memory.
require_once __DIR__ . '/fixtures/phpmailer/phpmailer-stubs.php';

require_once SCALYN_MAIL_RELAY_PATH . 'includes/Core/Autoloader.php';
\Scalyn\MailRelay\Core\Autoloader::register();
