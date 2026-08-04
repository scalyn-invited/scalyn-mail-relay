<?php
/**
 * Source-tree fallback autoloader.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 compatible class autoloader for the Scalyn\MailRelay namespace.
 *
 * Used only when the Composer autoloader is not available (source-only installs,
 * local development without running composer install). Production builds must
 * include the Composer autoloader.
 */
final class Autoloader {

	/**
	 * Registers this autoloader with spl_autoload_register().
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolves and requires the file for the given class name.
	 *
	 * Maps Scalyn\MailRelay\Admin\* to admin/ and all other sub-namespaces
	 * to includes/<Namespace>/.
	 *
	 * @param string $class_name The fully-qualified class name to load.
	 */
	private static function autoload( string $class_name ): void { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $class_name matches the spl_autoload_register callback signature.
		$prefix = 'Scalyn\\MailRelay\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
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
