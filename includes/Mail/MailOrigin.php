<?php
/** Explicit call-site attribution; never examines message content.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/** Trusted PHP integrations can scope a send, with nested scopes restored in finally. */
final class MailOrigin {

	/** Registered source/type labels.
	 *
	 * @var array<string,string>
	 */
	private static array $types = array(
		'unknown/default'             => 'Unattributed mail (default)',
		'wordpress/password_reset'    => 'WordPress password reset',
		'contact-form-7/contact_form' => 'Contact Form 7',
		'woocommerce/transactional'   => 'WooCommerce transactional email',
	);
	/** Current trusted integration scope.
	 *
	 * @var string|null
	 */
	private static ?string $scope = null;

	/**
	 * Registers a content-free integration key during plugin boot.
	 *
	 * @param string $key source/type, lowercase slugs.
	 * @param string $label Public label.
	 * @throws \InvalidArgumentException For invalid or duplicate identifiers.
	 */
	public static function register( string $key, string $label ): void {
		if ( ! preg_match( '~^[a-z][a-z0-9_-]{0,39}/[a-z][a-z0-9_-]{0,39}$~D', $key ) || isset( self::$types[ $key ] ) || count( self::$types ) >= 50 || '' === trim( $label ) || strlen( $label ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid mail integration.' );
		}
		self::$types[ $key ] = sanitize_text_field( $label );
	}

	/** Returns registered source/type labels, never message data. */
	public static function types(): array {
		return self::$types;
	}

	/**
	 * Wraps only the actual integration mail call. Not a user-input routing hint.
	 *
	 * @param string   $key Registered source/type.
	 * @param callable $send Integration callback.
	 * @return mixed Callback result.
	 * @throws \InvalidArgumentException For an unregistered key.
	 */
	public static function during( string $key, callable $send ): mixed {
		if ( ! isset( self::$types[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Unregistered mail integration.' );
		}
		$previous    = self::$scope;
		self::$scope = $key;
		try {
			return $send();
		} finally {
			self::$scope = $previous;
		}
	}

	/**
	 * Bounded, argument-free call-site inspection avoids leaking mail or assigning
	 * an entire request to a plugin. Header markers are not trusted for routing.
	 *
	 * @param array|null $frames Explicit frames for isolated tests.
	 */
	public static function current( ?array $frames = null ): string {
		if ( null !== self::$scope ) {
			return self::$scope;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Arguments and objects omitted; used only for explicit integration call sites, never persisted.
		$frames ??= debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 );
		foreach ( $frames as $frame ) {
			$class  = $frame['class'] ?? '';
			$method = $frame['function'] ?? '';
			if ( 'WPCF7_Mail' === $class && 'compose' === $method ) {
				return 'contact-form-7/contact_form';
			}
			if ( '' === $class && 'retrieve_password' === $method ) {
				return 'wordpress/password_reset';
			}
			if ( 'send' === $method && '' !== $class && is_a( $class, 'WC_Email', true ) ) {
				return 'woocommerce/transactional';
			}
		}
		return 'unknown/default';
	}
}
