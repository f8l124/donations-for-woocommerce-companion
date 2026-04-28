<?php
/**
 * Privacy_Guard — sanitize event payloads before they reach `do_action`.
 *
 * The Phase 9 design is **hooks-only**: aggregate-only data passes through
 * the public hooks (counts, amounts, intervals, optional currency + WPML
 * language). Donor PII (name, email, IP, user agent, session ID) is never
 * accepted by the guard, even if a malicious client tries to POST it to
 * the track endpoint.
 *
 * The guard is the public contract:
 * - Track_REST_Controller routes every inbound event through it.
 * - Submission_Tracker and any future submitter run their data through
 *   the same allow-list sanitizers so the hook arg shape stays uniform.
 * - Future v2.x analytics storage (if ever shipped) would also flow through
 *   the guard so the privacy posture is consistent across surfaces.
 *
 * Allow-lists are deliberately small and explicit. Anything an admin's
 * listener can correlate to PII (campaign_id, currency, language) is
 * carefully scoped: campaign_id is a public WP post ID (already enumerable),
 * currency is global site config, language is WPML's active set or the
 * site locale. None of these reveal anything about the donor.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Analytics;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;

final class Privacy_Guard {

	/**
	 * Allow-listed event type names. The guard rejects unknown types so a
	 * misconfigured client can't inject `do_action( 'dfwc_companion_<arbitrary>' )`
	 * into the listener chain.
	 *
	 * @return array<int,string>
	 */
	public static function event_types(): array {
		return array(
			'form_viewed',
			'interval_selected',
			'preset_selected',
			'custom_amount_entered',
			'donation_submitted',
			'donation_failed',
		);
	}

	/**
	 * Allow-listed render contexts. Mirrors the values
	 * `Frontend\Context_Augmenter` emits via `data-context` on the overlay
	 * wrapper. `unknown` is the safe fallback when the context can't be
	 * derived (e.g. donor on an old cached page that predates Phase 9).
	 *
	 * @return array<int,string>
	 */
	public static function contexts(): array {
		return array(
			'single',
			'shortcode',
			'widget',
			'checkout',
			'cart',
			'cart_block',
			'block',
			'elementor',
			'preview',
			'unknown',
		);
	}

	/**
	 * Sanitize an inbound raw event into the canonical sparse shape the
	 * track REST controller passes to `do_action`. Returns an array; the
	 * caller checks the `type` field — empty string means the event was
	 * malformed and should be silently dropped.
	 *
	 * Server time replaces any client-supplied timestamp to defeat clock-
	 * drift / replay shenanigans.
	 *
	 * @param array<string,mixed> $event
	 * @return array<string,mixed>
	 */
	public static function sanitize_event( array $event ): array {
		return array(
			'type'        => self::sanitize_type( (string) ( $event['type'] ?? '' ) ),
			'campaign_id' => self::sanitize_campaign_id( $event['campaign_id'] ?? 0 ),
			'interval'    => self::sanitize_interval( (string) ( $event['interval'] ?? '' ) ),
			'amount'      => self::sanitize_amount( $event['amount'] ?? 0 ),
			'context'     => self::sanitize_context( (string) ( $event['context'] ?? '' ) ),
			'currency'    => self::sanitize_currency( (string) ( $event['currency'] ?? '' ) ),
			'language'    => self::sanitize_language( (string) ( $event['language'] ?? '' ) ),
			'reason'      => self::sanitize_reason( (string) ( $event['reason'] ?? '' ) ),
			'ts'          => time(),
		);
	}

	public static function sanitize_type( string $type ): string {
		$type = strtolower( trim( $type ) );
		return in_array( $type, self::event_types(), true ) ? $type : '';
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_campaign_id( $value ): int {
		return absint( $value );
	}

	public static function sanitize_interval( string $interval ): string {
		$interval = sanitize_key( $interval );
		// All 7 intervals (standard + advanced). Empty string is the safe
		// fallback for events that don't carry an interval (form_viewed).
		$allowed = Config_Resolver::intervals( true );
		if ( '' === $interval ) {
			return '';
		}
		return in_array( $interval, $allowed, true ) ? $interval : '';
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_amount( $value ): float {
		$amount = (float) $value;
		if ( $amount < 0 || ! is_finite( $amount ) ) {
			return 0.0;
		}
		// Cap at 9 figures to defeat overflow / abusive payloads. A single
		// $1B donation event is the kind of thing a CRM should flag, not
		// a silent drop — but we also don't want to ship `INF` to listeners.
		if ( $amount > 999999999.0 ) {
			return 999999999.0;
		}
		return $amount;
	}

	public static function sanitize_context( string $context ): string {
		$context = sanitize_key( $context );
		return in_array( $context, self::contexts(), true ) ? $context : 'unknown';
	}

	public static function sanitize_currency( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );
		if ( '' === $currency ) {
			return Currency_Preset_Resolver::base_currency();
		}
		// 3-letter ISO 4217 shape — defensive against arbitrary strings.
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return Currency_Preset_Resolver::base_currency();
		}
		return $currency;
	}

	/**
	 * Validate a language code against WPML's active-language set when
	 * WPML is installed; otherwise against the site locale. Falls back to
	 * empty string for anything not on the allow-list — listeners can
	 * decide whether `language=''` means "unknown" or "site default".
	 */
	public static function sanitize_language( string $language ): string {
		$language = trim( $language );
		if ( '' === $language ) {
			return '';
		}

		// Cheap shape gate first — language codes are short ASCII, e.g. `en`,
		// `en_US`, `pt-br`. Reject anything outside that pattern before we
		// hit the WPML filter (which can be expensive).
		if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_\-]{1,9}$/', $language ) ) {
			return '';
		}

		$allowed = self::active_language_codes();
		// Compare case-insensitively (WPML uses `en` while `get_locale` uses
		// `en_US` — both should pass when present in the allow-list).
		foreach ( $allowed as $code ) {
			if ( strcasecmp( $code, $language ) === 0 ) {
				return $code;
			}
		}
		return '';
	}

	public static function sanitize_reason( string $reason ): string {
		$reason = sanitize_text_field( $reason );
		if ( strlen( $reason ) > 200 ) {
			$reason = substr( $reason, 0, 200 );
		}
		return $reason;
	}

	/**
	 * Active language codes — WPML's set when active, else the site locale
	 * (split on `_` so `en_US` exposes both `en_US` and `en`). Filterable
	 * for site-specific overrides.
	 *
	 * @return array<int,string>
	 */
	public static function active_language_codes(): array {
		$codes = array();

		// WPML primary.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook
		$wpml_languages = apply_filters( 'wpml_active_languages', null, '' );
		if ( is_array( $wpml_languages ) ) {
			foreach ( $wpml_languages as $code => $info ) {
				if ( is_string( $code ) && '' !== $code ) {
					$codes[] = $code;
				}
			}
		}

		// Always include the site locale as a fallback — even on WPML sites,
		// non-frontend code paths (admin preview) report the admin's locale.
		if ( function_exists( 'get_locale' ) ) {
			$locale = get_locale();
			if ( is_string( $locale ) && '' !== $locale ) {
				$codes[] = $locale;
				if ( false !== strpos( $locale, '_' ) ) {
					$codes[] = explode( '_', $locale )[0];
				}
			}
		}

		$codes = array_values( array_unique( array_filter( $codes ) ) );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed hook
		$filtered = apply_filters( 'dfwc_companion_active_language_codes', $codes );
		return is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ) ) ) : $codes;
	}
}
