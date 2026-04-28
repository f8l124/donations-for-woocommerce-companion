<?php
/**
 * Defaults — canonical default config used at every layer of the resolver.
 *
 * Static factory; no state. Returned arrays are deep-copied by callers
 * before mutation (PHP arrays are copy-on-write so this is essentially
 * free).
 *
 * Three forms:
 * - `for_campaign()`    — base shape for any campaign's resolved config
 * - `for_global()`      — adds plugin-wide options to the campaign shape
 * - `interval_block()`  — single interval block (presets, min/max, etc.)
 *
 * Schema fields documented in plans/v2/04-phase-3-templates-and-defaults.md §4.2.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Defaults {

	/**
	 * Default config skeleton for a single campaign.
	 *
	 * @return array<string,mixed>
	 */
	public static function for_campaign(): array {
		return array(
			'default_interval' => 'one_time',
			'one_time'         => self::interval_block( true, __( 'Donate {amount}', 'dfwc-companion' ) ),
			'monthly'          => self::interval_block( false, __( 'Donate {amount}/month', 'dfwc-companion' ) ),
			'annual'           => self::interval_block( false, __( 'Donate {amount}/year', 'dfwc-companion' ) ),
			'display'          => array(
				'show_title'    => true,
				'show_image'    => true,
				'cause_heading' => '',
			),
		);
	}

	/**
	 * Default config skeleton for the global-settings layer. Includes
	 * plugin-wide options on top of the per-campaign baseline.
	 *
	 * @return array<string,mixed>
	 */
	public static function for_global(): array {
		return array_merge(
			self::for_campaign(),
			array(
				'version'                    => 1,
				'default_template_id'        => '',
				'enable_advanced_intervals'  => false,
				'preserve_data_on_uninstall' => true,
			)
		);
	}

	/**
	 * Single interval block. Used by `for_campaign()` to populate
	 * one_time/monthly/annual, and directly by tests / template forms when
	 * they need a fresh skeleton.
	 *
	 * Phase 5 schema fields (impact_label, is_featured, sort_order) are
	 * present from Phase 3 forward — only the renderer/admin-UI
	 * consumption is gated by phase, not the storage shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function interval_block( bool $enabled = false, string $cta_template = '' ): array {
		return array(
			'enabled'               => $enabled,
			'presets'               => array(
				array(
					'amount'       => 25.0,
					'label'        => '',
					'impact_label' => '',
					'is_featured'  => false,
					'sort_order'   => 10,
				),
				array(
					'amount'       => 50.0,
					'label'        => '',
					'impact_label' => '',
					'is_featured'  => false,
					'sort_order'   => 20,
				),
				array(
					'amount'       => 100.0,
					'label'        => '',
					'impact_label' => '',
					'is_featured'  => false,
					'sort_order'   => 30,
				),
			),
			'min'                       => 5.0,
			'max'                       => 10000.0,
			'default_index'             => 1,
			'cta_template'              => $cta_template,
			'custom_amount_enabled'     => true,
			// Phase 5 — donor impact messaging.
			'subtitle'                  => '',
			'annual_equivalency'        => '',
			'impact_display_mode'       => 'below_button',
			// Custom-amount impact text shown alongside the donor's custom-
			// amount input (parallel to per-preset impact_label). Free-form;
			// admin can use it for "Every gift makes a difference" or
			// "Each $1 provides one meal" style messaging.
			'custom_amount_impact_label' => '',
			// Phase 6 — per-currency overrides. Sparse map keyed by ISO code.
			// Empty by default; populated when admin defines per-currency
			// preset amounts (e.g., 'GBP' => ['presets' => [...], 'min' => x]).
			// Override blocks only carry fields that differ from the base
			// block; missing fields fall through at render time via
			// Currency_Preset_Resolver::resolve().
			'currency_overrides'         => array(),
		);
	}

	/**
	 * Allow-listed impact-display modes. Used by sanitizers + admin UI.
	 *
	 * @return array<int,string>
	 */
	public static function impact_display_modes(): array {
		return array( 'inline', 'below_button', 'tooltip', 'card' );
	}

	/**
	 * Display-options defaults — useful when callers need just the display
	 * sub-tree (e.g., Config_Resolver::display_defaults backward-compat).
	 *
	 * @return array{show_title:bool,show_image:bool,cause_heading:string}
	 */
	public static function display(): array {
		$campaign = self::for_campaign();
		return $campaign['display'];
	}

	/**
	 * Allow-listed interval keys, in display order. Used by resolver, save
	 * handlers, admin UI, and renderer.
	 *
	 * @return array<int,string>
	 */
	public static function intervals(): array {
		return array( 'one_time', 'monthly', 'annual' );
	}
}
