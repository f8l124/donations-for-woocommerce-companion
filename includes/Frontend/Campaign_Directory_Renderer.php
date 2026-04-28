<?php
/**
 * Campaign_Directory_Renderer — render the donor-facing campaign grid.
 *
 * Composes Campaign_Query_Builder + WP_Query + Campaign_Card_Renderer to
 * produce a full grid HTML output: optional filter bar, card grid (or
 * list), pagination.
 *
 * Layouts:
 *   grid (default) — responsive auto-fill columns
 *   list           — vertical stack with wider cards
 *
 * Show/hide filters via the `show_filters` arg (default true).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Taxonomy\Campaign_Query_Builder;
use DFWC\Companion\Taxonomy\Campaign_Taxonomies;

final class Campaign_Directory_Renderer {

	/**
	 * Render the directory.
	 *
	 * @param array<string,mixed> $args Renderer args including filter input.
	 * @return string Escaped HTML.
	 */
	public function render( array $args = array() ): string {
		// Pull live filter input from $_GET when it appears to be the same
		// directory invocation (preserves filter selections across submits).
		$args = $this->merge_get_filters( $args );

		$layout = isset( $args['layout'] ) && 'list' === $args['layout'] ? 'list' : 'grid';
		$show_filters = ! isset( $args['show_filters'] ) || ! empty( $args['show_filters'] );

		$builder = new Campaign_Query_Builder();
		$query_args = $builder->build( $args );

		$query   = new \WP_Query( $query_args );
		$card_renderer = new Campaign_Card_Renderer();

		// Pull current filter values for the filter-bar UI.
		$current_filters = $this->extract_current_filters( $args );

		// Pre-load the term lists for filter dropdowns when filters are visible.
		$term_lists = $show_filters ? $this->build_term_lists() : array();

		// Pagination data.
		$paged       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$total_pages = $query->max_num_pages > 0 ? (int) $query->max_num_pages : 1;
		$total_found = (int) $query->found_posts;

		ob_start();
		?>
		<div class="dfwc-directory dfwc-directory--<?php echo esc_attr( $layout ); ?>" data-dfwc-directory>
			<?php if ( $show_filters ) : ?>
				<?php include DFWC_COMPANION_PATH . 'templates/directory-filters.php'; ?>
			<?php endif; ?>

			<?php if ( $query->have_posts() ) : ?>
				<div class="dfwc-directory__grid" role="list">
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card renderer escapes internally
						echo $card_renderer->render( get_the_ID() );
					endwhile;
					wp_reset_postdata();
					?>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<nav class="dfwc-directory__pagination" aria-label="<?php esc_attr_e( 'Campaigns pagination', 'dfwc-companion' ); ?>">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'dfwc_page', '%#%' ),
									'format'    => '',
									'current'   => $paged,
									'total'     => $total_pages,
									'prev_text' => __( '&laquo; Prev', 'dfwc-companion' ),
									'next_text' => __( 'Next &raquo;', 'dfwc-companion' ),
								)
							)
						);
						?>
					</nav>
				<?php endif; ?>

				<p class="dfwc-directory__count">
					<?php
					/* translators: %d: total number of campaigns matching the current filters */
					$count_label = _n( '%d campaign', '%d campaigns', $total_found, 'dfwc-companion' );
					printf( esc_html( $count_label ), (int) $total_found );
					?>
				</p>
			<?php else : ?>
				<p class="dfwc-directory__empty">
					<?php esc_html_e( 'No campaigns match your filters.', 'dfwc-companion' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Merge filter input from $_GET into the args. URL params use a
	 * `dfwc_` prefix so they don't collide with parent / theme params.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function merge_get_filters( array $args ): array {
		$prefixed = array(
			'dfwc_cause'             => 'cause',
			'dfwc_region'            => 'region',
			'dfwc_country'           => 'country',
			'dfwc_program'           => 'program',
			'dfwc_sponsorship_type'  => 'sponsorship_type',
			'dfwc_urgency'           => 'urgency',
			'dfwc_s'                 => 's',
			'dfwc_orderby'           => 'orderby',
			'dfwc_order'             => 'order',
			'dfwc_page'              => 'page',
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter input from public-facing form
		foreach ( $prefixed as $get_key => $arg_key ) {
			if ( isset( $_GET[ $get_key ] ) && ! isset( $args[ $arg_key ] ) ) {
				$args[ $arg_key ] = wp_unslash( $_GET[ $get_key ] );
			}
		}
		// phpcs:enable

		return $args;
	}

	/**
	 * Extract the current filter selections for the filter-bar UI.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,string>
	 */
	private function extract_current_filters( array $args ): array {
		$out = array();
		foreach ( array( 'cause', 'region', 'country', 'program', 'sponsorship_type', 'urgency', 's', 'orderby' ) as $key ) {
			if ( isset( $args[ $key ] ) && ! is_array( $args[ $key ] ) ) {
				$out[ $key ] = (string) $args[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Pre-load the top-N terms for each taxonomy used in the filter bar.
	 *
	 * @return array<string,array<int,\WP_Term>>
	 */
	private function build_term_lists(): array {
		$out = array();
		foreach ( Campaign_Query_Builder::filter_taxonomy_map() as $short => $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 100,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);
			$out[ $short ] = is_array( $terms ) ? $terms : array();
		}
		return $out;
	}
}
