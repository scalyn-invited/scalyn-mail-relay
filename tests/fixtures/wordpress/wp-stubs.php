<?php
/**
 * Minimal WordPress function stubs for PHPUnit unit tests.
 *
 * These stubs replace the WordPress functions used by Core and Mail classes
 * so that unit tests can run without a full WordPress installation.
 *
 * Only functions actually required by the tested classes are stubbed here.
 * Do not add stubs speculatively — add them when a test requires them.
 *
 * Option storage is backed by $GLOBALS['_test_wp_options'] so tests can
 * pre-populate options and inspect saved values. Reset this array in setUp().
 *
 * Hook callbacks can be registered in $GLOBALS['_test_wp_actions'] to allow
 * tests to verify that specific hooks were fired by the dispatcher.
 */

$GLOBALS['_test_wp_options']     = array();
$GLOBALS['_test_wp_actions']     = array();
$GLOBALS['_test_wp_transients']  = array();
$GLOBALS['_test_current_user_id'] = 1;
$GLOBALS['_test_wp_nonce_valid'] = false;
$GLOBALS['_test_wp_redirect']    = null;

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option
	 * @param mixed  $default
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		return $GLOBALS['_test_wp_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option
	 * @param mixed  $value
	 * @param mixed  $autoload
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['_test_wp_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Fires the callback registered in $GLOBALS['_test_wp_actions'] for the hook.
	 * Only one callback per hook name is supported in the test context.
	 */
	function do_action( string $hook_name, mixed ...$args ): void {
		if ( isset( $GLOBALS['_test_wp_actions'][ $hook_name ] ) ) {
			( $GLOBALS['_test_wp_actions'][ $hook_name ] )( ...$args );
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		$filtered = filter_var( $email, FILTER_SANITIZE_EMAIL );
		return ( false !== $filtered ) ? $filtered : '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( string $domain, bool $deprecated = false, string|false $plugin_rel_path = false ): bool {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $capability
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return ! empty( $GLOBALS['_test_current_user_can'][ $capability ] );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Stub for wp_die — throws a RuntimeException so tests can assert rejection.
	 *
	 * @param string|\WP_Error $message
	 * @param string           $title
	 * @param string|array     $args
	 * @return never
	 */
	function wp_die( $message = '', $title = '', $args = array() ): never {
		throw new \RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text
	 * @param string $domain
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array $value
	 * @return string|array
	 */
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $transient Transient name.
	 * @return mixed Value on success; false when not found.
	 */
	function get_transient( string $transient ): mixed {
		return $GLOBALS['_test_wp_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $transient   Transient name.
	 * @param mixed  $value       Value to store.
	 * @param int    $expiration  Unused in stub.
	 */
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['_test_wp_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $transient Transient name.
	 */
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['_test_wp_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/** Returns the current user ID set via $GLOBALS['_test_current_user_id']. */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['_test_current_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/** Returns a deterministic UUID-shaped string for tests. */
	function wp_generate_uuid4(): string {
		return 'test-uuid-4-' . substr( md5( (string) mt_rand() ), 0, 8 );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Appends a query parameter to a URL.
	 * Supports both add_query_arg(key, value, url) and add_query_arg(array, url).
	 *
	 * @param string|array    $key   Query key or associative array of key => value pairs.
	 * @param string|int|null $value Query value (unused when $key is an array).
	 * @param string          $url   Base URL.
	 * @return string
	 */
	function add_query_arg( $key, $value = null, string $url = '' ): string {
		if ( is_array( $key ) ) {
			$url = (string) $value;
			foreach ( $key as $k => $v ) {
				$url = add_query_arg( (string) $k, (string) $v, $url );
			}
			return $url;
		}
		$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $separator . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path   Path relative to the admin URL.
	 * @param string $scheme Unused in stub.
	 */
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! class_exists( 'WpRedirectException' ) ) {
	/**
	 * Thrown by the wp_safe_redirect stub to simulate the exit; call that
	 * always follows wp_safe_redirect() in production WordPress code.
	 *
	 * Tests that expect a redirect should catch this exception after asserting
	 * the redirect URL stored in $GLOBALS['_test_wp_redirect'].
	 *
	 * Intentionally does NOT extend RuntimeException so that tests expecting
	 * wp_die() (RuntimeException) cannot accidentally pass on a redirect.
	 */
	class WpRedirectException extends \Exception {}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Records the redirect URL in $GLOBALS['_test_wp_redirect'] then throws
	 * WpRedirectException to simulate the exit; call that follows
	 * wp_safe_redirect() in production code.
	 *
	 * @param string $location Redirect destination URL.
	 * @param int    $status   HTTP status code (unused).
	 * @param string $x_redirect_by Unused.
	 * @throws WpRedirectException Always — simulates the post-redirect exit.
	 */
	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		$GLOBALS['_test_wp_redirect'] = $location;
		throw new WpRedirectException( 'Redirect: ' . $location );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Verifies a nonce. Dies when $GLOBALS['_test_wp_nonce_valid'] is falsy.
	 *
	 * @param int|string $action    Nonce action name.
	 * @param string     $query_arg Query-string key holding the nonce.
	 * @return int|false 1 on success.
	 */
	function check_admin_referer( $action = -1, string $query_arg = '_wpnonce' ) {
		if ( empty( $GLOBALS['_test_wp_nonce_valid'] ) ) {
			wp_die( 'Nonce verification failed.' );
		}
		return 1;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Outputs a hidden nonce input field.
	 *
	 * @param int|string $action    Nonce action.
	 * @param string     $name      Input field name.
	 * @param bool       $referer   Whether to add a referer field (unused in stub).
	 * @param bool       $echo      Whether to echo (unused in stub — always echoes).
	 * @return string The nonce field HTML.
	 */
	function wp_nonce_field( $action = -1, string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$html = '<input type="hidden" name="' . htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' ) . '" value="test-nonce" />';
		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
		}
		return $html;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * @param int|string $action Nonce action.
	 */
	function wp_create_nonce( $action = -1 ): string {
		return 'test-nonce';
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the string unchanged (no i18n in tests).
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain (unused).
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/** Escapes a string for safe use in HTML attribute values. */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/** Escapes a string for safe HTML output. */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string   $url       URL to validate and escape.
	 * @param string[] $protocols Allowed protocols (unused in stub).
	 */
	function esc_url( string $url, array $protocols = array() ): string {
		return $url;
	}
}
