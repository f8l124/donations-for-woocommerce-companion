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
			'one_time'         => self::interval_block( true, __( 'Donate {amount}', 'dfwc-companion' ), 'one_time' ),
			'monthly'          => self::interval_block( false, __( 'Donate {amount}/month', 'dfwc-companion' ), 'monthly' ),
			'annual'           => self::interval_block( false, __( 'Donate {amount}/year', 'dfwc-companion' ), 'annual' ),
			// Phase 7 — advanced intervals shipped with default-disabled blocks
			// so storage shape stays stable whether or not the global toggle is on.
			'weekly'           => self::interval_block( false, __( 'Donate {amount}/week', 'dfwc-companion' ), 'weekly' ),
			'quarterly'        => self::interval_block( false, __( 'Donate {amount} every 3 months', 'dfwc-companion' ), 'quarterly' ),
			'semiannual'       => self::interval_block( false, __( 'Donate {amount} every 6 months', 'dfwc-companion' ), 'semiannual' ),
			'custom'           => self::interval_block( false, __( 'Donate {amount} {custom_label}', 'dfwc-companion' ), 'custom' ),
			'display'          => array(
				'show_title'    => true,
				// v2.2.1 (Phase 18): default to OFF. The companion's overlay is the
				// donor's primary visual focus; parent's campaign-image hero block above
				// it doubles up the visual weight on most themes. Admins who want the
				// image visible flip it back on per-campaign via the meta box's Display
				// Options, or globally via a template that sets show_image=true.
				// Existing campaigns with stored display overrides keep their saved
				// value (per the layered config; overrides always win over defaults).
				'show_image'    => false,
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
				// Phase 18 (v2.2.0) — augment ALL published `wc-donation`
				// campaigns with our overlay by default, regardless of
				// whether the admin has saved per-campaign companion config.
				// Admins who want the old per-campaign-opt-in behavior can
				// flip this off in Settings → General. The
				// `dfwc_should_augment_parent_form` filter remains as a
				// per-campaign escape hatch.
				'augment_all_campaigns'      => true,
				// Phase 13 (v1.2.0) — goal-aware giving. All opt-in; existing
				// installs see no behavior change until admin enables.
				'enable_goal_based_max'        => false,
				'enable_fully_funded_redirect' => false,
				'general_fund_campaign_id'     => 0,
				// Phase 14A (v1.3.0) — built-in stock donations. Opt-in; the
				// donor-facing "Donate Stock" UI only appears when
				// `stock_donations_enabled` is true AND the chosen mode
				// (pledge_form or overflow) is fully configured.
				'stock_donations_enabled'      => false,
				// Mode selector — `pledge_form` is the DIY built-in flow
				// (donor fills our form; admin reconciles manually after
				// shares clear). `overflow` routes donors to the org's
				// Overflow (overflow.co) hosted donation page.
				'stock_giving_mode'            => 'pledge_form',
				// Built-in pledge_form mode settings:
				'stock_broker_name'            => '',
				'stock_dtc_account_number'     => '',
				'stock_dtc_clearing_house_number' => '',
				'stock_admin_email'            => '',  // org email that receives pledge notifications
				'stock_tax_id'                 => '',  // org's EIN (e.g., "12-3456789") for tax receipts
				// Overflow mode settings:
				'stock_overflow_url'           => '',  // org's overflow.co donation URL
				'stock_overflow_webhook_secret' => '', // optional HMAC secret for incoming webhooks
				// Phase 15 (v2.0.0 → v2.1.0) — QuickBooks Online sync moved to
				// a sibling plugin: donations-for-woocommerce-qbo-sync.
				// Companion no longer ships QBO settings; the new plugin's
				// own option `dfwc_qbo_sync_settings` holds them. Existing
				// `qbo_*` keys in this option are left untouched (the new
				// plugin's Migration_Detector reads them once, copies into
				// its own option, and they become inert).
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
	public static function interval_block( bool $enabled = false, string $cta_template = '', string $interval_key = '' ): array {
		$block = array(
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

		// Phase 7 — `custom` interval gets cadence fields so admins can
		// configure a non-standard "every N <period>" rhythm. Other intervals
		// (weekly / quarterly / semiannual) have fixed cadence and don't
		// need these fields in storage.
		if ( 'custom' === $interval_key ) {
			$block['custom_period']   = 'month';
			$block['custom_interval'] = 1;
			$block['custom_label']    = '';
		}

		return $block;
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
