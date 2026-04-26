<?php
/**
 * Optionally replace the parent plugin's single-campaign and `[wc_woo_donation]`
 * shortcode forms with our interval-first form.
 *
 * Parent plugin doesn't expose a filter to skip its own template `require`,
 * so we use the `ob_start` / `ob_get_clean` pattern around the documented
 * `wc_donation_before_*_add_donation` / `wc_donation_after_*_add_donation`
 * action pairs to discard parent's output and substitute ours.
 *
 * Per-form-mode opt-in via the `_dfwc_companion_form_mode` campaign meta
 * (set to `'replace'` in the side meta box). Default is `'shortcode_only'`,
 * which leaves parent's form untouched.
 *
 * Master plan deviation D3.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config_Resolver;

final class Form_Replacer {

	/**
	 * Per-pair buffer-depth tracker so nested `ob_start()` calls (theme,
	 * other plugins) don't get accidentally flushed by our `ob_end_clean`.
	 * Keyed by 'single_<id>' or 'shortcode_<id>'.
	 *
	 * @var array<string,int>
	 */
	private array $buffer_levels = [];

	public function __construct() {
		// Priority 1 = before any other listener so we wrap the require cleanly.
		add_action( 'wc_donation_before_single_add_donation', [ $this, 'maybe_buffer_start_single' ], 1, 2 );
		add_action( 'wc_donation_after_single_add_donation', [ $this, 'maybe_buffer_replace_single' ], 1, 2 );

		add_action( 'wc_donation_before_shortcode_add_donation', [ $this, 'maybe_buffer_start_shortcode' ], 1, 1 );
		add_action( 'wc_donation_after_shortcode_add_donation', [ $this, 'maybe_buffer_replace_shortcode' ], 1, 1 );
	}

	/**
	 * @param int $campaign_id
	 * @param int $post_id     Unused; kept for hook signature.
	 */
	public function maybe_buffer_start_single( $campaign_id, $post_id = 0 ): void {
		$campaign_id = (int) $campaign_id;
		if ( ! $this->should_replace( $campaign_id ) ) {
			return;
		}
		$this->buffer_levels[ 'single_' . $campaign_id ] = ob_get_level();
		ob_start();
	}

	public function maybe_buffer_replace_single( $campaign_id, $post_id = 0 ): void {
		$campaign_id = (int) $campaign_id;
		$this->discard_and_replace( 'single_' . $campaign_id, $campaign_id );
	}

	public function maybe_buffer_start_shortcode( $campaign_id ): void {
		$campaign_id = (int) $campaign_id;
		if ( ! $this->should_replace( $campaign_id ) ) {
			return;
		}
		$this->buffer_levels[ 'shortcode_' . $campaign_id ] = ob_get_level();
		ob_start();
	}

	public function maybe_buffer_replace_shortcode( $campaign_id ): void {
		$campaign_id = (int) $campaign_id;
		$this->discard_and_replace( 'shortcode_' . $campaign_id, $campaign_id );
	}

	/**
	 * Discard parent's output for the given key and emit our renderer's HTML.
	 * Buffer-leak protection: pop any buffers opened by intermediary plugins
	 * within parent's template back down to the level we started at.
	 */
	private function discard_and_replace( string $key, int $campaign_id ): void {
		if ( ! isset( $this->buffer_levels[ $key ] ) ) {
			return;
		}

		$start_level = $this->buffer_levels[ $key ];
		unset( $this->buffer_levels[ $key ] );

		while ( ob_get_level() > $start_level ) {
			ob_end_clean();
		}

		// Pull in form assets — Renderer alone doesn't enqueue them when
		// we're invoked from the replace path on a non-singular wc-donation
		// query (rare but possible).
		Assets::enqueue();

		// Renderer output is sanitized internally (template uses esc_*; CTA
		// HTML is wp_kses-restricted). Safe to echo as-is.
		echo Renderer::render( $campaign_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function should_replace( int $campaign_id ): bool {
		if ( $campaign_id < 1 ) {
			return false;
		}
		return Config_Resolver::FORM_MODE_REPLACE === Config_Resolver::form_mode( $campaign_id );
	}
}
