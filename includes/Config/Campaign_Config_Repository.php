<?php
/**
 * Campaign_Config_Repository — read/write per-campaign companion meta.
 *
 * Wraps the post-meta API for the four meta keys introduced in v0.7.0:
 *   _dfwc_companion_template_id  — string; assigned template, '' = none
 *   _dfwc_companion_overrides    — partial config; campaign-level overrides
 *   _dfwc_companion_detached     — bool; frozen-from-template state
 *   _dfwc_companion_intervals    — legacy v0.6.x; read by resolver, removed on save
 *   _dfwc_companion_display      — legacy v0.6.x; same
 *
 * The `_dfwc_companion_template_id` and `_dfwc_companion_overrides` keys
 * are also declared in wpml-config.xml as `action="copy"` so WPML treats
 * them as language-agnostic structural data.
 *
 * Detach semantics: when an admin clicks "Detach" on a campaign, we
 * snapshot the currently resolved config into overrides and clear the
 * template_id. Subsequent template changes don't affect this campaign
 * until the admin re-applies a template.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Campaign_Config_Repository {

	public function get_template_id( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, Config_Resolver::META_KEY_TEMPLATE, true );
	}

	public function set_template_id( int $campaign_id, string $template_id ): void {
		if ( '' === $template_id ) {
			delete_post_meta( $campaign_id, Config_Resolver::META_KEY_TEMPLATE );
		} else {
			update_post_meta( $campaign_id, Config_Resolver::META_KEY_TEMPLATE, $template_id );
		}
		// Setting a template clears detached state.
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_DETACHED );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_overrides( int $campaign_id ): array {
		$stored = get_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	public function set_overrides( int $campaign_id, array $overrides ): void {
		if ( empty( $overrides ) ) {
			delete_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES );
		} else {
			update_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES, $overrides );
		}
	}

	public function is_detached( int $campaign_id ): bool {
		return (bool) get_post_meta( $campaign_id, Config_Resolver::META_KEY_DETACHED, true );
	}

	/**
	 * Detach a campaign from its template.
	 *
	 * Snapshots the currently resolved config into overrides, clears the
	 * template_id, and sets the detached flag. Future template changes
	 * won't affect this campaign until the admin re-applies a template.
	 */
	public function detach_from_template( int $campaign_id ): void {
		$resolved = Config_Resolver::resolve( $campaign_id );
		$this->set_overrides( $campaign_id, $resolved );
		// `set_template_id('')` clears the detached flag, so set it AFTER.
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_TEMPLATE );
		update_post_meta( $campaign_id, Config_Resolver::META_KEY_DETACHED, true );
	}

	/**
	 * Reset a campaign to inherit cleanly from its current template.
	 * Clears the overrides + detached flag. Template assignment preserved.
	 */
	public function reset_to_template( int $campaign_id ): void {
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_DETACHED );
	}

	/**
	 * Reset a campaign to plugin defaults.
	 *
	 * Removes template assignment, overrides, detached flag, AND the legacy
	 * v0.6.x intervals/display meta. After this call, the campaign reads
	 * back as fully default until the admin saves something.
	 */
	public function reset_to_defaults( int $campaign_id ): void {
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_TEMPLATE );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_DETACHED );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_INTERVALS );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_DISPLAY );
	}

	/**
	 * Apply a template to a campaign. Clears any prior overrides.
	 *
	 * Returns false when the requested template doesn't exist.
	 */
	public function apply_template( int $campaign_id, string $template_id ): bool {
		$repo = new Template_Repository();
		if ( '' === $template_id || ! $repo->exists( $template_id ) ) {
			return false;
		}
		$this->set_template_id( $campaign_id, $template_id );
		$this->set_overrides( $campaign_id, array() );

		/**
		 * Fires after a template is applied to one or more campaigns.
		 * Bulk_Actions fires this once with the array of IDs; individual
		 * applies fire with a single-element array.
		 *
		 * @param string         $template_id
		 * @param array<int,int> $campaign_ids
		 */
		do_action( 'dfwc_companion_template_applied', $template_id, array( $campaign_id ) );
		return true;
	}

	/**
	 * Compute the override delta between a baseline (defaults → global → template,
	 * no campaign overrides) and the form-submitted config.
	 *
	 * Only fields that differ from the baseline land in the returned array; equal
	 * fields stay out so inheritance handles them. Critical for honest layering:
	 * if admin assigns a template and saves the campaign without changes, the
	 * delta is empty and template changes propagate normally.
	 *
	 * Sequential arrays (like presets) are compared as a whole — if any element
	 * differs, the whole array goes into the delta because the resolver's
	 * deep-merge replaces sequential arrays wholesale rather than concatenating.
	 *
	 * @param array<string,mixed> $baseline
	 * @param array<string,mixed> $submitted
	 * @return array<string,mixed>
	 */
	public function compute_override_delta( array $baseline, array $submitted ): array {
		$delta = array();
		foreach ( $submitted as $key => $value ) {
			$base_value = $baseline[ $key ] ?? null;

			if ( is_array( $value ) && is_array( $base_value ) ) {
				if ( $this->is_associative( $value ) && $this->is_associative( $base_value ) ) {
					// Recurse into associative arrays (e.g. interval blocks, display).
					$nested = $this->compute_override_delta( $base_value, $value );
					if ( ! empty( $nested ) ) {
						$delta[ $key ] = $nested;
					}
				} elseif ( $value !== $base_value ) {
					// Sequential array — replace wholesale if any element differs.
					$delta[ $key ] = $value;
				}
				continue;
			}

			// Scalar comparison.
			if ( $value !== $base_value ) {
				$delta[ $key ] = $value;
			}
		}
		return $delta;
	}

	/**
	 * @param array<int|string,mixed> $arr
	 */
	private function is_associative( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Compute baseline config for a campaign — defaults merged with global +
	 * template, but WITHOUT campaign overrides. Used by the override-delta
	 * computation to figure out which form fields are real overrides vs
	 * inherited values the admin just re-saved.
	 *
	 * @return array<string,mixed>
	 */
	public function compute_baseline( int $campaign_id ): array {
		// Temporarily clear overrides for this campaign while we read the
		// baseline. Save the existing value first; restore at end. This is
		// race-free under WP's single-request-per-save model.
		$existing_overrides = get_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES, true );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES );

		$baseline = Config_Resolver::resolve( $campaign_id );

		if ( is_array( $existing_overrides ) && ! empty( $existing_overrides ) ) {
			update_post_meta( $campaign_id, Config_Resolver::META_KEY_OVERRIDES, $existing_overrides );
		}

		return $baseline;
	}

	/**
	 * One-time migration on first save in v0.7.0+. If the campaign has the
	 * legacy `_dfwc_companion_intervals` meta but no overrides yet, copy the
	 * legacy meta into overrides and delete the legacy keys. Idempotent
	 * (no-op when overrides already exist OR legacy keys are absent).
	 *
	 * Called from `Meta_Box::save()` so the migration happens on the next
	 * admin save of any v0.6.x campaign — without forcing a global migration
	 * pass that could be slow on large sites.
	 */
	public function migrate_legacy_to_overrides_if_needed( int $campaign_id ): void {
		// Skip if v0.7.0 keys are already populated.
		if ( ! empty( $this->get_overrides( $campaign_id ) ) ) {
			return;
		}
		if ( '' !== $this->get_template_id( $campaign_id ) ) {
			return;
		}

		$legacy_intervals = get_post_meta( $campaign_id, Config_Resolver::META_KEY_INTERVALS, true );
		$legacy_display   = get_post_meta( $campaign_id, Config_Resolver::META_KEY_DISPLAY, true );

		if ( ! is_array( $legacy_intervals ) || empty( $legacy_intervals ) ) {
			return;
		}

		$migrated = $legacy_intervals;
		if ( is_array( $legacy_display ) && ! empty( $legacy_display ) ) {
			$migrated['display'] = $legacy_display;
		}

		$this->set_overrides( $campaign_id, $migrated );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_INTERVALS );
		delete_post_meta( $campaign_id, Config_Resolver::META_KEY_DISPLAY );

		/**
		 * Fires after a campaign's legacy v0.6.x meta is migrated to v0.7.0
		 * overrides. Useful for migration audits.
		 *
		 * @param int $campaign_id
		 */
		do_action( 'dfwc_companion_campaign_migrated_to_overrides', $campaign_id );
	}
}
