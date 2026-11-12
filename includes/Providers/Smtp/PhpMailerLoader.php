<?php
/**
 * WordPress-bundled PHPMailer class loader.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Ensures the WordPress-bundled PHPMailer classes are available at runtime.
 *
 * WordPress ships PHPMailer under wp-includes/PHPMailer/. These classes are
 * loaded on demand by wp_mail() and may not be in memory when our SMTP
 * provider runs. This loader inspects the runtime class state and requires
 * the bundled files only when they are absent.
 *
 * Rules:
 * - Do NOT add a Composer PHPMailer dependency.
 * - Do NOT modify WordPress core files.
 * - The plugin must work in an installable ZIP where vendor/ is absent.
 * - If the classes are already in memory (loaded by WordPress or a Composer
 *   autoloader), this class is a no-op.
 */
final class PhpMailerLoader {

	/**
	 * Whether PHPMailer classes have already been confirmed available.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Ensures PHPMailer classes are available before use.
	 *
	 * Checks for existing class definitions first. If the classes are absent,
	 * requires the WordPress-bundled files from wp-includes/PHPMailer/ in
	 * dependency order (Exception → SMTP → PHPMailer).
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		// Guard: classes may already be present via WordPress core (wp_mail was
		// called earlier), a Composer autoloader, or test stubs. Use autoload=false
		// to avoid triggering a second autoload attempt on this check.
		if ( class_exists( 'PHPMailer\\PHPMailer\\PHPMailer', false ) ) {
			self::$loaded = true;
			return;
		}

		// WPINC is 'wp-includes' in all standard WordPress installations.
		// Provide a safe fallback for non-standard test environments.
		$wp_includes   = defined( 'WPINC' ) ? WPINC : 'wp-includes';
		$phpmailer_dir = ABSPATH . $wp_includes . '/PHPMailer/';

		// Load order matters: Exception before SMTP, SMTP before PHPMailer.
		$files = array(
			'Exception.php',
			'SMTP.php',
			'PHPMailer.php',
		);

		foreach ( $files as $file ) {
			$path = $phpmailer_dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- path is constructed from trusted WP constants.
			}
		}

		self::$loaded = true;
	}
}
