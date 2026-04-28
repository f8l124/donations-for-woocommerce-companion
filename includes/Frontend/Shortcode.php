<?php
/**
 * `[dfwc_recurring_donation campaign_id="N"]` shortcode.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'dfwc_recurring_donation';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array( 'campaign_id' => '0' ),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$campaign_id = absint( $atts['campaign_id'] );
		return Renderer::render( $campaign_id );
	}
}
