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

$GLOBALS['_test_wp_options']       = array();
$GLOBALS['_test_wp_actions']       = array();
$GLOBALS['_test_wp_added_actions'] = array();
$GLOBALS['_test_wp_transients']    = array();
$GLOBALS['_test_wp_cron']           = array(); // hook => next-run timestamp.
$GLOBALS['_test_wp_cleared_hooks']  = array(); // hooks passed to wp_clear_scheduled_hook().
$GLOBALS['_test_wp_roles']          = array(); // role name => RoleStub.
$GLOBALS['_test_current_user_id']  = 1;
$GLOBALS['_test_wp_nonce_valid']   = false;
$GLOBALS['_test_wp_redirect']      = null;
$GLOBALS['_test_current_time']     = null; // null = use real time; string = fixed time for tests.
$GLOBALS['_test_wp_enqueued_styles']    = array(); // handle => src.
$GLOBALS['_test_wp_enqueued_scripts']   = array(); // handle => src.
$GLOBALS['_test_wp_localized_scripts']  = array(); // handle => array( object_name => data ).

// Define plugin constants for tests.
if ( ! defined( 'SCALYN_MAIL_RELAY_REST_NAMESPACE' ) ) {
	define( 'SCALYN_MAIL_RELAY_REST_NAMESPACE', 'scalyn-mail-relay/v1' );
}

if ( ! defined( 'SCALYN_MAIL_RELAY_VERSION' ) ) {
	define( 'SCALYN_MAIL_RELAY_VERSION', '0.1.0' );
}

if ( ! defined( 'SCALYN_MAIL_RELAY_URL' ) ) {
	define( 'SCALYN_MAIL_RELAY_URL', 'http://example.com/wp-content/plugins/scalyn-mail-relay/' );
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/** Records enqueued style handles. */
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void {
		$GLOBALS['_test_wp_enqueued_styles'][ $handle ] = $src;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/** Records enqueued script handles. */
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, $args = array() ): void {
		$GLOBALS['_test_wp_enqueued_scripts'][ $handle ] = $src;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/** Records data localized onto a script handle. */
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
		$GLOBALS['_test_wp_localized_scripts'][ $handle ][ $object_name ] = $l10n;
		return true;
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	/** No-op stub. */
	function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
		return true;
	}
}

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

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option
	 */
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['_test_wp_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['_test_wp_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Returns the timestamp recorded in $GLOBALS['_test_wp_cron'], or false.
	 *
	 * @param string $hook
	 * @return int|false
	 */
	function wp_next_scheduled( string $hook ) {
		return $GLOBALS['_test_wp_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Records a scheduled event in $GLOBALS['_test_wp_cron'].
	 *
	 * @param int    $timestamp
	 * @param string $recurrence
	 * @param string $hook
	 */
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		$GLOBALS['_test_wp_cron'][ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Removes a hook from $GLOBALS['_test_wp_cron'] and records the clear call
	 * in $GLOBALS['_test_wp_cleared_hooks'] so tests can assert on hooks that
	 * were never scheduled in the first place.
	 *
	 * @param string $hook
	 */
	function wp_clear_scheduled_hook( string $hook ): void {
		$GLOBALS['_test_wp_cleared_hooks'][] = $hook;
		unset( $GLOBALS['_test_wp_cron'][ $hook ] );
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * Returns the RoleStub registered in $GLOBALS['_test_wp_roles'], or null.
	 *
	 * @param string $role
	 * @return RoleStub|null
	 */
	function get_role( string $role ): ?RoleStub {
		return $GLOBALS['_test_wp_roles'][ $role ] ?? null;
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	/**
	 * Returns a WpRolesStub view over $GLOBALS['_test_wp_roles'].
	 */
	function wp_roles(): WpRolesStub {
		return new WpRolesStub();
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Fires registered callbacks for the hook.
	 *
	 * Two registration paths are supported:
	 *   _test_wp_actions   — direct-assign path used by MailDispatcherTest and PluginTest.
	 *                        Only one callback per hook; called with all args as-is.
	 *   _test_wp_added_actions — populated by add_action(); supports multiple callbacks
	 *                            per hook and respects accepted_args.
	 */
	function do_action( string $hook_name, mixed ...$args ): void {
		if ( isset( $GLOBALS['_test_wp_actions'][ $hook_name ] ) ) {
			( $GLOBALS['_test_wp_actions'][ $hook_name ] )( ...$args );
		}
		foreach ( $GLOBALS['_test_wp_added_actions'][ $hook_name ] ?? array() as $entry ) {
			( $entry['callback'] )( ...array_slice( $args, 0, $entry['accepted_args'] ) );
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

if ( ! function_exists( 'wp_date' ) ) {
	/** Formats a timestamp using PHP's date() function with WordPress timezone. */
	function wp_date( string $format, int $timestamp ): string {
		return date( $format, $timestamp );
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

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * @param string $path   Path relative to the REST API base.
	 * @param string $scheme Unused in stub.
	 */
	function rest_url( string $path = '', string $scheme = 'rest' ): string {
		return 'http://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path   Path relative to the home URL.
	 * @param string $scheme Unused in stub.
	 */
	function home_url( string $path = '', string $scheme = 'http' ): string {
		return 'http://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * A wrapper for PHP's parse_url() function.
	 *
	 * @param string   $url URL to parse.
	 * @param int|null $component Specific component to return.
	 * @return array|string|int|null
	 */
	function wp_parse_url( string $url, ?int $component = -1 ) {
		$parsed = parse_url( $url );
		if ( -1 === $component ) {
			return $parsed;
		}
		return $parsed[ $component ] ?? null;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Stub for register_rest_route() that records registrations.
	 * Tests don't actually need to invoke the endpoint, just verify it was registered.
	 *
	 * @param string $namespace The namespace for the route.
	 * @param string $route     The route path.
	 * @param array  $args      Route arguments (methods, callback, permission_callback).
	 * @return bool True if registered successfully.
	 */
	function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
		if ( ! isset( $GLOBALS['_test_registered_rest_routes'] ) ) {
			$GLOBALS['_test_registered_rest_routes'] = array();
		}
		$GLOBALS['_test_registered_rest_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub for unit tests.
	 */
	class WP_REST_Request {
		private array $params = array();

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
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

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Outputs an HTML-escaped translated string.
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain (unused in stub).
	 */
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() is called.
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Outputs an attribute-escaped translated string.
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain (unused in stub).
	 */
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo esc_attr( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() is called.
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Returns an attribute-escaped translated string.
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain (unused).
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Sanitizes a string to be used as a CSS class name.
	 *
	 * Strips any character that is not a word character or a hyphen.
	 *
	 * @param string $class    CSS class name candidate.
	 * @param string $fallback Value returned when $class is empty after sanitization.
	 * @return string Sanitized class name.
	 */
	function sanitize_html_class( string $class, string $fallback = '' ): string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9_-]/', '', $class );
		return '' !== $sanitized ? $sanitized : $fallback;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Registers a WordPress action callback.
	 *
	 * Recorded in $GLOBALS['_test_wp_added_actions'] so tests can assert
	 * that register() wired the correct hooks. Does not integrate with do_action().
	 *
	 * @param string         $tag             Hook name.
	 * @param callable|array $function_to_add Callback to register.
	 * @param int            $priority        Execution priority (default 10).
	 * @param int            $accepted_args   Number of arguments the callback accepts.
	 */
	function add_action( string $tag, $function_to_add, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['_test_wp_added_actions'][ $tag ][] = array(
			'callback'      => $function_to_add,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Returns the current time in MySQL datetime format.
	 *
	 * Returns $GLOBALS['_test_current_time'] when set (non-null string) so that
	 * tests can pin the timestamp to a deterministic value.
	 *
	 * @param string $type Ignored in the stub (always returns MySQL datetime format).
	 * @param bool   $gmt  Ignored in the stub.
	 */
	function current_time( string $type, bool $gmt = false ): string {
		if ( null !== $GLOBALS['_test_current_time'] ) {
			return (string) $GLOBALS['_test_current_time'];
		}
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encodes a value to JSON. Mirrors WordPress's wp_json_encode().
	 *
	 * @param mixed $data    The value to encode.
	 * @param int   $options JSON encode options (default 0).
	 * @param int   $depth   Maximum depth (default 512).
	 * @return string|false JSON string on success; false on failure.
	 */
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

/**
 * Minimal WP_Role stub.
 *
 * Records add_cap()/remove_cap() so lifecycle tests can assert exactly which
 * capabilities were granted on activation and revoked on uninstall.
 */
class RoleStub {

	/** Capabilities currently held: capability => true. */
	public array $capabilities = array();

	/** Every capability passed to remove_cap(), in call order. */
	public array $removed = array();

	/**
	 * @param string $capability
	 */
	public function add_cap( string $capability ): void {
		$this->capabilities[ $capability ] = true;
	}

	/**
	 * @param string $capability
	 */
	public function remove_cap( string $capability ): void {
		$this->removed[] = $capability;
		unset( $this->capabilities[ $capability ] );
	}

	/**
	 * @param string $capability
	 */
	public function has_cap( string $capability ): bool {
		return isset( $this->capabilities[ $capability ] );
	}
}

/**
 * Minimal WP_Roles stub exposing the role names registered in
 * $GLOBALS['_test_wp_roles'].
 */
class WpRolesStub {

	/**
	 * Returns role name => display name for every registered role.
	 *
	 * @return array<string, string>
	 */
	public function get_names(): array {
		$names = array();
		foreach ( array_keys( $GLOBALS['_test_wp_roles'] ) as $role_name ) {
			$names[ $role_name ] = ucfirst( $role_name );
		}
		return $names;
	}
}

/**
 * Minimal $wpdb stub for unit tests.
 *
 * Tracks INSERT/UPDATE calls and configures return values for SELECT queries.
 * Each test should set $GLOBALS['wpdb'] = new WpdbStub() in setUp() to ensure
 * a clean slate between tests.
 *
 * Using a concrete class (not an interface) mirrors how WordPress code accesses
 * $wpdb as a global — tests configure the global, repositories consume it.
 */
class WpdbStub {

	/** Table name prefix. */
	public string $prefix = 'wp_';

	/** Recorded INSERT calls: [ ['table' => string, 'data' => array], ... ] */
	public array $inserts = array();

	/** Recorded UPDATE calls: [ ['table' => string, 'data' => array, 'where' => array], ... ] */
	public array $updates = array();

	/** Recorded prepare() calls: [ ['query' => string, 'args' => array], ... ] */
	public array $prepare_calls = array();

	/** Recorded raw query() calls, in call order. */
	public array $queries = array();

	/** Value returned by get_var(). Set per-test to simulate existing/absent rows. */
	public mixed $get_var_return = null;

	/** Row returned by get_row(). Null simulates "not found". */
	public ?array $get_row_return = null;

	/** Rows returned by get_results(). Empty array = no rows. */
	public array $get_results_return = array();

	/**
	 * When true, insert() throws a RuntimeException to simulate a DB-layer exception.
	 * Tests the subscriber's catch boundary for unexpected $wpdb exceptions.
	 */
	public bool $throw_on_insert = false;

	/**
	 * When true, insert() returns false to simulate a failed DB write.
	 * Tests the repository's false-return detection and safe exception throwing.
	 */
	public bool $return_false_on_insert = false;

	/**
	 * When true, update() returns false to simulate a failed DB update.
	 * Tests the repository's false-return detection and safe exception throwing.
	 */
	public bool $return_false_on_update = false;

	/**
	 * Prepares a SQL query. In the stub, arguments are recorded but the raw query
	 * template is returned unchanged. get_var/get_row/get_results ignore the query.
	 *
	 * @param string $query SQL template with %s/%d placeholders.
	 * @param mixed  ...$args Substitution values (recorded for assertion).
	 * @return string The unmodified query template.
	 */
	/**
	 * Records a raw query and reports success. Used by uninstall.php's
	 * DROP TABLE statements.
	 *
	 * @param string $query The SQL statement.
	 * @return int Simulated rows affected.
	 */
	public function query( string $query ): int {
		$this->queries[] = $query;
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->prepare_calls[] = array(
			'query' => $query,
			'args'  => $args,
		);
		return $query;
	}

	/**
	 * Records an INSERT and returns 1 (rows affected) or throws when throw_on_insert.
	 *
	 * @param string $table  Target table name.
	 * @param array  $data   Column => value pairs to insert.
	 * @param mixed  $format Optional format specifiers (unused in stub).
	 * @return int|false     1 on simulated success.
	 * @throws \RuntimeException When $this->throw_on_insert is true.
	 */
	public function insert( string $table, array $data, mixed $format = null ): int|false {
		if ( $this->throw_on_insert ) {
			throw new \RuntimeException( 'Simulated DB failure in insert()' );
		}
		if ( $this->return_false_on_insert ) {
			return false;
		}
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);
		return 1;
	}

	/**
	 * Records an UPDATE and returns 1 (rows affected).
	 *
	 * @param string $table        Target table name.
	 * @param array  $data         Column => value pairs to update.
	 * @param array  $where        Column => value pairs for the WHERE clause.
	 * @param mixed  $format       Optional format specifiers (unused in stub).
	 * @param mixed  $where_format Optional WHERE format specifiers (unused in stub).
	 * @return int|false           1 on simulated success.
	 */
	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		if ( $this->return_false_on_update ) {
			return false;
		}
		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		return 1;
	}

	/**
	 * Returns the configured get_var_return value regardless of the query.
	 *
	 * @param string $query Ignored in the stub.
	 * @return mixed The value set via $this->get_var_return.
	 */
	public function get_var( string $query ): mixed {
		return $this->get_var_return;
	}

	/**
	 * Returns the configured get_row_return value regardless of the query.
	 *
	 * @param string $query  Ignored in the stub.
	 * @param string $output Output format constant (OBJECT or ARRAY_A); used to
	 *                       decide whether to cast the return value to object.
	 * @return array|object|null
	 */
	public function get_row( string $query, string $output = OBJECT ): array|object|null {
		if ( null === $this->get_row_return ) {
			return null;
		}
		if ( ARRAY_A === $output ) {
			return $this->get_row_return;
		}
		return (object) $this->get_row_return;
	}

	/**
	 * Returns the configured get_results_return value regardless of the query.
	 *
	 * @param string $query  Ignored in the stub.
	 * @param string $output Output format constant (unused in stub; always returns arrays).
	 * @return array
	 */
	public function get_results( string $query, string $output = OBJECT ): array {
		return $this->get_results_return;
	}
}
