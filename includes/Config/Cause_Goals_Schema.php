<?php
/**
 * Cause_Goals_Schema — single source of truth for per-cause goal storage.
 *
 * Mirrors the TGB_Project_Mapper pattern from v2.3.0 (13.E): a static
 * read/write surface over the layered overrides meta. Per-cause goals
 * live under `_dfwc_companion_overrides.cause_goals`, keyed by the
 * companion-minted cause id (Cause_Identity).
 *
 * Per-cause shape:
 *
 *   array(
 *       '<cause_id>' => array(
 *           'enabled'       => bool,    // per-cause opt-in
 *           'goal_amount'   => float,   // 0 disables
 *           'closure_mode'  => string,  // see CLOSURE_MODE_* constants
 *                                       // 'inherit' => use global default
 *       ),
 *   )
 *
 * Closure modes (per the v2.4.0 plan §3.5):
 *
 *   - hide                  — silently drop the closed cause from the picker
 *   - redirect_to_cause     — show with "fully funded" badge + prompt to pick another
 *   - redirect_off_campaign — send donor to general fund / configured fallback
 *   - inherit               — sentinel; use global default_cause_closure_mode
 *
 * Defaults: per-cause `enabled` defaults FALSE (cause goals are opt-in
 * per cause). Per-cause `closure_mode` defaults INHERIT. Global
 * `default_cause_closure_mode` defaults to redirect_to_cause (matches
 * Phase 13's "surface, don't hard-block" UX precedent).
 *
 * Persistence: set() reads + merges + writes to avoid stomping other
 * overrides namespaces (intervals, display, crypto). Same merge pattern
 * TGB_Project_Mapper::set uses.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Cause_Goals_Schema {

	public const OVERRIDES_META_KEY = '_dfwc_companion_overrides';
	public const OVERRIDES_NAMESPACE = 'cause_goals';

	public const CLOSURE_MODE_INHERIT              = 'inherit';
	public const CLOSURE_MODE_HIDE                 = 'hide';
	public const CLOSURE_MODE_REDIRECT_TO_CAUSE    = 'redirect_to_cause';
	public const CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN = 'redirect_off_campaign';

	public const DEFAULT_CLOSURE_MODE = self::CLOSURE_MODE_REDIRECT_TO_CAUSE;

	/**
	 * Closure modes valid as the per-cause stored value. Includes
	 * `inherit` (defers to the global default).
	 *
	 * @return array<int,string>
	 */
	public static function closure_modes(): array {
		return array(
			self::CLOSURE_MODE_INHERIT,
			self::CLOSURE_MODE_HIDE,
			self::CLOSURE_MODE_REDIRECT_TO_CAUSE,
			self::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN,
		);
	}

	/**
	 * Closure modes valid as the global default — `inherit` excluded
	 * (the global IS the inherited value; can't inherit from itself).
	 *
	 * @return array<int,string>
	 */
	public static function global_closure_modes(): array {
		return array(
			self::CLOSURE_MODE_HIDE,
			self::CLOSURE_MODE_REDIRECT_TO_CAUSE,
			self::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN,
		);
	}

	/**
	 * Read the full per-cause goals map for a campaign. Empty array
	 * when no goals are configured.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function for_campaign( int $campaign_id ): array {
		$overrides = get_post_meta( $campaign_id, self::OVERRIDES_META_KEY, true );
		if ( ! is_array( $overrides ) ) {
			return array();
		}
		$cause_goals = $overrides[ self::OVERRIDES_NAMESPACE ] ?? array();
		return is_array( $cause_goals ) ? $cause_goals : array();
	}

	/**
	 * Resolve a single cause's goal config with defaults applied.
	 * Returns the canonical shape every time:
	 *
	 *   array(
	 *       'enabled'      => bool,
	 *       'goal_amount'  => float,
	 *       'closure_mode' => string  // resolved (no `inherit` sentinel)
	 *   )
	 *
	 * `closure_mode` is always a concrete mode — `inherit` is replaced
	 * by the global `default_cause_closure_mode`. Callers (Submit_Guard,
	 * closure renderer) get a read-once-and-act value.
	 *
	 * @return array{enabled:bool,goal_amount:float,closure_mode:string}
	 */
	public static function for_cause( int $campaign_id, string $cause_id ): array {
		$all  = self::for_campaign( $campaign_id );
		$row  = isset( $all[ $cause_id ] ) && is_array( $all[ $cause_id ] ) ? $all[ $cause_id ] : array();
		$mode = isset( $row['closure_mode'] ) ? (string) $row['closure_mode'] : self::CLOSURE_MODE_INHERIT;

		if ( self::CLOSURE_MODE_INHERIT === $mode || ! in_array( $mode, self::closure_modes(), true ) ) {
			$mode = self::resolve_global_default_mode();
		}

		return array(
			'enabled'      => ! empty( $row['enabled'] ),
			'goal_amount'  => isset( $row['goal_amount'] ) ? max( 0.0, (float) $row['goal_amount'] ) : 0.0,
			'closure_mode' => $mode,
		);
	}

	/**
	 * Persist the per-cause goals map for a campaign. Pass null or
	 * empty array to clear the entire `cause_goals` namespace; campaign
	 * reverts to "no per-cause goals configured" (every cause is open).
	 *
	 * Merges into the existing overrides structure so other namespaces
	 * (intervals, display, crypto, etc.) aren't stomped.
	 *
	 * @param array<string,array<string,mixed>>|null $cause_goals
	 */
	public static function set( int $campaign_id, ?array $cause_goals ): bool {
		$overrides = get_post_meta( $campaign_id, self::OVERRIDES_META_KEY, true );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}

		if ( null === $cause_goals || empty( $cause_goals ) ) {
			unset( $overrides[ self::OVERRIDES_NAMESPACE ] );
		} else {
			$overrides[ self::OVERRIDES_NAMESPACE ] = self::sanitize( $cause_goals );
		}

		if ( empty( $overrides ) ) {
			return delete_post_meta( $campaign_id, self::OVERRIDES_META_KEY );
		}
		return false !== update_post_meta( $campaign_id, self::OVERRIDES_META_KEY, $overrides );
	}

	/**
	 * Allow-list sanitizer for the per-cause goals map.
	 *
	 * - Drops entries with empty / non-string cause ids.
	 * - Coerces enabled to bool.
	 * - Clamps goal_amount to >= 0.
	 * - Restricts closure_mode to known modes (invalid → inherit).
	 * - Drops causes whose only payload is all-defaults (enabled=false,
	 *   goal=0, mode=inherit) — saves storage.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,array<string,mixed>>
	 */
	public static function sanitize( array $raw ): array {
		$out = array();
		foreach ( $raw as $cause_id => $row ) {
			$cause_id = (string) $cause_id;
			if ( '' === $cause_id || ! is_array( $row ) ) {
				continue;
			}

			$enabled     = ! empty( $row['enabled'] );
			$goal_amount = isset( $row['goal_amount'] ) ? max( 0.0, (float) $row['goal_amount'] ) : 0.0;

			$mode = isset( $row['closure_mode'] ) ? (string) $row['closure_mode'] : self::CLOSURE_MODE_INHERIT;
			if ( ! in_array( $mode, self::closure_modes(), true ) ) {
				$mode = self::CLOSURE_MODE_INHERIT;
			}

			// All-defaults entries don't need persisting; default-on-read
			// produces the same result.
			if ( ! $enabled && 0.0 === $goal_amount && self::CLOSURE_MODE_INHERIT === $mode ) {
				continue;
			}

			$out[ $cause_id ] = array(
				'enabled'      => $enabled,
				'goal_amount'  => $goal_amount,
				'closure_mode' => $mode,
			);
		}
		return $out;
	}

	/**
	 * Resolve the global-default closure mode. Falls back to
	 * `redirect_to_cause` when the option is missing, malformed, or
	 * accidentally set to `inherit` (which would be a config error —
	 * the global has no "parent" to inherit from).
	 */
	public static function resolve_global_default_mode(): string {
		$global = Config_Resolver::get_global_settings();
		$mode   = isset( $global['default_cause_closure_mode'] )
			? (string) $global['default_cause_closure_mode']
			: self::DEFAULT_CLOSURE_MODE;
		return in_array( $mode, self::global_closure_modes(), true ) ? $mode : self::DEFAULT_CLOSURE_MODE;
	}

	/**
	 * True when the global master toggle is on. Most read sites short-
	 * circuit on this — when off, cause goals don't exist as far as the
	 * donor flow is concerned.
	 */
	public static function feature_enabled(): bool {
		$global = Config_Resolver::get_global_settings();
		return ! empty( $global['enable_cause_goals'] );
	}
}
