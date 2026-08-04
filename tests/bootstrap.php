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

require_once __DIR__ . '/fixtures/wordpress/wp-stubs.php';

require_once SCALYN_MAIL_RELAY_PATH . 'includes/Core/Autoloader.php';
\Scalyn\MailRelay\Core\Autoloader::register();
