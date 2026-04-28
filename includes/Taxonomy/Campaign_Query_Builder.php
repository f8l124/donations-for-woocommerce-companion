<?php
/**
 * Campaign_Query_Builder — translate filter arrays into WP_Query args.
 *
 * Single responsibility: take a filter array (cause, country, region,
 * program, sponsorship_type, urgency, featured, s, orderby, order, per_page,
 * page) and produce sanitized WP_Query args for `wc-donation` posts.
 *
 * No state. Filterable via `dfwc_companion_campaign_grid_query_args` so
 * power users can mutate the final args (add custom meta_query clauses,
 * etc.) before WP_Query runs.
 *
 * WPML auto-handles language scoping via its `pre_get_posts` filter — we
 * don't pass an explicit `lang` argument unless a shortcode/block does
 * (e.g., `[dfwc_campaign_grid lang="all"]` to ignore language and show
 * campaigns from every language).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class Campaign_Query_Builder {

	private const ALLOWED_ORDERBY    = array( 'menu_order', 'title', 'date', 'rand', 'modified' );
	private const ALLOWED_ORDER      = array( 'ASC', 'DESC' );
	private const DEFAULT_PER_PAGE   = 12;
	private const MAX_PER_PAGE       = 50;

	/**
	 * Map filter shortname → taxonomy slug. Filter input uses friendly
	 * shortcode-style names (`cause`); WP_Query needs the full taxonomy slug
	 * (`dfwc_cause`).
	 *
	 * @return array<string,string>
	 */
	private static function tax_map(): array {
		return array(
			'cause'             => Campaign_Taxonomies::TAX_CAUSE,
			'region'            => Campaign_Taxonomies::TAX_REGION,
			'country'           => Campaign_Taxonomies::TAX_COUNTRY,
			'program'           => Campaign_Taxonomies::TAX_PROGRAM,
			'sponsorship_type'  => Campaign_Taxonomies::TAX_SPONSORSHIP_TYPE,
			'urgency'           => Campaign_Taxonomies::TAX_URGENCY,
		);
	}

	/**
	 * Build sanitized WP_Query args from a filter array.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	public function build( array $filters ): array {
		$args = array(
			'post_type'        => 'wc-donation',
			'post_status'      => 'publish',
			'posts_per_page'   => $this->resolve_per_page( $filters ),
			'paged'            => $this->resolve_page( $filters ),
			'orderby'          => $this->resolve_orderby( $filters ),
			'order'            => $this->resolve_order( $filters ),
			'no_found_rows'    => false,  // we want pagination
			'suppress_filters' => false,
		);

		$tax_query = $this->build_tax_query( $filters );
		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// Featured-only filter via meta_query.
		if ( ! empty( $filters['featured'] ) ) {
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				array(
					'key'     => Campaign_Taxonomies::META_KEY_FEATURED,
					'value'   => '1',
					'compare' => '=',
				),
			);
			// phpcs:enable
		}

		// Featured-first ordering: when admin sorts by `featured`, we use
		// menu_order with a meta_query to pin featured campaigns to the top.
		// Simpler approach for v0.8.0: when filter says `orderby=featured`,
		// alias to a priority/meta sort handled at render time.
		if ( isset( $filters['orderby'] ) && 'featured' === $filters['orderby'] ) {
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_key'] = Campaign_Taxonomies::META_KEY_FEATURED; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			// phpcs:enable
			$args['orderby']  = array(
				'meta_value_num' => 'DESC',
				'menu_order'     => 'ASC',
				'title'          => 'ASC',
			);
		}

		// Search query.
		if ( ! empty( $filters['s'] ) ) {
			$args['s'] = sanitize_text_field( (string) $filters['s'] );
		}

		// Explicit language override (rare).
		if ( ! empty( $filters['lang'] ) ) {
			$args['lang'] = sanitize_key( (string) $filters['lang'] );
		}

		/**
		 * Final filter on the WP_Query args. Power-user hook for adding
		 * custom meta_query clauses, switching to date_query, etc.
		 *
		 * @param array<string,mixed> $args
		 * @param array<string,mixed> $filters Raw filter input.
		 */
		return (array) apply_filters( 'dfwc_companion_campaign_grid_query_args', $args, $filters );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int|string,mixed>
	 */
	private function build_tax_query( array $filters ): array {
		$tax_query = array( 'relation' => 'AND' );

		foreach ( self::tax_map() as $short => $taxonomy ) {
			$value = $filters[ $short ] ?? null;
			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$terms = array_filter( array_map( 'sanitize_title', array_map( 'strval', $value ) ) );
			} else {
				$terms = array_filter( array( sanitize_title( (string) $value ) ) );
			}

			if ( empty( $terms ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $terms,
				'operator' => 'IN',
			);
		}

		return $tax_query;
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function resolve_per_page( array $filters ): int {
		$raw = isset( $filters['per_page'] ) ? (int) $filters['per_page'] : self::DEFAULT_PER_PAGE;
		if ( $raw < 1 ) {
			return self::DEFAULT_PER_PAGE;
		}
		if ( $raw > self::MAX_PER_PAGE ) {
			return self::MAX_PER_PAGE;
		}
		return $raw;
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function resolve_page( array $filters ): int {
		$raw = isset( $filters['page'] ) ? (int) $filters['page'] : 1;
		return max( 1, min( 1000, $raw ) );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return string
	 */
	private function resolve_orderby( array $filters ): string {
		$raw = isset( $filters['orderby'] ) ? sanitize_key( (string) $filters['orderby'] ) : 'menu_order';
		// 'featured' handled separately in build(); fall through to menu_order here.
		if ( 'featured' === $raw ) {
			return 'menu_order';
		}
		return in_array( $raw, self::ALLOWED_ORDERBY, true ) ? $raw : 'menu_order';
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	private function resolve_order( array $filters ): string {
		$raw = isset( $filters['order'] ) ? strtoupper( (string) $filters['order'] ) : 'ASC';
		return in_array( $raw, self::ALLOWED_ORDER, true ) ? $raw : 'ASC';
	}

	/**
	 * Returns the map of filter shortname → taxonomy slug. Used by Renderer
	 * + REST controller to know which keys to read from inbound filter input.
	 *
	 * @return array<string,string>
	 */
	public static function filter_taxonomy_map(): array {
		return self::tax_map();
	}
}
