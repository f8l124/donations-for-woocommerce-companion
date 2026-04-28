<?php
/**
 * Campaign_Grid_Shortcode — `[dfwc_campaign_grid]`.
 *
 * Examples:
 *   [dfwc_campaign_grid]
 *   [dfwc_campaign_grid cause="education"]
 *   [dfwc_campaign_grid country="kenya" featured="true" per_page="6"]
 *   [dfwc_campaign_grid orderby="title" order="ASC" layout="list"]
 *   [dfwc_campaign_grid show_filters="false"]
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Campaign_Grid_Shortcode {

	public const TAG = 'dfwc_campaign_grid';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $attrs
	 */
	public function render( $attrs ): string {
		$attrs = is_array( $attrs ) ? $attrs : array();

		$args = shortcode_atts(
			array(
				'cause'             => '',
				'region'            => '',
				'country'           => '',
				'program'           => '',
				'sponsorship_type'  => '',
				'urgency'           => '',
				'featured'          => '',
				's'                 => '',
				'orderby'           => 'menu_order',
				'order'             => 'ASC',
				'per_page'          => 12,
				'page'              => 1,
				'layout'            => 'grid',
				'show_filters'      => 'true',
				'lang'              => '',
			),
			$attrs,
			self::TAG
		);

		// Coerce string-y booleans.
		$args['featured']     = $this->bool_attr( $args['featured'] );
		$args['show_filters'] = $this->bool_attr( $args['show_filters'] );

		// Directory has its own CSS + JS handles; overlay assets aren't
		// needed for the grid (cards link out to single-campaign permalinks
		// which load the overlay via Context_Augmenter).
		Directory_Assets::enqueue();

		return ( new Campaign_Directory_Renderer() )->render( $args );
	}

	/**
	 * @param mixed $value
	 */
	private function bool_attr( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( trim( $value ) );
			return in_array( $lower, array( 'true', '1', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
}
