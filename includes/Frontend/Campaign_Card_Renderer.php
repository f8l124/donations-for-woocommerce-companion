<?php
/**
 * Campaign_Card_Renderer — render a single campaign card.
 *
 * Returns escaped HTML for one card. The directory renderer iterates and
 * concatenates; the Gutenberg block / Elementor widget can call this
 * directly for "show one campaign" patterns.
 *
 * Card structure is documented in plans/v2/05-phase-4-taxonomies-and-directory.md
 * §3.4. CSS classes are BEM-prefixed `.dfwc-card__*` for theme isolation.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Taxonomy\Campaign_Taxonomies;

final class Campaign_Card_Renderer {

	/**
	 * Render one card. Returns escaped HTML. Caller may pass a custom
	 * `cta_label` to override the default "Donate" text.
	 *
	 * @param int                  $campaign_id
	 * @param array<string,mixed>  $args        Optional rendering args.
	 * @return string
	 */
	public function render( int $campaign_id, array $args = array() ): string {
		$post = get_post( $campaign_id );
		if ( ! $post || 'wc-donation' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$data = $this->card_data( $post, $args );

		/**
		 * Allow third parties to augment card data before render. Useful
		 * for adding custom fields without subclassing the renderer.
		 *
		 * @param array<string,mixed> $data
		 * @param int                 $campaign_id
		 */
		$data = (array) apply_filters( 'dfwc_companion_card_data', $data, $campaign_id );

		ob_start();
		include DFWC_COMPANION_PATH . 'templates/directory-card.php';
		return (string) ob_get_clean();
	}

	/**
	 * Build the card-data array consumed by templates/directory-card.php.
	 *
	 * @param \WP_Post            $post
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function card_data( \WP_Post $post, array $args ): array {
		$cause_terms     = wp_get_post_terms( $post->ID, Campaign_Taxonomies::TAX_CAUSE, array( 'fields' => 'names' ) );
		$country_terms   = wp_get_post_terms( $post->ID, Campaign_Taxonomies::TAX_COUNTRY, array( 'fields' => 'names' ) );
		$is_featured     = (bool) get_post_meta( $post->ID, Campaign_Taxonomies::META_KEY_FEATURED, true );

		$thumbnail_id    = get_post_thumbnail_id( $post->ID );
		$image_url       = $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';
		$image_alt       = $thumbnail_id ? (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '';

		return array(
			'campaign_id' => $post->ID,
			'permalink'   => (string) get_permalink( $post ),
			'title'       => (string) get_the_title( $post ),
			'excerpt'     => (string) get_the_excerpt( $post ),
			'image_url'   => $image_url,
			'image_alt'   => $image_alt,
			'cause'       => is_array( $cause_terms ) && ! empty( $cause_terms ) ? (string) $cause_terms[0] : '',
			'country'     => is_array( $country_terms ) && ! empty( $country_terms ) ? (string) $country_terms[0] : '',
			'is_featured' => $is_featured,
			'cta_label'   => isset( $args['cta_label'] ) ? (string) $args['cta_label'] : __( 'Donate', 'dfwc-companion' ),
		);
	}
}
