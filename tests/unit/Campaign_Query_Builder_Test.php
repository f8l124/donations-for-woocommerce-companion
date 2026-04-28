<?php
/**
 * Unit tests for Taxonomy\Campaign_Query_Builder.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Taxonomy\Campaign_Query_Builder;
use DFWC\Companion\Taxonomy\Campaign_Taxonomies;
use PHPUnit\Framework\TestCase;

final class Campaign_Query_Builder_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_default_args_with_no_filters(): void {
		$args = ( new Campaign_Query_Builder() )->build( array() );

		$this->assertSame( 'wc-donation', $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertSame( 12, $args['posts_per_page'] );
		$this->assertSame( 1, $args['paged'] );
		$this->assertSame( 'menu_order', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertArrayNotHasKey( 'tax_query', $args );
		$this->assertArrayNotHasKey( 'meta_query', $args );
	}

	public function test_per_page_clamped_to_max(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'per_page' => 999 ) );
		$this->assertSame( 50, $args['posts_per_page'] );
	}

	public function test_per_page_clamped_to_min(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'per_page' => -5 ) );
		$this->assertSame( 12, $args['posts_per_page'] ); // falls back to default
	}

	public function test_page_clamped(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'page' => 0 ) );
		$this->assertSame( 1, $args['paged'] );

		$args = ( new Campaign_Query_Builder() )->build( array( 'page' => 5000 ) );
		$this->assertSame( 1000, $args['paged'] );
	}

	public function test_orderby_falls_back_for_unknown_value(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'orderby' => 'invalid_value' ) );
		$this->assertSame( 'menu_order', $args['orderby'] );
	}

	public function test_order_falls_back_for_unknown_value(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'order' => 'sideways' ) );
		$this->assertSame( 'ASC', $args['order'] );
	}

	public function test_orderby_featured_uses_meta_value_num(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'orderby' => 'featured' ) );

		$this->assertIsArray( $args['orderby'] );
		$this->assertSame( 'DESC', $args['orderby']['meta_value_num'] );
		$this->assertArrayHasKey( 'meta_key', $args );
		$this->assertSame( Campaign_Taxonomies::META_KEY_FEATURED, $args['meta_key'] );
	}

	public function test_single_taxonomy_filter_produces_tax_query(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'cause' => 'education' ) );

		$this->assertArrayHasKey( 'tax_query', $args );
		$this->assertSame( 'AND', $args['tax_query']['relation'] );
		$this->assertSame( Campaign_Taxonomies::TAX_CAUSE, $args['tax_query'][0]['taxonomy'] );
		$this->assertSame( array( 'education' ), $args['tax_query'][0]['terms'] );
	}

	public function test_multiple_taxonomies_combined_with_AND(): void {
		$args = ( new Campaign_Query_Builder() )->build( array(
			'cause'   => 'education',
			'country' => 'kenya',
		) );

		$this->assertSame( 'AND', $args['tax_query']['relation'] );
		// Two clauses + relation = 3 elements.
		$this->assertCount( 3, $args['tax_query'] );
	}

	public function test_array_terms_for_one_taxonomy_uses_in_operator(): void {
		$args = ( new Campaign_Query_Builder() )->build( array(
			'cause' => array( 'education', 'medical' ),
		) );

		$this->assertSame( array( 'education', 'medical' ), $args['tax_query'][0]['terms'] );
		$this->assertSame( 'IN', $args['tax_query'][0]['operator'] );
	}

	public function test_empty_taxonomy_value_skipped(): void {
		$args = ( new Campaign_Query_Builder() )->build( array(
			'cause'   => '',
			'country' => 'kenya',
		) );

		$this->assertCount( 2, $args['tax_query'] ); // relation + 1 clause
		$this->assertSame( Campaign_Taxonomies::TAX_COUNTRY, $args['tax_query'][0]['taxonomy'] );
	}

	public function test_featured_only_adds_meta_query(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'featured' => true ) );

		$this->assertArrayHasKey( 'meta_query', $args );
		$this->assertSame( Campaign_Taxonomies::META_KEY_FEATURED, $args['meta_query'][0]['key'] );
		$this->assertSame( '1', $args['meta_query'][0]['value'] );
	}

	public function test_search_string_passed_through(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 's' => '  Maisha  ' ) );

		// sanitize_text_field stub just trims; real WP also strips slashes/tags.
		$this->assertSame( 'Maisha', $args['s'] );
	}

	public function test_lang_param_passed_when_set(): void {
		$args = ( new Campaign_Query_Builder() )->build( array( 'lang' => 'fr' ) );
		$this->assertSame( 'fr', $args['lang'] );
	}

	public function test_lang_param_omitted_when_not_set(): void {
		$args = ( new Campaign_Query_Builder() )->build( array() );
		$this->assertArrayNotHasKey( 'lang', $args );
	}

	public function test_filter_taxonomy_map_keys_match_canonical_taxonomies(): void {
		$map = Campaign_Query_Builder::filter_taxonomy_map();

		$this->assertArrayHasKey( 'cause', $map );
		$this->assertArrayHasKey( 'region', $map );
		$this->assertArrayHasKey( 'country', $map );
		$this->assertArrayHasKey( 'program', $map );
		$this->assertArrayHasKey( 'sponsorship_type', $map );
		$this->assertArrayHasKey( 'urgency', $map );

		$this->assertSame( Campaign_Taxonomies::TAX_CAUSE, $map['cause'] );
	}

	public function test_dfwc_companion_campaign_grid_query_args_filter_runs_last(): void {
		add_filter(
			'dfwc_companion_campaign_grid_query_args',
			static function ( $args ) {
				$args['filter_marker'] = 'modified';
				return $args;
			}
		);

		$args = ( new Campaign_Query_Builder() )->build( array() );

		$this->assertSame( 'modified', $args['filter_marker'] );
	}
}
