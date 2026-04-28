<?php
/**
 * Config_Resolver — single source of truth for resolved campaign config.
 *
 * Phase 3 introduces a layered model:
 *
 *   Defaults  →  Global settings  →  Named template  →  Campaign overrides
 *                                                       (or detached snapshot)
 *
 * The resolver merges these layers at read time, with deep-merge for
 * associative arrays and replace-wholesale for sequential lists (so
 * overriding `monthly.presets` swaps the array instead of appending).
 *
 * Backward-compat: campaigns saved under v0.6.x have a populated
 * `_dfwc_companion_intervals` meta key and no `_dfwc_companion_overrides`
 * meta key. The resolver reads the legacy meta as a final fallback layer
 * when no overrides are set, so v0.6.x campaigns continue to render
 * correctly without admin action. The next admin save migrates legacy
 * meta to the v0.7.0 overrides key (Campaign_Config_Repository::migrate_legacy_to_overrides_if_needed).
 *
 * Public surface preserved from v0.6.x: resolve(), resolve_display(),
 * is_configured(), intervals(), defaults(), display_defaults(),
 * META_KEY_INTERVALS, META_KEY_DISPLAY, INTERVAL_* constants. New in v2:
 * META_KEY_TEMPLATE, META_KEY_OVERRIDES, META_KEY_DETACHED,
 * get_global_settings(), update_global_settings(), trace_inheritance().
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\I18n\WPML_Strings;

final class Config_Resolver {

	// === Meta keys (v0.6.x preserved + v0.7.0 additions) ===
	public const META_KEY_INTERVALS = '_dfwc_companion_intervals';
	public const META_KEY_DISPLAY   = '_dfwc_companion_display';
	public const META_KEY_TEMPLATE  = '_dfwc_companion_template_id';
	public const META_KEY_OVERRIDES = '_dfwc_companion_overrides';
	public const META_KEY_DETACHED  = '_dfwc_companion_detached';

	// === Option keys ===
	public const OPTION_KEY_GLOBAL = 'dfwc_companion_global_settings';

	// === Interval keys ===
	public const INTERVAL_ONE_TIME   = 'one_time';
	public const INTERVAL_MONTHLY    = 'monthly';
	public const INTERVAL_ANNUAL     = 'annual';
	// Phase 7 — advanced intervals (opt-in via global setting).
	public const INTERVAL_WEEKLY     = 'weekly';
	public const INTERVAL_QUARTERLY  = 'quarterly';
	public const INTERVAL_SEMIANNUAL = 'semiannual';
	public const INTERVAL_CUSTOM     = 'custom';

	/** @var Template_Repository|null */
	private static ?Template_Repository $template_repo = null;

	/**
	 * Allow-listed interval keys, in display order. The standard three
	 * (one_time / monthly / annual) are always included; advanced intervals
	 * (weekly / quarterly / semiannual / custom) only when the caller asks
	 * for them OR the global toggle is on.
	 *
	 * @param bool $include_advanced When true, returns all 7 keys regardless
	 *                               of the global toggle. Sanitizers pass true
	 *                               so storage round-trips advanced data even
	 *                               when the toggle is currently off (lets
	 *                               admins flip the toggle without losing
	 *                               saved config). The renderer respects the
	 *                               toggle independently.
	 * @return array<int,string>
	 */
	public static function intervals( bool $include_advanced = false ): array {
		$base = array( self::INTERVAL_ONE_TIME, self::INTERVAL_MONTHLY, self::INTERVAL_ANNUAL );
		if ( $include_advanced || self::advanced_enabled() ) {
			return array_merge( $base, self::advanced_intervals() );
		}
		return $base;
	}

	/**
	 * The advanced-interval keys, in display order. Shipped under the
	 * `enable_advanced_intervals` global toggle (Phase 7).
	 *
	 * @return array<int,string>
	 */
	public static function advanced_intervals(): array {
		return array(
			self::INTERVAL_WEEKLY,
			self::INTERVAL_QUARTERLY,
			self::INTERVAL_SEMIANNUAL,
			self::INTERVAL_CUSTOM,
		);
	}

	/**
	 * True iff the global setting `enable_advanced_intervals` is on.
	 * Filterable so site-specific snippets / programmatic activation can
	 * enable advanced intervals without flipping the UI toggle.
	 */
	public static function advanced_enabled(): bool {
		$enabled = (bool) ( self::get_global_settings()['enable_advanced_intervals'] ?? false );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed hook
		return (bool) apply_filters( 'dfwc_companion_advanced_intervals_enabled', $enabled );
	}

	/**
	 * Resolve the full config for one campaign by merging all layers.
	 *
	 * @return array<string,mixed>
	 */
	public static function resolve( int $campaign_id ): array {
		// Layer 1: plugin defaults.
		$config = Defaults::for_campaign();

		// Layer 2: global settings.
		$config = self::deep_merge( $config, self::extract_layer( self::get_global_settings() ) );

		// Layer 3: named template (skipped when detached).
		$template_id = $campaign_id > 0 ? (string) get_post_meta( $campaign_id, self::META_KEY_TEMPLATE, true ) : '';
		$detached    = $campaign_id > 0 ? (bool) get_post_meta( $campaign_id, self::META_KEY_DETACHED, true ) : false;

		if ( '' !== $template_id && ! $detached ) {
			$template = self::template_repository()->get( $template_id );
			if ( $template ) {
				$config = self::deep_merge( $config, self::extract_layer( $template->config ) );
			}
		}

		// Layer 4: campaign overrides.
		$overrides = $campaign_id > 0 ? get_post_meta( $campaign_id, self::META_KEY_OVERRIDES, true ) : null;
		if ( is_array( $overrides ) && ! empty( $overrides ) ) {
			$config = self::deep_merge( $config, self::extract_layer( $overrides ) );
		} elseif ( $campaign_id > 0 ) {
			// Layer 4-legacy: v0.6.x intervals meta. Read as fallback when
			// overrides are absent AND there's no template (otherwise the
			// admin's template choice would be silently overridden by old data).
			if ( '' === $template_id && ! $detached ) {
				$legacy_intervals = get_post_meta( $campaign_id, self::META_KEY_INTERVALS, true );
				if ( is_array( $legacy_intervals ) && ! empty( $legacy_intervals ) ) {
					$config = self::deep_merge( $config, self::extract_layer( $legacy_intervals ) );
				}
				$legacy_display = get_post_meta( $campaign_id, self::META_KEY_DISPLAY, true );
				if ( is_array( $legacy_display ) && ! empty( $legacy_display ) ) {
					$config['display'] = array_merge( $config['display'], $legacy_display );
				}
			}
		}

		// Validate / clamp / sort.
		$config = self::validate_and_clamp( $config );

		// WPML translation pass: walk known translatable fields and pass
		// each through WPML_Strings::translate(). No-op when WPML inactive
		// (saves the filter-traversal cost on monolingual sites).
		if ( WPML_Strings::wpml_active() ) {
			$config = self::translate_strings( $config, $campaign_id, $template_id );
		}

		/**
		 * Final filter on the resolved config. Existing in v0.6.x; preserved.
		 *
		 * @param array<string,mixed> $config
		 * @param int                 $campaign_id
		 */
		$config = (array) apply_filters( 'dfwc_companion_resolved_config', $config, $campaign_id );

		return $config;
	}

	/**
	 * Convenience: just the display sub-tree from resolve().
	 *
	 * @return array{show_title:bool,show_image:bool,cause_heading:string}
	 */
	public static function resolve_display( int $campaign_id ): array {
		$resolved = self::resolve( $campaign_id );
		// Cast values defensively in case a filter mutates types.
		$display = isset( $resolved['display'] ) && is_array( $resolved['display'] )
			? $resolved['display']
			: Defaults::display();
		return array(
			'show_title'    => ! empty( $display['show_title'] ),
			'show_image'    => ! empty( $display['show_image'] ),
			'cause_heading' => isset( $display['cause_heading'] ) ? (string) $display['cause_heading'] : '',
		);
	}

	/**
	 * True when the campaign has any companion config (template, overrides,
	 * or legacy v0.6.x intervals meta). Used by Context_Augmenter to gate
	 * whether to wrap parent's render — campaigns with no companion config
	 * see parent's vanilla form unchanged.
	 */
	public static function is_configured( int $campaign_id ): bool {
		if ( $campaign_id < 1 ) {
			return false;
		}
		if ( '' !== (string) get_post_meta( $campaign_id, self::META_KEY_TEMPLATE, true ) ) {
			return true;
		}
		$overrides = get_post_meta( $campaign_id, self::META_KEY_OVERRIDES, true );
		if ( is_array( $overrides ) && ! empty( $overrides ) ) {
			return true;
		}
		$legacy = get_post_meta( $campaign_id, self::META_KEY_INTERVALS, true );
		return is_array( $legacy ) && ! empty( $legacy );
	}

	/**
	 * Plugin-default config (preserved from v0.6.x; delegates to Defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return Defaults::for_campaign();
	}

	/**
	 * Display-options defaults (preserved from v0.6.x; delegates to Defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function display_defaults(): array {
		return Defaults::display();
	}

	/**
	 * Read the global-settings option, merged with the global defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_global_settings(): array {
		$stored = get_option( self::OPTION_KEY_GLOBAL, array() );
		if ( ! is_array( $stored ) ) {
			return Defaults::for_global();
		}
		// array_merge gives stored values precedence over defaults for top-level
		// scalars; intervals/display sub-trees aren't recursively merged here
		// because Settings_Page writes complete sub-trees.
		return array_merge( Defaults::for_global(), $stored );
	}

	/**
	 * Update the global-settings option. Always stamps version so future
	 * migrations can read it back. Merge semantics: top-level scalars
	 * replace; nested arrays replace wholesale (caller passes the full new
	 * sub-tree).
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function update_global_settings( array $settings ): bool {
		$merged            = array_merge( self::get_global_settings(), $settings );
		$merged['version'] = 1;
		return (bool) update_option( self::OPTION_KEY_GLOBAL, $merged, true );
	}

	/**
	 * Trace which layer each resolved field came from. Powers the admin
	 * meta-box's "inherited" / "overridden" pill UI.
	 *
	 * Returns a flat map of dotted-path → source layer:
	 *   'monthly.cta_template'              => 'template:school_sponsorship'
	 *   'monthly.presets.0.amount'          => 'campaign_override'
	 *   'one_time.enabled'                  => 'global'
	 *   'annual.cta_template'               => 'default'
	 *
	 * Implementation walks each layer; the LAST writer of any path wins
	 * and is recorded. Callers compare against the resolved config to
	 * decorate the meta-box UI.
	 *
	 * @return array<string,string>
	 */
	public static function trace_inheritance( int $campaign_id ): array {
		$trace = array();

		// Layer 1: defaults.
		self::flatten_into_trace( Defaults::for_campaign(), 'default', $trace );

		// Layer 2: global.
		$global_extracted = self::extract_layer( self::get_global_settings() );
		self::flatten_into_trace( $global_extracted, 'global', $trace );

		// Layer 3: template.
		$template_id = $campaign_id > 0 ? (string) get_post_meta( $campaign_id, self::META_KEY_TEMPLATE, true ) : '';
		$detached    = $campaign_id > 0 ? (bool) get_post_meta( $campaign_id, self::META_KEY_DETACHED, true ) : false;
		if ( '' !== $template_id && ! $detached ) {
			$template = self::template_repository()->get( $template_id );
			if ( $template ) {
				self::flatten_into_trace( self::extract_layer( $template->config ), 'template:' . $template_id, $trace );
			}
		}

		// Layer 4: campaign overrides.
		if ( $campaign_id > 0 ) {
			$overrides = get_post_meta( $campaign_id, self::META_KEY_OVERRIDES, true );
			if ( is_array( $overrides ) && ! empty( $overrides ) ) {
				self::flatten_into_trace( self::extract_layer( $overrides ), 'campaign_override', $trace );
			}
		}

		return $trace;
	}

	// === Internals ===

	private static function template_repository(): Template_Repository {
		if ( null === self::$template_repo ) {
			self::$template_repo = new Template_Repository();
		}
		return self::$template_repo;
	}

	/**
	 * Strip metadata fields like 'version', 'created_at' from a stored layer
	 * before merging — only the schema-relevant keys survive.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function extract_layer( array $data ): array {
		$allowed = array_merge(
			array( 'default_interval', 'display' ),
			self::intervals( true )
		);
		return array_intersect_key( $data, array_flip( $allowed ) );
	}

	/**
	 * Deep-merge two associative arrays. Sequential arrays (preset lists)
	 * replace wholesale rather than concatenate — overriding presets means
	 * SWAPPING the list, not appending to it.
	 *
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $override
	 * @return array<string,mixed>
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				if ( self::is_associative( $value ) && self::is_associative( $base[ $key ] ) ) {
					$base[ $key ] = self::deep_merge( $base[ $key ], $value );
				} else {
					// Sequential array (presets list) — replace wholesale.
					$base[ $key ] = $value;
				}
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * @param array<int|string,mixed> $arr
	 */
	private static function is_associative( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Flatten a nested config into trace-pathed entries, recording the
	 * source layer for each leaf path. Used by trace_inheritance.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,string> $trace Modified by reference.
	 */
	private static function flatten_into_trace( array $data, string $layer, array &$trace, string $prefix = '' ): void {
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && self::is_associative( $value ) ) {
				self::flatten_into_trace( $value, $layer, $trace, $path );
			} else {
				$trace[ $path ] = $layer;
			}
		}
	}

	/**
	 * Validate, clamp, sort. Runs after all layer merges.
	 *
	 * - Clamps min ≥ 0.01 and max ≥ min
	 * - Sorts presets by sort_order (ascending) and reindexes
	 * - Clamps default_index to [0, preset_count - 1]
	 * - Ensures display sub-tree has all expected keys
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private static function validate_and_clamp( array $config ): array {
		// Walk all known intervals (standard + advanced). Always include
		// advanced so resolved config carries them even when the global
		// toggle is off — gating happens at render time, not storage time.
		foreach ( self::intervals( true ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
				$config[ $key ] = Defaults::interval_block( false, '', $key );
			}
			$block = $config[ $key ];

			$block['min'] = max( 0.01, (float) ( $block['min'] ?? 5.0 ) );
			$block['max'] = max( $block['min'], (float) ( $block['max'] ?? 10000.0 ) );

			$presets = isset( $block['presets'] ) && is_array( $block['presets'] ) ? $block['presets'] : array();
			usort(
				$presets,
				static function ( $a, $b ): int {
					return ( (int) ( $a['sort_order'] ?? 0 ) ) <=> ( (int) ( $b['sort_order'] ?? 0 ) );
				}
			);
			$block['presets'] = array_values( $presets );

			$count = count( $block['presets'] );
			$block['default_index'] = $count > 0
				? max( 0, min( (int) ( $block['default_index'] ?? 0 ), $count - 1 ) )
				: 0;

			// Phase 7 — clamp custom-interval cadence fields when present.
			if ( self::INTERVAL_CUSTOM === $key ) {
				$block['custom_period']   = Engine_Interval_Map::sanitize_period( (string) ( $block['custom_period'] ?? 'month' ) );
				$block['custom_interval'] = Engine_Interval_Map::sanitize_multiplier( (int) ( $block['custom_interval'] ?? 1 ) );
				$block['custom_label']    = isset( $block['custom_label'] )
					? (string) $block['custom_label']
					: '';
			}

			$config[ $key ] = $block;
		}

		// Ensure display sub-tree has all expected keys.
		$display_defaults = Defaults::display();
		$display_existing = isset( $config['display'] ) && is_array( $config['display'] ) ? $config['display'] : array();
		$config['display'] = array_merge( $display_defaults, $display_existing );

		return $config;
	}

	/**
	 * Walk known translatable string fields and pass each through
	 * WPML_Strings::translate(). The namespace is the template_id when a
	 * template is assigned, else `_global` (for global-defaults strings) or
	 * `_campaign:<id>` (for campaign-override strings).
	 *
	 * Strings registered with one namespace and resolved with a different
	 * one would miss their translation; we honor the writer's namespace.
	 * For now the resolver simplifies by using the template_id when present
	 * and `_global` otherwise — the dominant case.
	 *
	 * @param array<string,mixed> $config
	 */
	private static function translate_strings( array $config, int $campaign_id, string $template_id ): array {
		$namespace = '' !== $template_id ? $template_id : '_global';
		foreach ( self::intervals( true ) as $interval ) {
			if ( ! isset( $config[ $interval ] ) || ! is_array( $config[ $interval ] ) ) {
				continue;
			}
			$block = $config[ $interval ];
			$translatable_fields = array( 'cta_template', 'subtitle', 'annual_equivalency', 'custom_amount_impact_label' );
			if ( self::INTERVAL_CUSTOM === $interval ) {
				$translatable_fields[] = 'custom_label';
			}
			foreach ( $translatable_fields as $field ) {
				if ( ! empty( $block[ $field ] ) ) {
					$block[ $field ] = WPML_Strings::translate(
						(string) $block[ $field ],
						$namespace . '.' . $interval . '.' . $field
					);
				}
			}
			if ( ! empty( $block['presets'] ) && is_array( $block['presets'] ) ) {
				foreach ( $block['presets'] as $idx => $preset ) {
					if ( ! is_array( $preset ) ) {
						continue;
					}
					foreach ( array( 'label', 'impact_label' ) as $preset_field ) {
						if ( ! empty( $preset[ $preset_field ] ) ) {
							$block['presets'][ $idx ][ $preset_field ] = WPML_Strings::translate(
								(string) $preset[ $preset_field ],
								$namespace . '.' . $interval . '.presets.' . $idx . '.' . $preset_field
							);
						}
					}
				}
			}
			$config[ $interval ] = $block;
		}

		if ( ! empty( $config['display']['cause_heading'] ) ) {
			$config['display']['cause_heading'] = WPML_Strings::translate(
				(string) $config['display']['cause_heading'],
				$namespace . '.display.cause_heading'
			);
		}

		return $config;
	}
}
