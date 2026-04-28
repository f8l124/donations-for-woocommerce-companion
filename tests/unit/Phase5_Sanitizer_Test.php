<?php
/**
 * Unit tests for Phase 5 sanitization additions.
 *
 * Targets the public surface that admins drive: Templates_Page's interval
 * sanitizer (which mirrors Meta_Box's). Both share the same allow-list +
 * single-featured-per-interval enforcement.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Admin\Templates_Page;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Template_Config;
use DFWC\Companion\Config\Template_Repository;
use PHPUnit\Framework\TestCase;

final class Phase5_Sanitizer_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_defaults_includes_phase_5_per_interval_fields(): void {
		$block = Defaults::interval_block();

		$this->assertArrayHasKey( 'subtitle', $block );
		$this->assertArrayHasKey( 'annual_equivalency', $block );
		$this->assertArrayHasKey( 'impact_display_mode', $block );
		$this->assertArrayHasKey( 'custom_amount_impact_label', $block );

		$this->assertSame( '', $block['subtitle'] );
		$this->assertSame( '', $block['annual_equivalency'] );
		$this->assertSame( 'below_button', $block['impact_display_mode'] );
		$this->assertSame( '', $block['custom_amount_impact_label'] );
	}

	public function test_impact_display_modes_returns_four_modes(): void {
		$modes = Defaults::impact_display_modes();

		$this->assertCount( 4, $modes );
		$this->assertContains( 'inline', $modes );
		$this->assertContains( 'below_button', $modes );
		$this->assertContains( 'tooltip', $modes );
		$this->assertContains( 'card', $modes );
	}

	public function test_template_save_round_trip_preserves_phase_5_fields(): void {
		$config = Defaults::for_campaign();
		$config['monthly']['enabled']                   = true;
		$config['monthly']['subtitle']                  = 'Become a monthly sponsor';
		$config['monthly']['annual_equivalency']        = '{amount}/month equals {annual_amount}/year';
		$config['monthly']['impact_display_mode']       = 'card';
		$config['monthly']['custom_amount_impact_label'] = 'Every gift makes a difference';
		$config['monthly']['presets'][0]['impact_label'] = 'Provides school supplies';
		$config['monthly']['presets'][0]['is_featured']  = true;

		$repo = new Template_Repository();
		$repo->save( new Template_Config( 'phase5', 'Phase 5', '', time(), time(), $config ) );

		$loaded = $repo->get( 'phase5' );

		$this->assertNotNull( $loaded );
		$this->assertSame( 'Become a monthly sponsor', $loaded->config['monthly']['subtitle'] );
		$this->assertSame( '{amount}/month equals {annual_amount}/year', $loaded->config['monthly']['annual_equivalency'] );
		$this->assertSame( 'card', $loaded->config['monthly']['impact_display_mode'] );
		$this->assertSame( 'Every gift makes a difference', $loaded->config['monthly']['custom_amount_impact_label'] );
		$this->assertSame( 'Provides school supplies', $loaded->config['monthly']['presets'][0]['impact_label'] );
		$this->assertTrue( $loaded->config['monthly']['presets'][0]['is_featured'] );
	}

	public function test_resolved_config_through_template_includes_phase_5_fields(): void {
		$config = Defaults::for_campaign();
		$config['monthly']['enabled']                   = true;
		$config['monthly']['subtitle']                  = 'Sponsor a child monthly';
		$config['monthly']['custom_amount_impact_label'] = 'Each $1 provides one meal';
		( new Template_Repository() )->save( new Template_Config( 'foo', 'Foo', '', time(), time(), $config ) );
		( new \DFWC\Companion\Config\Campaign_Config_Repository() )->apply_template( 42, 'foo' );

		$resolved = \DFWC\Companion\Config\Config_Resolver::resolve( 42 );

		$this->assertSame( 'Sponsor a child monthly', $resolved['monthly']['subtitle'] );
		$this->assertSame( 'Each $1 provides one meal', $resolved['monthly']['custom_amount_impact_label'] );
	}

	public function test_invalid_impact_display_mode_falls_back_to_below_button(): void {
		// Save with an invalid mode; resolver should clamp to default.
		$config                         = Defaults::for_campaign();
		$config['monthly']['enabled']   = true;
		$config['monthly']['impact_display_mode'] = 'wat';

		// Validate-and-clamp doesn't enforce the allow-list at resolve time
		// (only at save time). But save-time sanitizer rejects invalid modes;
		// stored data is always valid. This test verifies that path: simulate
		// admin save by passing invalid mode through Templates_Page sanitizer.
		// (We exercise that via the wrapper helper below.)
		$tpl = $this->build_template_via_save( $config );

		$this->assertSame( 'below_button', $tpl->config['monthly']['impact_display_mode'] );
	}

	public function test_single_featured_per_interval_enforced_on_save(): void {
		$config                                          = Defaults::for_campaign();
		$config['monthly']['enabled']                    = true;
		$config['monthly']['presets'][0]['is_featured']  = true;
		$config['monthly']['presets'][1]['is_featured']  = true;
		$config['monthly']['presets'][2]['is_featured']  = true;

		$tpl = $this->build_template_via_save( $config );

		// First preset stays featured; subsequent featured flags get unset.
		$featured_count = 0;
		foreach ( $tpl->config['monthly']['presets'] as $p ) {
			if ( ! empty( $p['is_featured'] ) ) {
				++$featured_count;
			}
		}
		$this->assertSame( 1, $featured_count );
	}

	/**
	 * Drive the Templates_Page sanitizer via reflection (it's private). This
	 * lets us exercise it without a full wp_handle_post round trip.
	 *
	 * @param array<string,mixed> $config
	 */
	private function build_template_via_save( array $config ): Template_Config {
		$page = new Templates_Page();

		// Mimic the form-submit shape: nested arrays under template[config].
		$ref    = new \ReflectionClass( $page );
		$method = $ref->getMethod( 'sanitize_config_from_post' );
		$method->setAccessible( true );
		$sanitized = $method->invoke( $page, $config );

		return new Template_Config( 'sanitized', 'Sanitized', '', time(), time(), $sanitized );
	}
}
