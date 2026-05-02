<?php
/**
 * TGB_Project_Mapper — resolve campaign → TGB project routing.
 *
 * Single source of truth for the per-campaign override pattern. Both
 * the donor-side renderer (Crypto_Donation_Renderer) and the webhook
 * handler (TGB_Webhook_Handler reverse-lookup) consult this class so
 * the resolution semantics stay consistent across read sites.
 *
 * Resolution order (per-campaign wins):
 *
 *   1. `_dfwc_companion_overrides.crypto.tgb_project_id` on the campaign post
 *   2. `tgb_default_project_id` from the global settings option
 *   3. '' (empty string) — donor-side renders without project routing,
 *      relies on TGB to bucket against org's default sub-project
 *
 * Storage: per-campaign settings live under the layered overrides
 * structure (`_dfwc_companion_overrides.crypto`) so they flow through
 * the existing Config_Resolver pattern. Same pattern v1.2.0 goal-aware
 * fields and v1.3.0 stock fields use.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class TGB_Project_Mapper {

	public const OVERRIDES_META_KEY = '_dfwc_companion_overrides';
	public const OVERRIDES_NAMESPACE = 'crypto';

	/**
	 * Resolve the TGB project_id for a campaign. Per-campaign override
	 * wins; falls back to the org-wide default; finally to ''.
	 */
	public static function resolve( int $campaign_id ): string {
		$campaign_setting = self::for_campaign( $campaign_id );
		if ( ! empty( $campaign_setting['tgb_project_id'] ) ) {
			return (string) $campaign_setting['tgb_project_id'];
		}

		$global = Config_Resolver::get_global_settings();
		return (string) ( $global['tgb_default_project_id'] ?? '' );
	}

	/**
	 * Per-campaign opt-in flag. Default true when global crypto is on —
	 * matches the v2.2.0 augment-all-campaigns convention. Per-campaign
	 * meta-box can flip individual campaigns to false.
	 */
	public static function is_enabled_for_campaign( int $campaign_id ): bool {
		$campaign_setting = self::for_campaign( $campaign_id );

		// Explicit `enabled => false` opts out; missing key OR
		// `enabled => true` keep the default-on behavior.
		if ( array_key_exists( 'enabled', $campaign_setting ) ) {
			return (bool) $campaign_setting['enabled'];
		}
		return true;
	}

	/**
	 * Read the full crypto override array for a campaign. Returns an
	 * empty array when no overrides exist.
	 *
	 * @return array<string,mixed>
	 */
	public static function for_campaign( int $campaign_id ): array {
		$overrides = get_post_meta( $campaign_id, self::OVERRIDES_META_KEY, true );
		if ( ! is_array( $overrides ) ) {
			return array();
		}
		$crypto = $overrides[ self::OVERRIDES_NAMESPACE ] ?? array();
		return is_array( $crypto ) ? $crypto : array();
	}

	/**
	 * Reverse-lookup: TGB project_id → companion campaign_id. Returns 0
	 * when no campaign has the project_id stored as a per-campaign
	 * override. Falls back to "first published wc-donation" when the
	 * project_id matches the org-wide default — heuristic for "donor
	 * came in via the org default sub-project without a specific
	 * campaign attribution."
	 *
	 * Used by the webhook handler when TGB sends a confirmation but
	 * companion has no pending order yet (race case).
	 */
	public static function find_campaign_by_project_id( string $project_id ): int {
		if ( '' === $project_id ) {
			return 0;
		}

		// LIKE scan — `_dfwc_companion_overrides` is a serialized array,
		// so we substring-match the project_id then verify by re-parsing.
		$query = new \WP_Query(
			array(
				'post_type'              => 'wc-donation',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 50,
				'meta_query'             => array(
					array(
						'key'     => self::OVERRIDES_META_KEY,
						'value'   => $project_id,
						'compare' => 'LIKE',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$resolved = self::resolve( (int) $post_id );
			if ( $resolved === $project_id ) {
				return (int) $post_id;
			}
		}

		// Default-project-id fallback: first published wc-donation.
		$global = Config_Resolver::get_global_settings();
		if ( (string) ( $global['tgb_default_project_id'] ?? '' ) === $project_id ) {
			$fallback = new \WP_Query(
				array(
					'post_type'              => 'wc-donation',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( ! empty( $fallback->posts ) ) {
				return (int) $fallback->posts[0];
			}
		}

		return 0;
	}

	/**
	 * Persist crypto-specific overrides for a campaign. Pass null or
	 * empty array to clear (campaign reverts to inheriting global
	 * settings). Merges with the existing overrides structure so other
	 * namespaces (display, intervals, goal_aware, stock, etc.) aren't
	 * stomped.
	 *
	 * @param array<string,mixed>|null $crypto_settings
	 */
	public static function set( int $campaign_id, ?array $crypto_settings ): bool {
		$overrides = get_post_meta( $campaign_id, self::OVERRIDES_META_KEY, true );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}

		if ( null === $crypto_settings || empty( $crypto_settings ) ) {
			unset( $overrides[ self::OVERRIDES_NAMESPACE ] );
		} else {
			$overrides[ self::OVERRIDES_NAMESPACE ] = self::sanitize( $crypto_settings );
		}

		if ( empty( $overrides ) ) {
			return delete_post_meta( $campaign_id, self::OVERRIDES_META_KEY );
		}
		return false !== update_post_meta( $campaign_id, self::OVERRIDES_META_KEY, $overrides );
	}

	/**
	 * Allow-list sanitizer for the crypto overrides shape. Drops unknown
	 * keys; coerces booleans; trims project_id.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		$out = array();

		if ( array_key_exists( 'enabled', $raw ) ) {
			$out['enabled'] = (bool) $raw['enabled'];
		}

		if ( isset( $raw['tgb_project_id'] ) ) {
			$project_id = sanitize_text_field( (string) $raw['tgb_project_id'] );
			if ( '' !== $project_id ) {
				$out['tgb_project_id'] = $project_id;
			}
		}

		return $out;
	}
}
