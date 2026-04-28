<?php
/**
 * WPML_Strings — wraps WPML's String Translation API with a safe no-op
 * fallback for monolingual sites.
 *
 * Templates and global settings store admin-defined strings (CTA templates,
 * subtitles, equivalency text, preset labels, impact labels). Every save
 * registers these with WPML so translators can localize them; every render
 * translates them via WPML's filter so donors see the right language.
 *
 * Domain "Donations Companion" appears as a single section in WPML's String
 * Translation UI, with all strings keyed by `<namespace>.<interval>.<field>`
 * for predictable navigation.
 *
 * No-op fallback: when WPML isn't installed, register() does nothing and
 * translate() returns the original string unchanged. This means
 * single-language sites pay zero overhead.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\I18n;

defined( 'ABSPATH' ) || exit;

final class WPML_Strings {

	/** WPML domain (top-level grouping in WPML's String Translation UI). */
	public const DOMAIN = 'Donations Companion';

	/**
	 * Translate a single admin-defined string via WPML's String Translation.
	 *
	 * @param string $original Default-language string.
	 * @param string $name     Unique identifier within the domain (e.g.,
	 *                         'school_sponsorship.monthly.cta_template').
	 * @return string Translated string in the active language; original if
	 *                WPML inactive or no translation exists.
	 */
	public static function translate( string $original, string $name ): string {
		if ( '' === $original ) {
			return $original;
		}
		if ( ! self::wpml_active() ) {
			return $original;
		}
		// WPML's filter signature: ( $original, $domain, $name, $language=null, $hasTranslation=null ).
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WPML's own hook name, not ours.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook name, not ours.
		$translated = apply_filters( 'wpml_translate_single_string', $original, self::DOMAIN, $name );
		return is_string( $translated ) ? $translated : $original;
	}

	/**
	 * Register a single admin-defined string with WPML's String Translation.
	 * Idempotent: re-registering the same name with the same value is a no-op.
	 *
	 * Called from save handlers when admin types/changes admin-defined strings,
	 * so WPML's String Translation UI lists them for translators.
	 */
	public static function register( string $name, string $original ): void {
		if ( '' === $original ) {
			return;
		}
		if ( ! self::wpml_active() ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook name, not ours.
		do_action( 'wpml_register_single_string', self::DOMAIN, $name, $original );
	}

	/**
	 * Bulk-register an associative array of `name => original` pairs.
	 * Convenience for save handlers iterating over many fields.
	 *
	 * @param array<string,string> $pairs
	 */
	public static function register_many( array $pairs ): void {
		foreach ( $pairs as $name => $original ) {
			self::register( (string) $name, (string) $original );
		}
	}

	/**
	 * True when WPML's String Translation API is available.
	 */
	public static function wpml_active(): bool {
		return defined( 'ICL_LANGUAGE_CODE' ) || function_exists( 'icl_object_id' );
	}

	/**
	 * True when WPML WooCommerce Multilingual (WCML) is active. WCML is the
	 * primary multi-currency target for Phase 6.
	 */
	public static function wcml_active(): bool {
		return class_exists( '\\WCML_Multi_Currency' ) || function_exists( 'wcml_get_user_currency' );
	}

	/**
	 * Current site language code. Falls back to `get_locale()` for monolingual
	 * sites or a sensible string when WPML's filter is missing.
	 */
	public static function current_language(): string {
		if ( ! self::wpml_active() ) {
			return function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook name, not ours.
		$lang = apply_filters( 'wpml_current_language', null );
		if ( is_string( $lang ) && '' !== $lang ) {
			return $lang;
		}
		return defined( 'ICL_LANGUAGE_CODE' ) ? (string) ICL_LANGUAGE_CODE : 'en';
	}
}
