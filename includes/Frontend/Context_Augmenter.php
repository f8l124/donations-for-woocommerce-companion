<?php
/**
 * Auto-augment parent's donation form wherever parent renders it itself —
 * single-campaign permalink, parent's `[wc_woo_donation]` shortcode, donation
 * widget, and the cart / cart-block / checkout donation prompts. We DON'T
 * replace parent's output (that was the v0.1–v0.3 pattern, dropped in v0.4);
 * we just wrap it in our overlay marker so dfwc-overlay.js mutates the
 * amount + recurring controls.
 *
 * Re-entrancy: `Renderer::render()` already calls parent's shortcode and
 * wraps the result. To avoid double-wrapping when our shortcode is in use,
 * `Renderer::is_inside_render()` is checked here.
 *
 * Gating: a campaign is augmented only when the admin has saved companion
 * config for it (`Config_Resolver::is_configured($id)`). Campaigns the admin
 * never opted into render parent's vanilla form unchanged.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class Context_Augmenter {

	/**
	 * The six render contexts parent fires action hooks around. Each entry:
	 *   context_label => [ before_action, after_action, callback_arg_count ]
	 *
	 * The single-campaign action passes (campaign_id, post_id) — two args.
	 * Every other context passes just campaign_id.
	 *
	 * @var array<string,array{0:string,1:string,2:int}>
	 */
	private const CONTEXTS = array(
		'single'     => array( 'wc_donation_before_single_add_donation', 'wc_donation_after_single_add_donation', 2 ),
		'shortcode'  => array( 'wc_donation_before_shortcode_add_donation', 'wc_donation_after_shortcode_add_donation', 1 ),
		'widget'     => array( 'wc_donation_before_widget_add_donation', 'wc_donation_after_widget_add_donation', 1 ),
		'checkout'   => array( 'wc_donation_before_checkout_add_donation', 'wc_donation_after_checkout_add_donation', 1 ),
		'cart'       => array( 'wc_donation_before_cart_add_donation', 'wc_donation_after_cart_add_donation', 1 ),
		'cart_block' => array( 'wc_donation_before_cart_add_donation_block', 'wc_donation_after_cart_add_donation_block', 1 ),
	);

	/**
	 * Per-pair state — tracks which (context, campaign_id) pairs we opened a
	 * wrapper for so the matching after-action knows to close. Keyed
	 * `<context>_<campaign_id>`. Value is `true` when wrapper is open.
	 *
	 * @var array<string,bool>
	 */
	private array $opened = array();

	public function __construct() {
		foreach ( self::CONTEXTS as $context => $tuple ) {
			list( $before, $after, $args ) = $tuple;
			add_action(
				$before,
				function ( $campaign_id, $post_id = 0 ) use ( $context ) {
					$this->emit_open( $context, (int) $campaign_id );
				},
				1,
				$args
			);
			add_action(
				$after,
				function ( $campaign_id, $post_id = 0 ) use ( $context ) {
					$this->emit_close( $context, (int) $campaign_id );
				},
				1,
				$args
			);
		}
	}

	private function emit_open( string $context, int $campaign_id ): void {
		// Skip if our Renderer is already wrapping this render.
		if ( Renderer::is_inside_render() ) {
			return;
		}

		// Phase 2: gate on contract health. Broken contract → fall back to
		// parent's vanilla form rather than producing a half-broken overlay.
		// `dfwc_companion_contract_skip_augment` filter lets power users
		// override (rare; documented in plans/v2/03-phase-2-contract-diagnostics.md §7).
		if ( $this->contract_blocks_augmentation() ) {
			return;
		}

		// Allow third-party opt-out per campaign + context.
		$should_augment = (bool) apply_filters(
			'dfwc_should_augment_parent_form',
			Config_Resolver::is_configured( $campaign_id ),
			$campaign_id,
			$context
		);
		if ( ! $should_augment ) {
			return;
		}

		// Pull in overlay assets late — register hook ran on wp_enqueue_scripts:5
		// so the handles already exist; this just enqueues.
		Assets::enqueue();

		$attrs = Renderer::build_overlay_attributes( $campaign_id );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<div class="dfwc-overlay" data-dfwc-overlay-target data-campaign-id="%1$d" data-engine="%2$s" data-active-interval="%3$s" data-config="%4$s" data-intervals="%5$s" data-display="%6$s" data-context="%7$s">',
			(int) $campaign_id,
			esc_attr( $attrs['engine'] ),
			esc_attr( $attrs['active_interval'] ),
			esc_attr( (string) wp_json_encode( $attrs['form_config'] ) ),
			esc_attr( (string) wp_json_encode( $attrs['enabled_intervals'] ) ),
			esc_attr( (string) wp_json_encode( $attrs['display'] ) ),
			esc_attr( $context )
		);
		// phpcs:enable

		$this->opened[ $context . '_' . $campaign_id ] = true;
	}

	private function emit_close( string $context, int $campaign_id ): void {
		$key = $context . '_' . $campaign_id;
		if ( empty( $this->opened[ $key ] ) ) {
			return; // We didn't open one for this pair; nothing to close.
		}
		echo '</div>';
		unset( $this->opened[ $key ] );
	}

	/**
	 * Should the contract checker block augmentation right now?
	 *
	 * Defaults to true when the report is broken. The
	 * `dfwc_companion_contract_skip_augment` filter lets power users override
	 * (e.g., they have a custom workaround for a broken parent and want the
	 * overlay anyway). Filter receives the current value + the report so
	 * the override can be selective.
	 */
	private function contract_blocks_augmentation(): bool {
		// Defensive: if the contract module is missing for some reason
		// (e.g., a partial deploy mid-upgrade), don't block. Better to render
		// the overlay than to silently disable on every page.
		if ( ! class_exists( '\\DFWC\\Companion\\Contracts\\Parent_Form_Contract_Checker' ) ) {
			return false;
		}

		$checker = new \DFWC\Companion\Contracts\Parent_Form_Contract_Checker();
		$report  = $checker->get_report();
		$blocks  = $report->is_broken();

		/**
		 * Filter: should a broken contract block donor-side augmentation?
		 *
		 * @param bool                                                                $blocks True = skip overlay; false = augment anyway.
		 * @param \DFWC\Companion\Contracts\Parent_Form_Contract_Report $report The full contract report.
		 */
		return (bool) apply_filters( 'dfwc_companion_contract_skip_augment', $blocks, $report );
	}
}
