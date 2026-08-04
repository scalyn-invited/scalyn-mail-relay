<?php
/** PHPUnit bootstrap for isolated value-object/unit tests. */
define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'SCALYN_MAIL_RELAY_PATH', dirname( __DIR__ ) . '/' );
require_once SCALYN_MAIL_RELAY_PATH . 'includes/Core/Autoloader.php';
Scalyn\MailRelay\Core\Autoloader::register();
