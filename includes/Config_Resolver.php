<?php
/**
 * Single source of truth for per-campaign interval config.
 *
 * Reads `_dfwc_companion_intervals` post meta. When the meta is absent
 * (campaign was created before the companion was installed, or admin hasn't
 * saved the meta box yet), infers a sensible default from the parent plugin's
 * existing meta (`pred-amount`, `wc-donation-recurring`, `_subscription_period`,
 * `free-min-amount`, `free-max-amount`). The inferred config is in-memory only —
 * it is NOT written back to the DB; the admin materializes it by saving the
 * meta box once.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion;

defined( 'ABSPATH' ) || exit;

final class Config_Resolver {

	public const META_KEY_INTERVALS = '_dfwc_companion_intervals';
	public const META_KEY_FORM_MODE = '_dfwc_companion_form_mode';

	public const FORM_MODE_REPLACE        = 'replace';
	public const FORM_MODE_SHORTCODE_ONLY = 'shortcode_only';

	public const INTERVAL_ONE_TIME = 'one_time';
	public const INTERVAL_MONTHLY  = 'monthly';
	public const INTERVAL_ANNUAL   = 'annual';

	/**
	 * Allow-listed interval keys, in display order.
	 */
	public static function intervals(): array {
		return [ self::INTERVAL_ONE_TIME, self::INTERVAL_MONTHLY, self::INTERVAL_ANNUAL ];
	}

	/**
	 * Returns the full per-campaign config, with all fields populated from
	 * defaults / inference where missing.
	 */
	public static function resolve( int $campaign_id ): array {
		$stored = get_post_meta( $campaign_id, self::META_KEY_INTERVALS, true );

		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return self::merge_with_defaults( $stored );
		}

		return self::infer_from_parent( $campaign_id );
	}

	public static function form_mode( int $campaign_id ): string {
		$stored = get_post_meta( $campaign_id, self::META_KEY_FORM_MODE, true );
		// Default to 'replace' when meta is unset (changed in 0.2.0).
		// Admins who explicitly want shortcode_only can pick it in the side
		// meta box; that choice is persisted and respected.
		if ( self::FORM_MODE_SHORTCODE_ONLY === $stored ) {
			return self::FORM_MODE_SHORTCODE_ONLY;
		}
		return self::FORM_MODE_REPLACE;
	}

	/**
	 * Canonical config skeleton. One-time enabled by default; recurring
	 * intervals disabled (admin opts in via the meta box).
	 */
	public static function defaults(): array {
		$preset_skeleton = [
			'enabled'       => false,
			'presets'       => [
				[ 'amount' => 25.0, 'label' => '' ],
				[ 'amount' => 50.0, 'label' => '' ],
				[ 'amount' => 100.0, 'label' => '' ],
			],
			'min'           => 5.0,
			'max'           => 10000.0,
			'default_index' => 1,
			'cta_template'  => '',
		];

		return [
			self::INTERVAL_ONE_TIME => array_merge(
				$preset_skeleton,
				[ 'enabled' => true, 'cta_template' => __( 'Donate {amount}', 'dfwc-companion' ) ]
			),
			self::INTERVAL_MONTHLY  => array_merge(
				$preset_skeleton,
				[ 'cta_template' => __( 'Donate {amount}/month', 'dfwc-companion' ) ]
			),
			self::INTERVAL_ANNUAL   => array_merge(
				$preset_skeleton,
				[ 'cta_template' => __( 'Donate {amount}/year', 'dfwc-companion' ) ]
			),
		];
	}

	/**
	 * Defensive merge: stored config may be missing fields (older saved version,
	 * partial corruption). Fill blanks from defaults; coerce types.
	 */
	private static function merge_with_defaults( array $stored ): array {
		$defaults = self::defaults();
		$merged   = [];

		foreach ( self::intervals() as $key ) {
			$block            = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : [];
			$default_block    = $defaults[ $key ];
			$merged[ $key ]   = [
				'enabled'       => isset( $block['enabled'] ) ? (bool) $block['enabled'] : $default_block['enabled'],
				'presets'       => self::coerce_presets( $block['presets'] ?? $default_block['presets'] ),
				'min'           => isset( $block['min'] ) ? (float) $block['min'] : $default_block['min'],
				'max'           => isset( $block['max'] ) ? (float) $block['max'] : $default_block['max'],
				'default_index' => isset( $block['default_index'] ) ? (int) $block['default_index'] : $default_block['default_index'],
				'cta_template'  => isset( $block['cta_template'] ) && is_string( $block['cta_template'] ) && '' !== $block['cta_template']
					? $block['cta_template']
					: $default_block['cta_template'],
			];

			// Clamp default_index to the valid range so the UI always has a selectable default.
			$preset_count                          = count( $merged[ $key ]['presets'] );
			$merged[ $key ]['default_index']       = $preset_count > 0
				? max( 0, min( $merged[ $key ]['default_index'], $preset_count - 1 ) )
				: 0;
		}

		return $merged;
	}

	/**
	 * Build a synthetic config from parent's existing campaign meta. Used the
	 * first time a campaign is read by the companion. Never persisted.
	 */
	private static function infer_from_parent( int $campaign_id ): array {
		$config = self::defaults();

		// Inherit preset amounts from parent's pred-amount field if present.
		$parent_presets = get_post_meta( $campaign_id, 'pred-amount', true );
		if ( is_array( $parent_presets ) && ! empty( $parent_presets ) ) {
			$normalized = [];
			foreach ( array_slice( $parent_presets, 0, 6 ) as $amount ) {
				$amount_f = (float) $amount;
				if ( $amount_f > 0 ) {
					$normalized[] = [ 'amount' => $amount_f, 'label' => '' ];
				}
			}
			if ( ! empty( $normalized ) ) {
				foreach ( self::intervals() as $key ) {
					$config[ $key ]['presets']       = $normalized;
					$config[ $key ]['default_index'] = (int) min( 1, count( $normalized ) - 1 );
				}
			}
		}

		// Inherit min/max from parent's free-min-amount / free-max-amount if present.
		$min = (float) get_post_meta( $campaign_id, 'free-min-amount', true );
		$max = (float) get_post_meta( $campaign_id, 'free-max-amount', true );
		if ( $min > 0 ) {
			foreach ( self::intervals() as $key ) {
				$config[ $key ]['min'] = $min;
			}
		}
		if ( $max > 0 && $max >= $min ) {
			foreach ( self::intervals() as $key ) {
				$config[ $key ]['max'] = $max;
			}
		}

		// Recurring inference: respect parent's wc-donation-recurring + period meta.
		$recurring = (string) get_post_meta( $campaign_id, 'wc-donation-recurring', true );
		if ( in_array( $recurring, [ 'user', 'enabled' ], true ) ) {
			$period = (string) get_post_meta( $campaign_id, '_subscription_period', true );
			if ( 'month' === $period ) {
				$config[ self::INTERVAL_MONTHLY ]['enabled'] = true;
			} elseif ( 'year' === $period ) {
				$config[ self::INTERVAL_ANNUAL ]['enabled'] = true;
			} elseif ( in_array( $period, [ 'day', 'week' ], true ) ) {
				// Sub-monthly cadences map conservatively to monthly.
				$config[ self::INTERVAL_MONTHLY ]['enabled'] = true;
			}
		}

		return $config;
	}

	/**
	 * Coerce a presets array into the canonical [{amount,label},…] shape.
	 * Drops rows with non-positive amount; reindexes so keys are sequential.
	 */
	private static function coerce_presets( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
			if ( $amount <= 0 ) {
				continue;
			}
			$label = isset( $row['label'] ) && is_string( $row['label'] ) ? $row['label'] : '';
			$out[] = [ 'amount' => $amount, 'label' => $label ];
		}

		return array_values( $out );
	}
}
