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
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Engine_Interval_Map;
use DFWC\Companion\Config\Goal_State;
use DFWC\Companion\Engine_Detector;
use DFWC\Companion\I18n\WPML_Strings;

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
	public static function wrap_with_overlay( int $campaign_id, string $inner, string $context = 'shortcode' ): string {
		$attrs        = self::build_overlay_attributes( $campaign_id );
		$crypto_attrs = Crypto_Donation_Renderer::should_render( $campaign_id, $context )
			? Crypto_Donation_Renderer::get_data_attributes( $campaign_id )
			: Crypto_Donation_Renderer::get_disabled_attributes();

		return sprintf(
			'<div class="dfwc-overlay" data-dfwc-overlay-target data-campaign-id="%1$d"'
				. ' data-engine="%2$s" data-active-interval="%3$s" data-config="%4$s"'
				. ' data-intervals="%5$s" data-display="%6$s" data-context="%7$s"'
				. ' data-language="%8$s" data-fully-funded="%9$s" data-general-fund-url="%10$s"'
				. ' data-stock-pledge-enabled="%11$s" data-stock-mode="%12$s"'
				. ' data-stock-overflow-url="%13$s"%14$s>%15$s</div>',
			(int) $campaign_id,
			esc_attr( $attrs['engine'] ),
			esc_attr( $attrs['active_interval'] ),
			esc_attr( (string) wp_json_encode( $attrs['form_config'] ) ),
			esc_attr( (string) wp_json_encode( $attrs['enabled_intervals'] ) ),
			esc_attr( (string) wp_json_encode( $attrs['display'] ) ),
			esc_attr( $context ),
			esc_attr( $attrs['language'] ),
			$attrs['fully_funded'] ? '1' : '0',
			esc_attr( $attrs['general_fund_url'] ),
			$attrs['stock_pledge_enabled'] ? '1' : '0',
			esc_attr( $attrs['stock_mode'] ),
			esc_attr( $attrs['stock_overflow_url'] ),
			self::format_crypto_attrs( $crypto_attrs ),
			$inner // already escaped inside parent's shortcode/template
		);
	}

	/**
	 * Serialize the crypto data-* attributes into a leading-space attribute
	 * string. Centralized so all three render sites (here,
	 * Context_Augmenter, Preview_Renderer) emit identically.
	 *
	 * @param array<string,string> $attrs
	 */
	public static function format_crypto_attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			$out .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		return $out;
	}

	/**
	 * Compute the overlay's data-* attribute payload (engine, enabled
	 * intervals, active interval, per-interval config, display options).
	 * Used by `wrap_with_overlay()` and by Context_Augmenter when emitting
	 * the wrapper's opening tag separately from its closing tag.
	 *
	 * @return array{engine:string,enabled_intervals:array<int,string>,active_interval:string,form_config:array<string,array>,display:array{show_title:bool,show_image:bool,cause_heading:string},language:string,fully_funded:bool,general_fund_url:string,stock_pledge_enabled:bool,stock_mode:string,stock_overflow_url:string}
	 */
	public static function build_overlay_attributes( int $campaign_id ): array {
		$config            = Config_Resolver::resolve( $campaign_id );
		$engine            = Engine_Detector::detect();
		$enabled_intervals = self::resolve_enabled_intervals( $config, $engine );
		$active_interval   = ! empty( $enabled_intervals ) ? $enabled_intervals[0] : Config_Resolver::INTERVAL_ONE_TIME;
		$form_config       = self::build_form_config( $config, $enabled_intervals, $campaign_id );
		$display           = Config_Resolver::resolve_display( $campaign_id );

		// Phase 9 — language code surfaced for analytics events. WPML active
		// language wins; falls back to site locale.
		$language = WPML_Strings::wpml_active() ? WPML_Strings::current_language() : '';
		if ( '' === $language && function_exists( 'get_locale' ) ) {
			$language = (string) get_locale();
		}

		// Phase 13 — goal-aware giving. Fully-funded state + the configured
		// general-fund campaign URL surface to the donor-side overlay JS so
		// the "Goal met!" card can render with a working CTA.
		$global           = Config_Resolver::get_global_settings();
		$goal             = Goal_State::for_campaign( $campaign_id );
		$general_fund_id  = (int) ( $global['general_fund_campaign_id'] ?? 0 );
		$general_fund_url = $general_fund_id > 0 && $general_fund_id !== $campaign_id
			? (string) get_permalink( $general_fund_id )
			: '';
		$fully_funded     = $goal->is_fully_funded() && '' !== $general_fund_url;

		// Phase 14A — built-in stock donations. Two modes:
		// - pledge_form: the org has filled in broker + DTC details
		// - overflow:    the org has filled in their overflow.co donation URL
		// `stock_pledge_enabled` is true when ANY supported mode is fully
		// configured. The mode itself + the Overflow URL travel separately
		// to the overlay JS so it can branch on which UI to render.
		$stock_mode = (string) ( $global['stock_giving_mode'] ?? 'pledge_form' );
		if ( ! in_array( $stock_mode, array( 'pledge_form', 'overflow' ), true ) ) {
			$stock_mode = 'pledge_form';
		}
		$stock_overflow_url = (string) ( $global['stock_overflow_url'] ?? '' );
		if ( '' !== $stock_overflow_url ) {
			$stock_overflow_url = (string) esc_url_raw( $stock_overflow_url );
		}
		if ( empty( $global['stock_donations_enabled'] ) ) {
			$stock_pledge_enabled = false;
		} elseif ( 'overflow' === $stock_mode ) {
			$stock_pledge_enabled = '' !== $stock_overflow_url;
		} else {
			$stock_pledge_enabled = '' !== (string) ( $global['stock_broker_name'] ?? '' )
				&& '' !== (string) ( $global['stock_dtc_account_number'] ?? '' );
		}

		return array(
			'engine'              => $engine,
			'enabled_intervals'   => $enabled_intervals,
			'active_interval'     => $active_interval,
			'form_config'         => $form_config,
			'display'             => $display,
			'language'            => $language,
			'fully_funded'        => $fully_funded,
			'general_fund_url'    => $general_fund_url,
			'stock_pledge_enabled' => $stock_pledge_enabled,
			'stock_mode'          => $stock_mode,
			'stock_overflow_url'  => $stock_overflow_url,
		);
	}

	/**
	 * Format the CTA label by substituting {amount} (formatted price) and
	 * {interval} (locale suffix like "/month") tokens. Output is HTML
	 * containing only allow-listed wc_price() tags; safe to use as
	 * `button.innerHTML` from PHP. The overlay JS does its own substitution
	 * client-side via textContent for runtime updates.
	 */
	public static function format_cta( string $template, float $amount, string $interval_key, array $block = array() ): string {
		$intervals = array(
			Config_Resolver::INTERVAL_ONE_TIME   => '',
			Config_Resolver::INTERVAL_MONTHLY    => '/' . __( 'month', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL     => '/' . __( 'year', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_WEEKLY     => '/' . __( 'week', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_QUARTERLY  => ' ' . __( 'every 3 months', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_SEMIANNUAL => ' ' . __( 'every 6 months', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_CUSTOM     => isset( $block['custom_label'] ) && '' !== (string) $block['custom_label']
				? ' ' . (string) $block['custom_label']
				: '',
		);
		$interval_suffix = $intervals[ $interval_key ] ?? '';

		$price_html = wc_price( $amount );

		$out = $template;
		$out = str_replace( '{amount}', $price_html, $out );
		$out = str_replace( '{interval}', esc_html( $interval_suffix ), $out );

		// Phase 7 — `{custom_label}` token for the custom interval. Empty
		// otherwise; the unknown-tokens stripper below cleans up.
		$custom_label = isset( $block['custom_label'] ) ? (string) $block['custom_label'] : '';
		$out          = str_replace( '{custom_label}', esc_html( $custom_label ), $out );

		// Strip any unknown tokens so admin-supplied templates can't smuggle markup.
		$out = (string) preg_replace( '/\{[a-z_]+\}/', '', $out );

		return wp_kses( $out, self::ALLOWED_PRICE_TAGS );
	}

	public static function allowed_price_tags(): array {
		return self::ALLOWED_PRICE_TAGS;
	}

	public static function interval_labels(): array {
		return array(
			Config_Resolver::INTERVAL_ONE_TIME   => __( 'One-time', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_MONTHLY    => __( 'Monthly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL     => __( 'Annually', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_WEEKLY     => __( 'Weekly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_QUARTERLY  => __( 'Quarterly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_SEMIANNUAL => __( 'Semi-annually', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_CUSTOM     => __( 'Custom', 'dfwc-companion' ),
		);
	}

	private static function resolve_enabled_intervals( array $config, string $engine ): array {
		$out             = array();
		$recurring_ok    = Engine_Detector::ENGINE_NONE !== $engine;
		// All non-one_time intervals are recurring; engine must be present.
		$all_intervals   = Config_Resolver::intervals( true );
		$recurring_keys  = array_diff( $all_intervals, array( Config_Resolver::INTERVAL_ONE_TIME ) );

		// Render-time gating: only iterate intervals the global toggle exposes.
		// Storage may carry advanced-interval config from a previous toggle-on
		// session; we honor the current toggle here so a toggle-off cleanly
		// hides the extras from donors without losing their saved data.
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
	private static function build_form_config( array $config, array $enabled_intervals, int $campaign_id = 0 ): array {
		$out             = array();
		$interval_labels = self::interval_labels();
		// Phase 6 — overlay block by donor's active currency. WCML primary;
		// WC base fallback. No-op when active currency matches base.
		$active_currency = Currency_Preset_Resolver::active_currency();

		// Phase 13 — pre-compute the goal clamp once per render. Only applied
		// to one_time interval (recurring intervals are documented to skip).
		$global              = Config_Resolver::get_global_settings();
		$goal_clamp_enabled  = ! empty( $global['enable_goal_based_max'] ) && $campaign_id > 0;
		$goal                = $goal_clamp_enabled ? Goal_State::for_campaign( $campaign_id ) : null;

		foreach ( $enabled_intervals as $key ) {
			$block   = Currency_Preset_Resolver::resolve( $config[ $key ], $active_currency );
			$cadence = Engine_Interval_Map::for_interval( $key, $block );
			$max     = (float) $block['max'];
			if ( null !== $goal && Config_Resolver::INTERVAL_ONE_TIME === $key ) {
				$max = $goal->clamp_max( $max );
			}
			$entry   = array(
				'min'                        => (float) $block['min'],
				'max'                        => $max,
				'default_index'              => (int) $block['default_index'],
				'cta_template'               => (string) $block['cta_template'],
				'label'                      => (string) ( $interval_labels[ $key ] ?? $key ),
				'custom_amount_enabled'      => ! empty( $block['custom_amount_enabled'] ),
				// Phase 5: donor impact messaging fields.
				'subtitle'                   => (string) ( $block['subtitle'] ?? '' ),
				'annual_equivalency'         => (string) ( $block['annual_equivalency'] ?? '' ),
				'impact_display_mode'        => (string) ( $block['impact_display_mode'] ?? 'below_button' ),
				'custom_amount_impact_label' => (string) ( $block['custom_amount_impact_label'] ?? '' ),
				// Phase 6 — currency context the overlay JS / Submit_Guard uses
				// when validating donor amounts and when re-fetching on
				// runtime currency switch.
				'currency'                   => (string) ( $block['_resolved_currency'] ?? $active_currency ),
				// Phase 7 — engine cadence so overlay JS knows which AJAX keys
				// to ship per interval. null for one_time. Custom interval
				// pulls cadence from the block's custom_period/custom_interval.
				'cadence'                    => null !== $cadence
					? array(
						'period'   => (string) $cadence['period'],
						'interval' => (int) $cadence['interval'],
					)
					: null,
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
			if ( Config_Resolver::INTERVAL_CUSTOM === $key ) {
				$entry['custom_label'] = (string) ( $block['custom_label'] ?? '' );
			}
			$out[ $key ] = $entry;
		}
		return $out;
	}

	private static function dev_comment( string $note ): string {
		return '<!-- ' . esc_html( $note ) . ' -->';
	}
}
