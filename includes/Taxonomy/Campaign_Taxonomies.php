<?php
/**
 * Campaign_Taxonomies — register the six campaign-classification taxonomies.
 *
 * Six taxonomies attached to the wc-donation post type:
 *   dfwc_cause              Cause Category    hierarchical
 *   dfwc_region             Region            hierarchical (continent → sub-region)
 *   dfwc_country            Country           flat
 *   dfwc_program            Program           flat
 *   dfwc_sponsorship_type   Sponsorship Type  flat
 *   dfwc_urgency            Urgency           flat
 *
 * Default terms seeded once via `dfwc_companion_terms_seeded` flag option.
 * Admins can edit, delete, or extend freely thereafter — re-activations
 * never re-seed because the flag stays set.
 *
 * WPML support: each taxonomy is declared `translate="1"` in wpml-config.xml,
 * so WPML's Translation Management handles per-term translation natively.
 *
 * Per-campaign featured flag: meta key `_dfwc_companion_featured` (boolean)
 * is owned by this class because featured-status drives the directory
 * grid's "featured first" sort. Meta_Box renders the toggle.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class Campaign_Taxonomies {

	public const TAX_CAUSE             = 'dfwc_cause';
	public const TAX_REGION            = 'dfwc_region';
	public const TAX_COUNTRY           = 'dfwc_country';
	public const TAX_PROGRAM           = 'dfwc_program';
	public const TAX_SPONSORSHIP_TYPE  = 'dfwc_sponsorship_type';
	public const TAX_URGENCY           = 'dfwc_urgency';

	public const META_KEY_FEATURED     = '_dfwc_companion_featured';
	public const SEEDED_OPTION         = 'dfwc_companion_terms_seeded';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'maybe_seed_terms' ), 6 );
	}

	/**
	 * Register all six taxonomies. Run on `init:5` (before parent plugin's
	 * default-priority hooks so the post type is taxonomy-aware from the start).
	 */
	public function register(): void {
		// Defensive: if the parent plugin hasn't registered wc-donation yet,
		// register_taxonomy fails silently. We tolerate this gracefully.
		register_taxonomy(
			self::TAX_CAUSE,
			'wc-donation',
			$this->build_args(
				array(
					'labels'       => $this->labels( __( 'Cause Category', 'dfwc-companion' ), __( 'Cause Categories', 'dfwc-companion' ) ),
					'hierarchical' => true,
				)
			)
		);
		register_taxonomy(
			self::TAX_REGION,
			'wc-donation',
			$this->build_args(
				array(
					'labels'       => $this->labels( __( 'Region', 'dfwc-companion' ), __( 'Regions', 'dfwc-companion' ) ),
					'hierarchical' => true,
				)
			)
		);
		register_taxonomy(
			self::TAX_COUNTRY,
			'wc-donation',
			$this->build_args(
				array( 'labels' => $this->labels( __( 'Country', 'dfwc-companion' ), __( 'Countries', 'dfwc-companion' ) ) )
			)
		);
		register_taxonomy(
			self::TAX_PROGRAM,
			'wc-donation',
			$this->build_args(
				array( 'labels' => $this->labels( __( 'Program', 'dfwc-companion' ), __( 'Programs', 'dfwc-companion' ) ) )
			)
		);
		register_taxonomy(
			self::TAX_SPONSORSHIP_TYPE,
			'wc-donation',
			$this->build_args(
				array( 'labels' => $this->labels( __( 'Sponsorship Type', 'dfwc-companion' ), __( 'Sponsorship Types', 'dfwc-companion' ) ) )
			)
		);
		register_taxonomy(
			self::TAX_URGENCY,
			'wc-donation',
			$this->build_args(
				array( 'labels' => $this->labels( __( 'Urgency', 'dfwc-companion' ), __( 'Urgency Levels', 'dfwc-companion' ) ) )
			)
		);

		/**
		 * Fires after companion taxonomies are registered. Useful for
		 * extensions that want to attach custom fields or metaboxes.
		 */
		do_action( 'dfwc_companion_taxonomies_registered' );
	}

	/**
	 * Seed default terms on first activation. Idempotent via the
	 * `dfwc_companion_terms_seeded` flag option.
	 */
	public function maybe_seed_terms(): void {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}

		$seed = array(
			self::TAX_CAUSE => array(
				__( 'Education', 'dfwc-companion' ),
				__( 'Discipleship', 'dfwc-companion' ),
				__( 'Medical', 'dfwc-companion' ),
				__( 'Food', 'dfwc-companion' ),
				__( 'Construction', 'dfwc-companion' ),
				__( 'Missions', 'dfwc-companion' ),
				__( 'Leadership Training', 'dfwc-companion' ),
			),
			self::TAX_SPONSORSHIP_TYPE => array(
				__( 'School', 'dfwc-companion' ),
				__( 'Classroom', 'dfwc-companion' ),
				__( 'Student', 'dfwc-companion' ),
				__( 'Pastor', 'dfwc-companion' ),
				__( 'Teacher', 'dfwc-companion' ),
				__( 'Church', 'dfwc-companion' ),
				__( 'Missionary', 'dfwc-companion' ),
			),
			self::TAX_URGENCY => array(
				__( 'Normal', 'dfwc-companion' ),
				__( 'Priority', 'dfwc-companion' ),
				__( 'Urgent', 'dfwc-companion' ),
			),
		);

		/**
		 * Allow third parties to override the seed list before insertion.
		 *
		 * @param array<string,array<int,string>> $seed
		 */
		$seed = (array) apply_filters( 'dfwc_companion_seeded_terms', $seed );

		foreach ( $seed as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( ! term_exists( $term, $taxonomy ) ) {
					wp_insert_term( $term, $taxonomy );
				}
			}
		}

		update_option( self::SEEDED_OPTION, time(), false );

		/**
		 * Fires after default terms are seeded. Useful for analytics or
		 * post-activation flows.
		 */
		do_action( 'dfwc_companion_terms_seeded' );
	}

	/**
	 * All companion taxonomy slugs in display order. Used by
	 * Campaign_Query_Builder + admin filters.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::TAX_CAUSE,
			self::TAX_SPONSORSHIP_TYPE,
			self::TAX_PROGRAM,
			self::TAX_REGION,
			self::TAX_COUNTRY,
			self::TAX_URGENCY,
		);
	}

	/**
	 * Build register_taxonomy() args. Filterable for power users via
	 * `dfwc_companion_taxonomy_args`.
	 *
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function build_args( array $overrides = array() ): array {
		$base = array(
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'rewrite'           => false,
		);

		$args = array_merge( $base, $overrides );

		/**
		 * Filter taxonomy args before registration. Power-user hook for
		 * sites that want to disable show_ui, change rewrite, etc.
		 *
		 * @param array<string,mixed> $args
		 */
		return (array) apply_filters( 'dfwc_companion_taxonomy_args', $args );
	}

	/**
	 * Build a labels array for register_taxonomy.
	 *
	 * @return array<string,string>
	 */
	private function labels( string $singular, string $plural ): array {
		return array(
			'name'              => $plural,
			'singular_name'     => $singular,
			'search_items'      => sprintf( /* translators: %s: plural taxonomy name */ __( 'Search %s', 'dfwc-companion' ), $plural ),
			'all_items'         => sprintf( /* translators: %s: plural taxonomy name */ __( 'All %s', 'dfwc-companion' ), $plural ),
			'parent_item'       => sprintf( /* translators: %s: singular taxonomy name */ __( 'Parent %s', 'dfwc-companion' ), $singular ),
			'parent_item_colon' => sprintf( /* translators: %s: singular taxonomy name */ __( 'Parent %s:', 'dfwc-companion' ), $singular ),
			'edit_item'         => sprintf( /* translators: %s: singular taxonomy name */ __( 'Edit %s', 'dfwc-companion' ), $singular ),
			'update_item'       => sprintf( /* translators: %s: singular taxonomy name */ __( 'Update %s', 'dfwc-companion' ), $singular ),
			'add_new_item'      => sprintf( /* translators: %s: singular taxonomy name */ __( 'Add New %s', 'dfwc-companion' ), $singular ),
			'new_item_name'     => sprintf( /* translators: %s: singular taxonomy name */ __( 'New %s Name', 'dfwc-companion' ), $singular ),
			'menu_name'         => $plural,
		);
	}
}
