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
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
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

// Reset helper for tests
if ( ! function_exists( 'dfwc_test_reset' ) ) {
	function dfwc_test_reset() {
		$GLOBALS['_dfwc_test_post_meta'] = [];
		$GLOBALS['_dfwc_test_options']   = [];
	}
}

// Load the companion's autoloader.
require_once __DIR__ . '/../includes/Autoloader.php';
\DFWC\Companion\Autoloader::register();
