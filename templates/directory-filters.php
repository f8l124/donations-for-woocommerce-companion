<?php
/**
 * Directory filter bar.
 *
 * Variables in scope (from Campaign_Directory_Renderer::render):
 *   array<string,array<int,\WP_Term>>  $term_lists       Pre-loaded terms keyed by short name (cause, region, etc.)
 *   array<string,string>               $current_filters  Current filter selections
 *
 * Form GETs to the same page with `dfwc_*` prefixed query args. JS
 * progressively enhances with debounced search-as-you-type.
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

$tax_labels = array(
	'cause'             => __( 'Cause', 'dfwc-companion' ),
	'region'            => __( 'Region', 'dfwc-companion' ),
	'country'           => __( 'Country', 'dfwc-companion' ),
	'program'           => __( 'Program', 'dfwc-companion' ),
	'sponsorship_type'  => __( 'Sponsorship type', 'dfwc-companion' ),
	'urgency'           => __( 'Urgency', 'dfwc-companion' ),
);
?>
<form method="get" class="dfwc-directory__filters" data-dfwc-directory-filters>
	<?php
	// Preserve any non-companion query args on the page (e.g. parent's filters).
	foreach ( $_GET as $k => $v ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter form is read-only public input
		if ( 0 === strpos( (string) $k, 'dfwc_' ) ) {
			continue;
		}
		?>
		<input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( wp_unslash( is_array( $v ) ? '' : (string) $v ) ); ?>">
		<?php
	endforeach;
	?>

	<label class="dfwc-directory__filter dfwc-directory__filter--search">
		<span class="dfwc-directory__filter-label"><?php esc_html_e( 'Search', 'dfwc-companion' ); ?></span>
		<input
			type="search"
			name="dfwc_s"
			value="<?php echo esc_attr( $current_filters['s'] ?? '' ); ?>"
			placeholder="<?php esc_attr_e( 'Search campaigns…', 'dfwc-companion' ); ?>"
			data-dfwc-directory-search
		>
	</label>

	<?php
	foreach ( $tax_labels as $short => $label ) :
		$terms = $term_lists[ $short ] ?? array();
		if ( empty( $terms ) ) {
			continue;
		}
		?>
		<label class="dfwc-directory__filter">
			<span class="dfwc-directory__filter-label"><?php echo esc_html( $label ); ?></span>
			<select name="dfwc_<?php echo esc_attr( $short ); ?>">
				<option value=""><?php esc_html_e( '— Any —', 'dfwc-companion' ); ?></option>
				<?php foreach ( $terms as $dfwc_term ) : ?>
					<option
						value="<?php echo esc_attr( $dfwc_term->slug ); ?>"
						<?php selected( $current_filters[ $short ] ?? '', $dfwc_term->slug ); ?>
					>
						<?php echo esc_html( $dfwc_term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	<?php endforeach; ?>

	<label class="dfwc-directory__filter dfwc-directory__filter--sort">
		<span class="dfwc-directory__filter-label"><?php esc_html_e( 'Sort', 'dfwc-companion' ); ?></span>
		<select name="dfwc_orderby">
			<option value="menu_order" <?php selected( $current_filters['orderby'] ?? '', 'menu_order' ); ?>>
				<?php esc_html_e( 'Manual order', 'dfwc-companion' ); ?>
			</option>
			<option value="featured" <?php selected( $current_filters['orderby'] ?? '', 'featured' ); ?>>
				<?php esc_html_e( 'Featured first', 'dfwc-companion' ); ?>
			</option>
			<option value="title" <?php selected( $current_filters['orderby'] ?? '', 'title' ); ?>>
				<?php esc_html_e( 'Title (A→Z)', 'dfwc-companion' ); ?>
			</option>
			<option value="date" <?php selected( $current_filters['orderby'] ?? '', 'date' ); ?>>
				<?php esc_html_e( 'Newest', 'dfwc-companion' ); ?>
			</option>
			<option value="rand" <?php selected( $current_filters['orderby'] ?? '', 'rand' ); ?>>
				<?php esc_html_e( 'Random', 'dfwc-companion' ); ?>
			</option>
		</select>
	</label>

	<button type="submit" class="dfwc-directory__submit button"><?php esc_html_e( 'Filter', 'dfwc-companion' ); ?></button>
</form>
