<?php
/**
 * Render the augmented donor experience: parent's full donation form, wrapped
 * in our overlay marker so dfwc-overlay.js can mutate the amount + recurring
 * controls into our interval-first UI.
 *
 * Single entry point used by Shortcode, Block, and Elementor_Widget. Returns a
 * string; never echoes. The marker carries per-form config as JSON in
 * data-config so the overlay can run without a global localize that scales
 * poorly for multi-instance pages.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Engine_Detector;

final class Renderer {

	private const ALLOWED_PRICE_TAGS = array(
		'bdi'  => array(),
		'span' => array( 'class' => true ),
	);

	/**
	 * Re-entrancy guard. True while we are inside `render()` and have called
	 * parent's `[wc_woo_donation]` shortcode — Context_Augmenter checks this
	 * to avoid wrapping parent's render a second time when its action hooks
	 * fire inside our delegated do_shortcode call.
	 */
	private static bool $inside_render = false;

	public static function is_inside_render(): bool {
		return self::$inside_render;
	}

	/**
	 * Build the augmented donor experience for a campaign.
	 */
	public static function render( int $campaign_id ): string {
		if ( $campaign_id < 1 ) {
			return self::dev_comment( 'dfwc: missing campaign_id' );
		}

		$post = get_post( $campaign_id );
		if ( ! $post || 'wc-donation' !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::dev_comment( 'dfwc: campaign not found, wrong post type, or unpublished' );
		}

		// Parent must be active for the augmentation pattern to work — its
		// shortcode renders the form we augment.
		if ( ! shortcode_exists( 'wc_woo_donation' ) ) {
			return self::dev_comment( 'dfwc: parent plugin shortcode [wc_woo_donation] not registered' );
		}

		// Pull in our overlay assets — registered globally by Frontend\Assets.
		Assets::enqueue();

		// Delegate to parent's shortcode for the full form HTML (cause selector,
		// gift aid, processing fee, tributes, button, etc.). Set the inside-render
		// flag so Context_Augmenter knows not to also wrap when parent's
		// `wc_donation_before_shortcode_add_donation` action fires inside.
		self::$inside_render = true;
		try {
			$inner = do_shortcode( '[wc_woo_donation id="' . (int) $campaign_id . '"]' );
		} finally {
			self::$inside_render = false;
		}

		if ( '' === trim( (string) $inner ) ) {
			return self::dev_comment( 'dfwc: parent shortcode returned empty (campaign deleted or unpublished?)' );
		}

		return self::wrap_with_overlay( $campaign_id, $inner );
	}

	/**
	 * Wrap arbitrary parent-rendered form HTML in our overlay marker. Used by
	 * `render()` (after delegating to parent's shortcode) and by
	 * Context_Augmenter (after capturing parent's auto-render output via the
	 * cart/checkout/widget action hooks).
	 *
	 * @param int    $campaign_id
	 * @param string $inner      Parent's pre-rendered form HTML.
	 */
	public static function wrap_with_overlay( int $campaign_id, string $inner ): string {
		$attrs = self::build_overlay_attributes( $campaign_id );

		return sprintf(
			'<div class="dfwc-overlay" data-dfwc-overlay-target data-campaign-id="%1$d" data-engine="%2$s" data-active-interval="%3$s" data-config="%4$s" data-intervals="%5$s" data-display="%6$s">%7$s</div>',
			(int) $campaign_id,
			esc_attr( $attrs['engine'] ),
			esc_attr( $attrs['active_interval'] ),
			esc_attr( (string) wp_json_encode( $attrs['form_config'] ) ),
			esc_attr( (string) wp_json_encode( $attrs['enabled_intervals'] ) ),
			esc_attr( (string) wp_json_encode( $attrs['display'] ) ),
			$inner // already escaped inside parent's shortcode/template
		);
	}

	/**
	 * Compute the overlay's data-* attribute payload (engine, enabled
	 * intervals, active interval, per-interval config, display options).
	 * Used by `wrap_with_overlay()` and by Context_Augmenter when emitting
	 * the wrapper's opening tag separately from its closing tag.
	 *
	 * @return array{engine:string,enabled_intervals:array<int,string>,active_interval:string,form_config:array<string,array>,display:array{show_title:bool,show_image:bool,cause_heading:string}}
	 */
	public static function build_overlay_attributes( int $campaign_id ): array {
		$config            = Config_Resolver::resolve( $campaign_id );
		$engine            = Engine_Detector::detect();
		$enabled_intervals = self::resolve_enabled_intervals( $config, $engine );
		$active_interval   = ! empty( $enabled_intervals ) ? $enabled_intervals[0] : Config_Resolver::INTERVAL_ONE_TIME;
		$form_config       = self::build_form_config( $config, $enabled_intervals );
		$display           = Config_Resolver::resolve_display( $campaign_id );

		return array(
			'engine'            => $engine,
			'enabled_intervals' => $enabled_intervals,
			'active_interval'   => $active_interval,
			'form_config'       => $form_config,
			'display'           => $display,
		);
	}

	/**
	 * Format the CTA label by substituting {amount} (formatted price) and
	 * {interval} (locale suffix like "/month") tokens. Output is HTML
	 * containing only allow-listed wc_price() tags; safe to use as
	 * `button.innerHTML` from PHP. The overlay JS does its own substitution
	 * client-side via textContent for runtime updates.
	 */
	public static function format_cta( string $template, float $amount, string $interval_key ): string {
		$intervals = array(
			Config_Resolver::INTERVAL_ONE_TIME => '',
			Config_Resolver::INTERVAL_MONTHLY  => '/' . __( 'month', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL   => '/' . __( 'year', 'dfwc-companion' ),
		);
		$interval_suffix = $intervals[ $interval_key ] ?? '';

		$price_html = wc_price( $amount );

		$out = $template;
		$out = str_replace( '{amount}', $price_html, $out );
		$out = str_replace( '{interval}', esc_html( $interval_suffix ), $out );

		// Strip any unknown tokens so admin-supplied templates can't smuggle markup.
		$out = (string) preg_replace( '/\{[a-z_]+\}/', '', $out );

		return wp_kses( $out, self::ALLOWED_PRICE_TAGS );
	}

	public static function allowed_price_tags(): array {
		return self::ALLOWED_PRICE_TAGS;
	}

	public static function interval_labels(): array {
		return array(
			Config_Resolver::INTERVAL_ONE_TIME => __( 'One-time', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_MONTHLY  => __( 'Monthly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL   => __( 'Annually', 'dfwc-companion' ),
		);
	}

	private static function resolve_enabled_intervals( array $config, string $engine ): array {
		$out             = array();
		$recurring_ok    = Engine_Detector::ENGINE_NONE !== $engine;
		$recurring_keys  = array( Config_Resolver::INTERVAL_MONTHLY, Config_Resolver::INTERVAL_ANNUAL );

		foreach ( Config_Resolver::intervals() as $key ) {
			if ( ! ( $config[ $key ]['enabled'] ?? false ) ) {
				continue;
			}
			if ( in_array( $key, $recurring_keys, true ) && ! $recurring_ok ) {
				continue;
			}
			if ( empty( $config[ $key ]['presets'] ) && ( $config[ $key ]['min'] ?? 0 ) <= 0 ) {
				continue;
			}
			$out[] = $key;
		}

		if ( empty( $out ) ) {
			$out[] = Config_Resolver::INTERVAL_ONE_TIME;
		}

		return $out;
	}

	/**
	 * Reduce the full config to just the bits the JS overlay needs, keyed by
	 * enabled interval. Includes presets so the overlay can render its preset
	 * chips client-side; preset.label is forwarded so it can populate
	 * parent's `selectedLabel` POST field via JS.
	 */
	private static function build_form_config( array $config, array $enabled_intervals ): array {
		$out             = array();
		$interval_labels = self::interval_labels();
		foreach ( $enabled_intervals as $key ) {
			$block       = $config[ $key ];
			$out[ $key ] = array(
				'min'                        => (float) $block['min'],
				'max'                        => (float) $block['max'],
				'default_index'              => (int) $block['default_index'],
				'cta_template'               => (string) $block['cta_template'],
				'label'                      => (string) ( $interval_labels[ $key ] ?? $key ),
				'custom_amount_enabled'      => ! empty( $block['custom_amount_enabled'] ),
				// Phase 5: donor impact messaging fields.
				'subtitle'                   => (string) ( $block['subtitle'] ?? '' ),
				'annual_equivalency'         => (string) ( $block['annual_equivalency'] ?? '' ),
				'impact_display_mode'        => (string) ( $block['impact_display_mode'] ?? 'below_button' ),
				'custom_amount_impact_label' => (string) ( $block['custom_amount_impact_label'] ?? '' ),
				'presets'                    => array_map(
					static function ( $p ) {
						return array(
							'amount'       => (float) ( $p['amount'] ?? 0 ),
							'label'        => (string) ( $p['label'] ?? '' ),
							'impact_label' => (string) ( $p['impact_label'] ?? '' ),
							'is_featured'  => ! empty( $p['is_featured'] ),
						);
					},
					(array) ( $block['presets'] ?? array() )
				),
			);
		}
		return $out;
	}

	private static function dev_comment( string $note ): string {
		return '<!-- ' . esc_html( $note ) . ' -->';
	}
}
