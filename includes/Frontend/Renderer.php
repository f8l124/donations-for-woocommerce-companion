<?php
/**
 * Builds the donor-facing form HTML. Single entry point used by Shortcode,
 * Block, Elementor_Widget, and (in Phase F) Form_Replacer.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config_Resolver;
use DFWC\Companion\Engine_Detector;

final class Renderer {

	private const ALLOWED_PRICE_TAGS = [
		'bdi'  => [],
		'span' => [ 'class' => true ],
	];

	/**
	 * Render the form for a campaign. Returns sanitized HTML; never echoes.
	 *
	 * Returns an empty string for invalid campaigns (with an HTML comment for
	 * developers, visible only in source view, never to end users).
	 */
	public static function render( int $campaign_id ): string {
		if ( $campaign_id < 1 ) {
			return self::dev_comment( 'dfwc: missing campaign_id' );
		}

		$post = get_post( $campaign_id );
		if ( ! $post || 'wc-donation' !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::dev_comment( 'dfwc: campaign not found, wrong post type, or unpublished' );
		}

		$config         = Config_Resolver::resolve( $campaign_id );
		$engine         = Engine_Detector::detect();
		$intervals      = Config_Resolver::intervals();
		$interval_label = self::interval_labels();
		$form_uid       = wp_unique_id( 'dfwc-form-' );

		// Determine which intervals are actually offered (engine-aware).
		$enabled_intervals = self::resolve_enabled_intervals( $config, $engine );

		// First enabled interval becomes the initial active tab.
		$active_interval = ! empty( $enabled_intervals ) ? $enabled_intervals[0] : Config_Resolver::INTERVAL_ONE_TIME;

		// Per-form JSON config consumed by the JS layer (no global localize bloat,
		// no leaking of other campaigns' configs into the page).
		$form_config = self::build_form_config( $config, $enabled_intervals );

		ob_start();
		include DFWC_COMPANION_PATH . 'templates/recurring-donation-form.php';
		return (string) ob_get_clean();
	}

	/**
	 * Format the CTA button label by substituting {amount} (formatted price)
	 * and {interval} (locale suffix like "/month") tokens. Output is HTML
	 * containing only allow-listed wc_price() tags; safe to echo without
	 * further escaping.
	 */
	public static function format_cta( string $template, float $amount, string $interval_key ): string {
		$intervals = [
			Config_Resolver::INTERVAL_ONE_TIME => '',
			Config_Resolver::INTERVAL_MONTHLY  => '/' . __( 'month', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL   => '/' . __( 'year', 'dfwc-companion' ),
		];
		$interval_suffix = $intervals[ $interval_key ] ?? '';

		$price_html = wc_price( $amount );

		$out = $template;
		$out = str_replace( '{amount}', $price_html, $out );
		$out = str_replace( '{interval}', esc_html( $interval_suffix ), $out );

		// Strip any unknown tokens so admin-supplied templates can't smuggle markup.
		$out = (string) preg_replace( '/\{[a-z_]+\}/', '', $out );

		return wp_kses( $out, self::ALLOWED_PRICE_TAGS );
	}

	/**
	 * Allow-listed kses tags for echoing wc_price() output in templates.
	 */
	public static function allowed_price_tags(): array {
		return self::ALLOWED_PRICE_TAGS;
	}

	/**
	 * Display labels for each interval. Mirrors Admin\Meta_Box::interval_labels()
	 * but kept local to avoid an admin → frontend dependency.
	 */
	public static function interval_labels(): array {
		return [
			Config_Resolver::INTERVAL_ONE_TIME => __( 'One-time', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_MONTHLY  => __( 'Monthly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL   => __( 'Annually', 'dfwc-companion' ),
		];
	}

	/**
	 * The set of intervals that should actually be selectable for this
	 * campaign right now: enabled in config AND supported by the active
	 * recurring engine (one-time always supported).
	 */
	private static function resolve_enabled_intervals( array $config, string $engine ): array {
		$out             = [];
		$recurring_ok    = Engine_Detector::ENGINE_NONE !== $engine;
		$recurring_keys  = [ Config_Resolver::INTERVAL_MONTHLY, Config_Resolver::INTERVAL_ANNUAL ];

		foreach ( Config_Resolver::intervals() as $key ) {
			if ( ! ( $config[ $key ]['enabled'] ?? false ) ) {
				continue;
			}
			if ( in_array( $key, $recurring_keys, true ) && ! $recurring_ok ) {
				continue;
			}
			if ( empty( $config[ $key ]['presets'] ) && ( $config[ $key ]['min'] ?? 0 ) <= 0 ) {
				continue; // Nothing for the donor to pick.
			}
			$out[] = $key;
		}

		// One-time is the universal fallback if nothing else is selectable.
		if ( empty( $out ) ) {
			$out[] = Config_Resolver::INTERVAL_ONE_TIME;
		}

		return $out;
	}

	/**
	 * Reduce the full config to just the bits the JS layer needs, keyed by
	 * enabled interval. Avoids leaking disabled-interval data into the page.
	 */
	private static function build_form_config( array $config, array $enabled_intervals ): array {
		$out = [];
		foreach ( $enabled_intervals as $key ) {
			$block = $config[ $key ];
			$out[ $key ] = [
				'min'           => (float) $block['min'],
				'max'           => (float) $block['max'],
				'default_index' => (int) $block['default_index'],
				'cta_template'  => (string) $block['cta_template'],
			];
		}
		return $out;
	}

	private static function dev_comment( string $note ): string {
		return '<!-- ' . esc_html( $note ) . ' -->';
	}
}
