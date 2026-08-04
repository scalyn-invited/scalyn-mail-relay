<?php
/**
 * Source-tree fallback autoloader.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	private static function autoload( string $class ): void {
		$prefix = 'Scalyn\\MailRelay\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$root     = array_shift( $parts );
		$file     = implode( DIRECTORY_SEPARATOR, $parts ) . '.php';

		$base = 'Admin' === $root
			? SCALYN_MAIL_RELAY_PATH . 'admin/'
			: SCALYN_MAIL_RELAY_PATH . 'includes/' . $root . '/';

		$path = $base . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
