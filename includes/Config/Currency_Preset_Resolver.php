<?php
/**
 * Currency_Preset_Resolver — render-time per-currency overlay on top of the
 * already-resolved campaign config.
 *
 * `Config_Resolver::resolve()` returns the layered config in base currency
 * (defaults → global → template → campaign overrides). This class layers a
 * SECOND, render-time pass on top: if the donor's active currency isn't the
 * base currency AND the interval block has a `currency_overrides[$currency]`
 * entry, the resolver merges that entry's amount/min/max into the block.
 *
 * Why a separate pass: `Config_Resolver` is currency-agnostic and cache-
 * friendly. Currency context can change between requests (donor picks GBP in
 * the WCML switcher mid-session) without invalidating any of the layered
 * config — only the final per-currency overlay needs to re-resolve.
 *
 * Currency override blocks are intentionally SPARSE — only fields that differ
 * from the base block need to be present. Missing fields fall through to the
 * base. Per-preset labels / impact_label / is_featured / sort_order are NOT
 * overrideable per currency by design (psychological pricing varies by
 * currency, but the messaging copy is shared).
 *
 * Multi-currency integration:
 * - **Primary:** WPML WooCommerce Multilingual (WCML). Active currency via
 *   `wcml_get_user_currency()`; supported currencies via `wcml_multi_currency()
 *   ->get_active_currencies()`.
 * - **Fallback:** WC base currency (`get_woocommerce_currency()`).
 * - **Filter-based extension:** `dfwc_companion_active_currency` is the final
 *   override point — a 5-line snippet wires WCPay Multi-Currency, Aelia
 *   Currency Switcher, or any custom switcher.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Currency_Preset_Resolver {

	/**
	 * Resolve a per-interval block for the donor's active currency.
	 *
	 * If `$currency` is omitted, uses `active_currency()`. If the resolved
	 * currency matches base currency OR no override is defined for the target
	 * currency, returns the block untouched (donor sees base presets,
	 * displayed in base currency).
	 *
	 * @param array<string,mixed> $interval_block A single interval block — the
	 *                                            `one_time` / `monthly` /
	 *                                            `annual` sub-tree from a
	 *                                            resolved campaign config.
	 * @param string|null         $currency       ISO currency code; null to
	 *                                            auto-detect.
	 * @return array<string,mixed> The block, possibly with merged per-currency
	 *                             overrides. Always carries a
	 *                             `_resolved_currency` key for downstream
	 *                             telemetry / rendering.
	 */
	public static function resolve( array $interval_block, ?string $currency = null ): array {
		$currency      = null !== $currency ? $currency : self::active_currency();
		$base_currency = self::base_currency();

		$resolved                       = $interval_block;
		$resolved['_resolved_currency'] = $currency;

		// Same currency as base — no override needed.
		if ( $currency === $base_currency ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return apply_filters(
				'dfwc_companion_resolved_currency_block',
				$resolved,
				$interval_block,
				$currency,
				$base_currency
			);
		}

		$overrides = isset( $interval_block['currency_overrides'][ $currency ] )
			&& is_array( $interval_block['currency_overrides'][ $currency ] )
			? $interval_block['currency_overrides'][ $currency ]
			: null;

		if ( null === $overrides ) {
			// No override defined for this currency — donor sees base presets,
			// displayed in their currency by `wc_price()`. Acceptable fallback;
			// admins can add overrides later without code changes.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return apply_filters(
				'dfwc_companion_resolved_currency_block',
				$resolved,
				$interval_block,
				$currency,
				$base_currency
			);
		}

		// Override presets — only the `amount` is overrideable. label /
		// impact_label / is_featured / sort_order come from the base preset at
		// the matching index. Override presets beyond base count are dropped
		// (admin UI prevents this; defensive here).
		if ( isset( $overrides['presets'] ) && is_array( $overrides['presets'] ) ) {
			$base_presets    = isset( $interval_block['presets'] ) && is_array( $interval_block['presets'] )
				? $interval_block['presets']
				: array();
			$merged_presets  = array();
			$base_preset_ct  = count( $base_presets );
			foreach ( $overrides['presets'] as $idx => $override_preset ) {
				if ( ! is_array( $override_preset ) ) {
					continue;
				}
				if ( $idx >= $base_preset_ct ) {
					continue;
				}
				$base_preset = $base_presets[ $idx ];
				if ( ! is_array( $base_preset ) ) {
					continue;
				}
				if ( ! isset( $override_preset['amount'] ) ) {
					$merged_presets[] = $base_preset;
					continue;
				}
				$override_amount = (float) $override_preset['amount'];
				if ( $override_amount <= 0 ) {
					$merged_presets[] = $base_preset;
					continue;
				}
				$merged                  = $base_preset;
				$merged['amount']        = $override_amount;
				$merged['_base_amount']  = isset( $base_preset['amount'] ) ? (float) $base_preset['amount'] : 0.0;
				$merged_presets[]        = $merged;
			}
			$resolved['presets'] = $merged_presets;
		}

		foreach ( array( 'min', 'max' ) as $field ) {
			if ( isset( $overrides[ $field ] ) ) {
				$resolved[ $field ] = (float) $overrides[ $field ];
			}
		}

		if ( ( $resolved['max'] ?? 0.0 ) < ( $resolved['min'] ?? 0.0 ) ) {
			$resolved['max'] = $resolved['min'];
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters(
			'dfwc_companion_resolved_currency_block',
			$resolved,
			$interval_block,
			$currency,
			$base_currency
		);
	}

	/**
	 * The donor's active currency. WCML primary; WC base currency fallback.
	 * Filterable so non-WCML stacks (WCPay Multi-Currency, Aelia, custom) can
	 * inject their own active-currency lookup.
	 */
	public static function active_currency(): string {
		$currency = self::base_currency();

		if ( function_exists( 'wcml_get_user_currency' ) ) {
			$wcml_currency = wcml_get_user_currency();
			if ( is_string( $wcml_currency ) && '' !== $wcml_currency ) {
				$currency = $wcml_currency;
			}
		}

		$context = array(
			'context' => function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'frontend',
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$filtered = apply_filters( 'dfwc_companion_active_currency', $currency, $context );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $currency;
	}

	/**
	 * The configured WC base currency. USD fallback when WC isn't loaded
	 * (defensive — companion's bootstrap requires WC, but this method may be
	 * called from contexts where WC functions aren't yet defined).
	 */
	public static function base_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
			if ( is_string( $currency ) && '' !== $currency ) {
				return $currency;
			}
		}
		return 'USD';
	}

	/**
	 * Currencies the admin can configure per-currency overrides for.
	 *
	 * - With WCML active: the WCML "active currencies" set (the currencies the
	 *   admin enabled in WCML's currency-switcher config).
	 * - Without WCML: just base currency (admin sees no per-currency UI; saves
	 *   stay backward-compatible with v0.6.x).
	 *
	 * Filterable so a non-WCML multi-currency stack can publish its own list.
	 *
	 * @return array<int,string> ISO currency codes.
	 */
	public static function supported_currencies(): array {
		$active = array( self::base_currency() );

		if ( function_exists( 'wcml_multi_currency' ) ) {
			$wcml = wcml_multi_currency();
			if ( is_object( $wcml ) && method_exists( $wcml, 'get_active_currencies' ) ) {
				$wcml_active = $wcml->get_active_currencies();
				if ( is_array( $wcml_active ) && ! empty( $wcml_active ) ) {
					$active = array_keys( $wcml_active );
				}
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$filtered = apply_filters( 'dfwc_companion_supported_currencies', $active );

		if ( ! is_array( $filtered ) || empty( $filtered ) ) {
			return array( self::base_currency() );
		}

		// Normalize: uppercase ISO codes, unique, drop empties.
		$out = array();
		foreach ( $filtered as $code ) {
			if ( ! is_string( $code ) ) {
				continue;
			}
			$code = strtoupper( trim( $code ) );
			if ( '' === $code || in_array( $code, $out, true ) ) {
				continue;
			}
			$out[] = $code;
		}

		return ! empty( $out ) ? $out : array( self::base_currency() );
	}

	/**
	 * Extra (non-base) currencies — the codes the admin needs to see preset
	 * tables for in addition to base. Convenience for the meta box / templates
	 * page UI.
	 *
	 * @return array<int,string>
	 */
	public static function extra_currencies(): array {
		$base = self::base_currency();
		return array_values(
			array_filter(
				self::supported_currencies(),
				static function ( $code ) use ( $base ) {
					return $code !== $base;
				}
			)
		);
	}

	/**
	 * True iff the site has more than one supported currency. Used by admin UI
	 * to gate per-currency UI rendering — a monolingual / single-currency site
	 * sees no extra UI noise.
	 */
	public static function multi_currency_active(): bool {
		return count( self::supported_currencies() ) > 1;
	}

	/**
	 * Sanitize a raw `currency_overrides` POST sub-tree into the canonical
	 * sparse shape. Used by Meta_Box, Templates_Page, Settings_Page, and
	 * Template_Validator so all three sanitizers stay in sync.
	 *
	 * Allow-list:
	 * - Currency codes filtered through `supported_currencies()`. Unknown
	 *   codes silently dropped (defense against admin posting arbitrary keys).
	 * - Per-currency block: only `presets` (amount-only rows), `min`, `max`
	 *   keys retained. Other fields ignored.
	 * - Empty currency override blocks (no presets, no min/max) dropped.
	 *
	 * @param array<string,mixed> $raw            Raw `currency_overrides` from POST.
	 * @param int                 $base_preset_ct Count of presets in the base block;
	 *                                            override preset slots beyond this
	 *                                            are ignored.
	 * @return array<string,array<string,mixed>>
	 */
	public static function sanitize_currency_overrides( $raw, int $base_preset_ct ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$base_currency = self::base_currency();
		$supported     = self::supported_currencies();
		$out           = array();

		foreach ( $raw as $code => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$code = is_string( $code ) ? strtoupper( trim( $code ) ) : '';
			if ( '' === $code || $code === $base_currency ) {
				continue;
			}
			if ( ! in_array( $code, $supported, true ) ) {
				continue;
			}

			$clean = array();

			if ( isset( $block['presets'] ) && is_array( $block['presets'] ) ) {
				$clean_presets = array();
				$slot          = 0;
				foreach ( $block['presets'] as $row ) {
					if ( $slot >= $base_preset_ct ) {
						break;
					}
					if ( is_array( $row ) && isset( $row['amount'] ) ) {
						$amount_str = (string) $row['amount'];
						$amount     = function_exists( 'wc_format_decimal' )
							? (float) wc_format_decimal(
								$amount_str,
								function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2
							)
							: (float) $amount_str;
						if ( $amount > 0 ) {
							$clean_presets[] = array( 'amount' => $amount );
							++$slot;
							continue;
						}
					}
					// Empty / zero / invalid override slot — skip but advance the
					// slot counter so amounts stay aligned with base preset
					// indexes when the admin fills in only some currencies.
					$clean_presets[] = array();
					++$slot;
				}
				// Trim trailing empty slots (no information stored in tail empties).
				while ( ! empty( $clean_presets ) ) {
					$tail = end( $clean_presets );
					if ( is_array( $tail ) && empty( $tail ) ) {
						array_pop( $clean_presets );
						continue;
					}
					break;
				}
				if ( ! empty( $clean_presets ) ) {
					// $clean_presets is built via [] = ... so it's already a list.
					$clean['presets'] = $clean_presets;
				}
			}

			foreach ( array( 'min', 'max' ) as $field ) {
				if ( ! isset( $block[ $field ] ) ) {
					continue;
				}
				$value = function_exists( 'wc_format_decimal' )
					? (float) wc_format_decimal(
						(string) $block[ $field ],
						function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2
					)
					: (float) $block[ $field ];
				if ( $value > 0 ) {
					$clean[ $field ] = $value;
				}
			}

			if ( isset( $clean['min'], $clean['max'] ) && $clean['max'] < $clean['min'] ) {
				$clean['max'] = $clean['min'];
			}

			if ( ! empty( $clean ) ) {
				$out[ $code ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * Friendly label for a currency code: "GBP — £". Used by admin UI add-
	 * currency dropdowns. Falls back to the bare code when WC currency-symbol
	 * helper is unavailable.
	 */
	public static function currency_label( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return '';
		}
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = get_woocommerce_currency_symbol( $code );
			if ( is_string( $symbol ) && '' !== $symbol && $symbol !== $code ) {
				return $code . ' — ' . html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $code;
	}
}
