<?php
/**
 * Unit tests for Validation\Template_Validator.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Validation\Template_Validator;
use PHPUnit\Framework\TestCase;

final class Template_Validator_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_sanitize_returns_full_canonical_shape(): void {
		$out = ( new Template_Validator() )->sanitize( array() );

		$this->assertArrayHasKey( 'default_interval', $out );
		$this->assertArrayHasKey( 'one_time', $out );
		$this->assertArrayHasKey( 'monthly', $out );
		$this->assertArrayHasKey( 'annual', $out );
		$this->assertArrayHasKey( 'display', $out );

		$this->assertSame( 'one_time', $out['default_interval'] );
	}

	public function test_default_interval_allow_listed(): void {
		$out = ( new Template_Validator() )->sanitize( array( 'default_interval' => 'invalid' ) );
		$this->assertSame( 'one_time', $out['default_interval'] );

		$out = ( new Template_Validator() )->sanitize( array( 'default_interval' => 'monthly' ) );
		$this->assertSame( 'monthly', $out['default_interval'] );
	}

	public function test_invalid_impact_display_mode_clamps_to_default(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array( 'impact_display_mode' => 'wat' ),
		) );

		$this->assertSame( 'below_button', $out['monthly']['impact_display_mode'] );
	}

	public function test_valid_impact_display_modes_pass_through(): void {
		foreach ( Defaults::impact_display_modes() as $mode ) {
			$out = ( new Template_Validator() )->sanitize( array(
				'monthly' => array( 'impact_display_mode' => $mode ),
			) );
			$this->assertSame( $mode, $out['monthly']['impact_display_mode'] );
		}
	}

	public function test_min_clamped_to_minimum_one_cent(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array( 'min' => -5 ),
		) );

		$this->assertSame( 0.01, $out['monthly']['min'] );
	}

	public function test_max_clamped_to_at_least_min(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array( 'min' => 100, 'max' => 10 ),
		) );

		$this->assertSame( 100.0, $out['monthly']['max'] );
	}

	public function test_presets_with_invalid_amounts_dropped(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array(
				'presets' => array(
					array( 'amount' => 25 ),
					array( 'amount' => 0 ),       // dropped
					array( 'amount' => -10 ),     // dropped
					array( 'amount' => 'abc' ),   // dropped (parses to 0)
					array( 'amount' => 50 ),
				),
			),
		) );

		$this->assertCount( 2, $out['monthly']['presets'] );
		$this->assertSame( 25.0, $out['monthly']['presets'][0]['amount'] );
		$this->assertSame( 50.0, $out['monthly']['presets'][1]['amount'] );
	}

	public function test_default_index_clamped_to_preset_range(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array(
				'presets'       => array(
					array( 'amount' => 25 ),
					array( 'amount' => 50 ),
				),
				'default_index' => 99,
			),
		) );

		$this->assertSame( 1, $out['monthly']['default_index'] );
	}

	public function test_single_featured_per_interval_enforced(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array(
				'presets' => array(
					array( 'amount' => 25, 'is_featured' => true ),
					array( 'amount' => 50, 'is_featured' => true ),
					array( 'amount' => 100, 'is_featured' => true ),
				),
			),
		) );

		$count = 0;
		foreach ( $out['monthly']['presets'] as $p ) {
			if ( $p['is_featured'] ) { ++$count; }
		}
		$this->assertSame( 1, $count );
	}

	public function test_phase_5_per_interval_fields_preserved(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array(
				'subtitle'                   => '  Become a sponsor  ',
				'annual_equivalency'         => '{amount}/month = {annual_amount}/year',
				'custom_amount_impact_label' => 'Every gift makes a difference',
			),
		) );

		// sanitize_text_field stub trims whitespace.
		$this->assertSame( 'Become a sponsor', $out['monthly']['subtitle'] );
		$this->assertSame( '{amount}/month = {annual_amount}/year', $out['monthly']['annual_equivalency'] );
		$this->assertSame( 'Every gift makes a difference', $out['monthly']['custom_amount_impact_label'] );
	}

	public function test_display_booleans_coerced_via_empty(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'display' => array( 'show_title' => '1', 'show_image' => '' ),
		) );

		$this->assertTrue( $out['display']['show_title'] );
		$this->assertFalse( $out['display']['show_image'] );
		$this->assertSame( '', $out['display']['cause_heading'] );
	}

	public function test_unknown_top_level_keys_dropped(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly'      => array( 'enabled' => true ),
			'foo'          => 'bar',
			'eval'         => 'evil',
		) );

		$this->assertArrayNotHasKey( 'foo', $out );
		$this->assertArrayNotHasKey( 'eval', $out );
		$this->assertTrue( $out['monthly']['enabled'] );
	}

	public function test_preset_sort_order_falls_back_to_indexed_default(): void {
		$out = ( new Template_Validator() )->sanitize( array(
			'monthly' => array(
				'presets' => array(
					array( 'amount' => 25 ),
					array( 'amount' => 50 ),
				),
			),
		) );

		// First preset gets sort_order = 10, second gets 20.
		$this->assertSame( 10, $out['monthly']['presets'][0]['sort_order'] );
		$this->assertSame( 20, $out['monthly']['presets'][1]['sort_order'] );
	}
}
