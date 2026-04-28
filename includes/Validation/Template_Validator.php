<?php
/**
 * Template_Validator — sanitize a full campaign / template config blob.
 *
 * Used by:
 * - REST\Preview_REST_Controller — sanitizes the unsaved config the admin
 *   POSTs from the preview pane before rendering.
 * - Future v0.10+ deduplication pass: Templates_Page and Meta_Box can
 *   delegate to this class instead of maintaining parallel sanitizers.
 *   For now, both keep their existing sanitizers; this class lives
 *   alongside without claiming exclusive ownership.
 *
 * Validation rules (mirror the existing sanitizers so behavior stays consistent):
 * - Interval keys allow-listed against `Config_Resolver::intervals()`
 * - default_interval allow-listed
 * - Display mode allow-listed via `Defaults::impact_display_modes()`
 * - Booleans coerced via `! empty()` (HTML form-checkbox semantics)
 * - Amounts via `wc_format_decimal` + `floatval`; preset rows with
 *   non-positive amounts dropped
 * - Single-featured-per-interval enforcement (last-write-wins)
 * - All translatable strings via `sanitize_text_field`
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Validation;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Defaults;

final class Template_Validator {

	/**
	 * Sanitize a full config payload (the shape `Config_Resolver::resolve()`
	 * returns). Returns a sanitized array safe for storage or rendering.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public function sanitize( array $raw ): array {
		$config = Defaults::for_campaign();

		// default_interval (allow-listed).
		if ( isset( $raw['default_interval'] ) ) {
			$value = sanitize_key( (string) $raw['default_interval'] );
			if ( in_array( $value, Config_Resolver::intervals(), true ) ) {
				$config['default_interval'] = $value;
			}
		}

		// Per-interval blocks.
		foreach ( Config_Resolver::intervals() as $interval ) {
			$block_raw           = is_array( $raw[ $interval ] ?? null ) ? $raw[ $interval ] : array();
			$config[ $interval ] = $this->sanitize_interval_block( $block_raw, $config[ $interval ] );
		}

		// Display sub-tree.
		$display_raw       = is_array( $raw['display'] ?? null ) ? $raw['display'] : array();
		$config['display'] = $this->sanitize_display( $display_raw );

		return $config;
	}

	/**
	 * Sanitize one interval block. Public so tests / Renderer can use it
	 * directly without reaching through `sanitize()`.
	 *
	 * @param array<string,mixed> $raw
	 * @param array<string,mixed> $defaults Per-interval defaults to fill missing fields.
	 * @return array<string,mixed>
	 */
	public function sanitize_interval_block( array $raw, array $defaults ): array {
		$enabled               = ! empty( $raw['enabled'] );
		$custom_amount_enabled = ! empty( $raw['custom_amount_enabled'] );

		$presets = $this->sanitize_presets( is_array( $raw['presets'] ?? null ) ? $raw['presets'] : array() );

		$min = isset( $raw['min'] ) ? $this->parse_decimal( $raw['min'] ) : (float) $defaults['min'];
		$max = isset( $raw['max'] ) ? $this->parse_decimal( $raw['max'] ) : (float) $defaults['max'];
		if ( $min < 0.01 ) {
			$min = 0.01;
		}
		if ( $max < $min ) {
			$max = $min;
		}

		$default_index = isset( $raw['default_index'] ) ? (int) $raw['default_index'] : 0;
		$preset_count  = count( $presets );
		$default_index = $preset_count > 0 ? max( 0, min( $default_index, $preset_count - 1 ) ) : 0;

		$cta = isset( $raw['cta_template'] ) ? sanitize_text_field( (string) $raw['cta_template'] ) : (string) ( $defaults['cta_template'] ?? '' );

		$subtitle                   = isset( $raw['subtitle'] ) ? sanitize_text_field( (string) $raw['subtitle'] ) : '';
		$annual_equivalency         = isset( $raw['annual_equivalency'] ) ? sanitize_text_field( (string) $raw['annual_equivalency'] ) : '';
		$custom_amount_impact_label = isset( $raw['custom_amount_impact_label'] ) ? sanitize_text_field( (string) $raw['custom_amount_impact_label'] ) : '';

		$impact_mode = isset( $raw['impact_display_mode'] ) ? sanitize_key( (string) $raw['impact_display_mode'] ) : 'below_button';
		if ( ! in_array( $impact_mode, Defaults::impact_display_modes(), true ) ) {
			$impact_mode = 'below_button';
		}

		$currency_overrides = Currency_Preset_Resolver::sanitize_currency_overrides(
			$raw['currency_overrides'] ?? null,
			count( $presets )
		);

		return array(
			'enabled'                    => $enabled,
			'presets'                    => $presets,
			'min'                        => $min,
			'max'                        => $max,
			'default_index'              => $default_index,
			'cta_template'               => $cta,
			'custom_amount_enabled'      => $custom_amount_enabled,
			'subtitle'                   => $subtitle,
			'annual_equivalency'         => $annual_equivalency,
			'impact_display_mode'        => $impact_mode,
			'custom_amount_impact_label' => $custom_amount_impact_label,
			'currency_overrides'         => $currency_overrides,
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{show_title:bool,show_image:bool,cause_heading:string}
	 */
	public function sanitize_display( array $raw ): array {
		return array(
			'show_title'    => ! empty( $raw['show_title'] ),
			'show_image'    => ! empty( $raw['show_image'] ),
			'cause_heading' => isset( $raw['cause_heading'] )
				? sanitize_text_field( (string) $raw['cause_heading'] )
				: '',
		);
	}

	/**
	 * Sanitize the presets array. Drops rows with non-positive amount.
	 * Enforces single-featured-per-interval (last-write-wins on duplicates).
	 *
	 * @param array<int|string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_presets( array $raw ): array {
		$presets = array();
		$idx_seq = 0;
		foreach ( $raw as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$amount = $this->parse_decimal( $row['amount'] ?? '' );
			if ( $amount <= 0 ) {
				continue;
			}
			$presets[] = array(
				'amount'       => $amount,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'impact_label' => isset( $row['impact_label'] ) ? sanitize_text_field( (string) $row['impact_label'] ) : '',
				'is_featured'  => ! empty( $row['is_featured'] ),
				'sort_order'   => isset( $row['sort_order'] ) ? (int) $row['sort_order'] : ( ( $idx_seq + 1 ) * 10 ),
			);
			++$idx_seq;
		}

		// Single-featured-per-interval enforcement.
		$found_featured = false;
		foreach ( $presets as $i => $preset ) {
			if ( $preset['is_featured'] && $found_featured ) {
				$presets[ $i ]['is_featured'] = false;
			} elseif ( $preset['is_featured'] ) {
				$found_featured = true;
			}
		}

		return $presets;
	}

	/**
	 * Parse a numeric value via wc_format_decimal when available; falls back
	 * to floatval otherwise (test bootstrap stub returns the same shape).
	 *
	 * @param mixed $value
	 */
	private function parse_decimal( $value ): float {
		if ( function_exists( 'wc_format_decimal' ) ) {
			$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
			return (float) wc_format_decimal( (string) $value, (int) $decimals );
		}
		return (float) $value;
	}
}
