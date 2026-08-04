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

$GLOBALS['_test_wp_options'] = array();
$GLOBALS['_test_wp_actions'] = array();

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
