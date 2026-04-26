<?php
/**
 * Replace the parent plugin's donation form with our interval-first form
 * everywhere parent renders it: single-campaign permalink, [wc_woo_donation]
 * shortcode, widget, and WC checkout context.
 *
 * Parent plugin doesn't expose a filter to skip its own template `require`,
 * so we use the `ob_start` / `ob_get_clean` pattern around the documented
 * `wc_donation_before_*_add_donation` / `wc_donation_after_*_add_donation`
 * action pairs to discard parent's output and substitute ours.
 *
 * Behavior in 0.3.0 onwards: replacement is unconditional. Power users who
 * want to keep parent's form (e.g., to render only via shortcode/block on a
 * separate page) can opt out per-campaign via the
 * `dfwc_should_replace_parent_form` filter:
 *
 *     add_filter( 'dfwc_should_replace_parent_form', function ( $replace, $campaign_id ) {
 *         return ( 123 === (int) $campaign_id ) ? false : $replace;
 *     }, 10, 2 );
 *
 * Master plan deviation D3.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Form_Replacer {

	/**
	 * Per-pair buffer-depth tracker so nested `ob_start()` calls (theme,
	 * other plugins) don't get accidentally flushed by our `ob_end_clean`.
	 * Keyed by '<context>_<campaign_id>'.
	 *
	 * @var array<string,int>
	 */
	private array $buffer_levels = [];

	/**
	 * Render contexts we wrap. The before/after hooks always come in pairs.
	 * Single arg = (campaign_id), two args = (campaign_id, post_id).
	 *
	 * @var array<string,int>
	 */
	private const CONTEXTS = [
		'single'    => 2,
		'shortcode' => 1,
		'widget'    => 1,
		'checkout'  => 1,
	];

	public function __construct() {
		foreach ( self::CONTEXTS as $context => $arg_count ) {
			// Priority 1 = before any other listener so we wrap the require cleanly.
			add_action( "wc_donation_before_{$context}_add_donation", function ( $campaign_id, $post_id = 0 ) use ( $context ) {
				$this->maybe_buffer_start( $context, (int) $campaign_id );
			}, 1, $arg_count );
			add_action( "wc_donation_after_{$context}_add_donation", function ( $campaign_id, $post_id = 0 ) use ( $context ) {
				$this->discard_and_replace( $context, (int) $campaign_id );
			}, 1, $arg_count );
		}
	}

	private function maybe_buffer_start( string $context, int $campaign_id ): void {
		if ( ! $this->should_replace( $campaign_id ) ) {
			return;
		}
		$this->buffer_levels[ $context . '_' . $campaign_id ] = ob_get_level();
		ob_start();
	}

	/**
	 * Discard parent's output for the given (context, campaign_id) pair and
	 * emit our renderer's HTML. Buffer-leak protection: pop any buffers
	 * opened by intermediary plugins within parent's template back down to
	 * the level we started at.
	 */
	private function discard_and_replace( string $context, int $campaign_id ): void {
		$key = $context . '_' . $campaign_id;
		if ( ! isset( $this->buffer_levels[ $key ] ) ) {
			return;
		}

		$start_level = $this->buffer_levels[ $key ];
		unset( $this->buffer_levels[ $key ] );

		while ( ob_get_level() > $start_level ) {
			ob_end_clean();
		}

		// Pull in form assets — Renderer alone doesn't enqueue them when
		// we're invoked from a non-singular wc-donation query context (e.g.
		// the widget area on an unrelated page).
		Assets::enqueue();

		// Renderer output is sanitized internally (template uses esc_*; CTA
		// HTML is wp_kses-restricted). Safe to echo as-is.
		echo Renderer::render( $campaign_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Always replace, unless a third-party hooks `dfwc_should_replace_parent_form`
	 * to opt this campaign out. In 0.1.x–0.2.x this was gated on a per-campaign
	 * `_dfwc_companion_form_mode` meta, but the opt-IN default in 0.1.x kept
	 * leaving sites with two donation forms after upgrade — costlier than the
	 * value of the opt-out. (See readme 0.3.0 changelog.)
	 *
	 * @param int $campaign_id
	 */
	private function should_replace( int $campaign_id ): bool {
		if ( $campaign_id < 1 ) {
			return false;
		}
		/**
		 * Filter whether to replace the parent plugin's donation form for a
		 * given campaign. Default true.
		 *
		 * @param bool $replace     Whether to replace.
		 * @param int  $campaign_id The wc-donation post ID being rendered.
		 */
		return (bool) apply_filters( 'dfwc_should_replace_parent_form', true, $campaign_id );
	}
}
