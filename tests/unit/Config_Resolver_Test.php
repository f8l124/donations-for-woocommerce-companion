<?php
/**
 * Unit tests for Config_Resolver.
 *
 * Phase 1 ships a single test class against the existing v0.6.6 Config_Resolver
 * surface. Phase 3 expands this dramatically when the layered model lands —
 * see plans/v2/04-phase-3-templates-and-defaults.md §3.11.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config_Resolver;
use PHPUnit\Framework\TestCase;

final class Config_Resolver_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_resolve_returns_defaults_for_unconfigured_campaign(): void {
		$config = Config_Resolver::resolve( 1 );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'one_time', $config );
		$this->assertArrayHasKey( 'monthly', $config );
		$this->assertArrayHasKey( 'annual', $config );
		$this->assertTrue( $config['one_time']['enabled'] ?? false );
	}

	public function test_intervals_returns_three_keys(): void {
		$intervals = Config_Resolver::intervals();

		$this->assertCount( 3, $intervals );
		$this->assertSame( [ 'one_time', 'monthly', 'annual' ], $intervals );
	}

	public function test_is_configured_false_for_uninitialized_campaign(): void {
		$this->assertFalse( Config_Resolver::is_configured( 0 ) );
		$this->assertFalse( Config_Resolver::is_configured( 9999 ) );
	}

	public function test_is_configured_true_after_intervals_meta_set(): void {
		update_post_meta(
			42,
			Config_Resolver::META_KEY_INTERVALS,
			[
				'monthly' => [
					'enabled' => true,
					'presets' => [ [ 'amount' => 25.0 ] ],
				],
			]
		);

		$this->assertTrue( Config_Resolver::is_configured( 42 ) );
	}

	public function test_resolve_reads_persisted_intervals_meta(): void {
		update_post_meta(
			42,
			Config_Resolver::META_KEY_INTERVALS,
			[
				'monthly' => [
					'enabled'       => true,
					'presets'       => [ [ 'amount' => 50.0, 'label' => 'Sustainer' ] ],
					'default_index' => 0,
					'cta_template'  => 'Donate {amount}/month',
				],
			]
		);

		$config = Config_Resolver::resolve( 42 );

		$this->assertTrue( $config['monthly']['enabled'] );
		$this->assertSame( 50.0, $config['monthly']['presets'][0]['amount'] );
		$this->assertSame( 'Sustainer', $config['monthly']['presets'][0]['label'] );
	}

	public function test_resolve_display_returns_defaults_when_unset(): void {
		$display = Config_Resolver::resolve_display( 42 );

		$this->assertIsArray( $display );
		$this->assertArrayHasKey( 'show_title', $display );
		$this->assertArrayHasKey( 'show_image', $display );
		$this->assertArrayHasKey( 'cause_heading', $display );
		$this->assertTrue( $display['show_title'] );
		$this->assertTrue( $display['show_image'] );
	}

	public function test_resolve_display_persists_admin_choice(): void {
		update_post_meta(
			42,
			Config_Resolver::META_KEY_DISPLAY,
			[
				'show_title'    => false,
				'show_image'    => false,
				'cause_heading' => 'Choose your impact',
			]
		);

		$display = Config_Resolver::resolve_display( 42 );

		$this->assertFalse( $display['show_title'] );
		$this->assertFalse( $display['show_image'] );
		$this->assertSame( 'Choose your impact', $display['cause_heading'] );
	}
}
