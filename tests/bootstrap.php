<?php
/**
 * PHPUnit bootstrap.
 *
 * Lightweight bootstrap that doesn't require the WP test framework. v0.7.0
 * unit tests target pure-logic classes (Config_Resolver, Currency_Preset_Resolver,
 * Engine_Interval_Map, Privacy_Guard, Template_Validator) that don't need WP
 * loaded. WP-integration tests live in tests/e2e via Playwright.
 *
 * If a future test class needs WP loaded, switch to the wp-phpunit pattern by
 * uncommenting the WP test framework lines below.
 *
 * @package DFWC\Companion
 */

// Stub a minimal WordPress surface so includes/ files don't fatal on autoload.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

// Plugin constants — match the runtime values defined in the main plugin file.
require_once __DIR__ . '/phpstan-bootstrap.php';

// Minimal WP function stubs for unit tests that don't load WP.
// Each stub mirrors the WP function's signature and returns sane defaults.
// If a test needs richer behavior, the test class can override via a Mockery
// or override the function with `runkit` (or simply load WP via wp-phpunit).

// Real-but-minimal filter/action implementation. Supports priority-ordered
// callbacks; resets via dfwc_test_reset(). Doesn't implement the full WP
// hook system (no removed filters, no all-hooks, no priority normalization
// edge cases) — sufficient for unit tests of plugin code that uses filters.
$GLOBALS['_dfwc_test_filters'] = array();
$GLOBALS['_dfwc_test_actions'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['_dfwc_test_actions'][ $tag ][ $priority ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['_dfwc_test_filters'][ $tag ][ $priority ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( empty( $GLOBALS['_dfwc_test_filters'][ $tag ] ) ) {
			return $value;
		}
		$priorities = $GLOBALS['_dfwc_test_filters'][ $tag ];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = call_user_func( $callback, $value, ...$args );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		if ( empty( $GLOBALS['_dfwc_test_actions'][ $tag ] ) ) {
			return null;
		}
		$priorities = $GLOBALS['_dfwc_test_actions'][ $tag ];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				call_user_func( $callback, ...$args );
			}
		}
		return null;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title, $fallback = '', $context = 'save' ) {
		// Minimal stub: lowercase, replace spaces+underscores with dashes, strip non-slug chars.
		$str = strtolower( (string) $title );
		$str = preg_replace( '/[\s_]+/', '-', $str );
		$str = preg_replace( '/[^a-z0-9\-]/', '', $str );
		$str = preg_replace( '/-+/', '-', $str );
		$str = trim( $str, '-' );
		return '' !== $str ? $str : (string) $fallback;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub
	}
}

// Per-post meta in-memory store for tests.
if ( ! function_exists( 'get_post_meta' ) ) {
	$GLOBALS['_dfwc_test_post_meta'] = [];
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$store = $GLOBALS['_dfwc_test_post_meta'][ $post_id ] ?? [];
		if ( '' === $key ) {
			return $store;
		}
		$value = $store[ $key ] ?? '';
		return $single ? $value : (array) $value;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value, $prev = '' ) {
		$GLOBALS['_dfwc_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( $GLOBALS['_dfwc_test_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return $GLOBALS['_dfwc_test_options'][ $name ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['_dfwc_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['_dfwc_test_options'][ $name ] );
		return true;
	}
}

// WC pricing helpers (used by Config_Resolver / sanitize_interval_block)
if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $number, $dp = 2 ) {
		return number_format( (float) $number, (int) $dp, '.', '' );
	}
}
if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return 2;
	}
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return 'USD';
	}
}

// Stubs for hook helpers used in self-checks/contracts.
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $tag, $callback = false ) {
		return ! empty( $GLOBALS['_dfwc_test_actions'][ $tag ] );
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return false; // Tests can override by registering a real post type if needed.
	}
}

// Stubs for transients (Checker uses them for cache).
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['_dfwc_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['_dfwc_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['_dfwc_test_transients'][ $key ] );
		return true;
	}
}

// Constants used by Checker.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Reset helper for tests
if ( ! function_exists( 'dfwc_test_reset' ) ) {
	function dfwc_test_reset() {
		$GLOBALS['_dfwc_test_post_meta']  = array();
		$GLOBALS['_dfwc_test_options']    = array();
		$GLOBALS['_dfwc_test_transients'] = array();
		$GLOBALS['_dfwc_test_filters']    = array();
		$GLOBALS['_dfwc_test_actions']    = array();
		// Reset the static request cache on the Checker so each test starts fresh.
		if ( class_exists( '\\DFWC\\Companion\\Contracts\\Parent_Form_Contract_Checker' ) ) {
			$reflection = new \ReflectionClass( '\\DFWC\\Companion\\Contracts\\Parent_Form_Contract_Checker' );
			if ( $reflection->hasProperty( 'request_cache' ) ) {
				$prop = $reflection->getProperty( 'request_cache' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		}
	}
}

// Load the companion's autoloader.
require_once __DIR__ . '/../includes/Autoloader.php';
\DFWC\Companion\Autoloader::register();
