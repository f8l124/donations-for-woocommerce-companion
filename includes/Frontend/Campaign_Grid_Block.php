<?php
/**
 * Campaign_Grid_Block — server-rendered Gutenberg block
 * `dfwc-companion/campaign-grid`.
 *
 * Block metadata in `assets/blocks/campaign-grid/block.json`. Editor
 * preview via ServerSideRender so the editor shows the same HTML donors
 * see. Render delegates to Campaign_Directory_Renderer.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Campaign_Grid_Block {

	public const BLOCK_NAME = 'dfwc-companion/campaign-grid';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			DFWC_COMPANION_PATH . 'assets/blocks/campaign-grid',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function render( array $attributes ): string {
		// Editor preview: render in editor too (server-side render shows live).
		Directory_Assets::enqueue();

		// Coerce attribute types into renderer args.
		$args = array(
			'cause'             => isset( $attributes['cause'] ) ? (string) $attributes['cause'] : '',
			'region'            => isset( $attributes['region'] ) ? (string) $attributes['region'] : '',
			'country'           => isset( $attributes['country'] ) ? (string) $attributes['country'] : '',
			'program'           => isset( $attributes['program'] ) ? (string) $attributes['program'] : '',
			'sponsorship_type'  => isset( $attributes['sponsorship_type'] ) ? (string) $attributes['sponsorship_type'] : '',
			'urgency'           => isset( $attributes['urgency'] ) ? (string) $attributes['urgency'] : '',
			'featured'          => ! empty( $attributes['featured'] ),
			'orderby'           => isset( $attributes['orderby'] ) ? (string) $attributes['orderby'] : 'menu_order',
			'order'             => isset( $attributes['order'] ) ? (string) $attributes['order'] : 'ASC',
			'per_page'          => isset( $attributes['per_page'] ) ? (int) $attributes['per_page'] : 12,
			'layout'            => isset( $attributes['layout'] ) ? (string) $attributes['layout'] : 'grid',
			'show_filters'      => isset( $attributes['show_filters'] ) ? (bool) $attributes['show_filters'] : true,
		);

		return ( new Campaign_Directory_Renderer() )->render( $args );
	}
}
