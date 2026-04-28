<?php
/**
 * Unit tests for Config\Defaults factory.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class Defaults_Test extends TestCase {

	public function test_for_campaign_returns_full_skeleton(): void {
		$d = Defaults::for_campaign();

		$this->assertSame( 'one_time', $d['default_interval'] );
		$this->assertArrayHasKey( 'one_time', $d );
		$this->assertArrayHasKey( 'monthly', $d );
		$this->assertArrayHasKey( 'annual', $d );
		$this->assertArrayHasKey( 'display', $d );

		$this->assertTrue( $d['one_time']['enabled'] );
		$this->assertFalse( $d['monthly']['enabled'] );
		$this->assertFalse( $d['annual']['enabled'] );
	}

	public function test_for_global_extends_for_campaign_with_plugin_options(): void {
		$g = Defaults::for_global();

		// Inherits campaign keys.
		$this->assertArrayHasKey( 'one_time', $g );
		$this->assertArrayHasKey( 'display', $g );

		// Adds global-only keys.
		$this->assertArrayHasKey( 'version', $g );
		$this->assertSame( 1, $g['version'] );
		$this->assertArrayHasKey( 'default_template_id', $g );
		$this->assertSame( '', $g['default_template_id'] );
		$this->assertTrue( $g['preserve_data_on_uninstall'] );
		$this->assertFalse( $g['enable_advanced_intervals'] );
	}

	public function test_interval_block_default_disabled(): void {
		$b = Defaults::interval_block();

		$this->assertFalse( $b['enabled'] );
		$this->assertSame( '', $b['cta_template'] );
		$this->assertCount( 3, $b['presets'] );
		$this->assertSame( 25.0, $b['presets'][0]['amount'] );
		$this->assertSame( 50.0, $b['presets'][1]['amount'] );
		$this->assertSame( 100.0, $b['presets'][2]['amount'] );
	}

	public function test_interval_block_can_be_enabled_with_cta(): void {
		$b = Defaults::interval_block( true, 'Donate {amount}/month' );

		$this->assertTrue( $b['enabled'] );
		$this->assertSame( 'Donate {amount}/month', $b['cta_template'] );
	}

	public function test_interval_block_includes_phase_5_fields(): void {
		$b = Defaults::interval_block();

		// Phase 5 schema fields are present from Phase 3 forward (storage shape stable).
		foreach ( $b['presets'] as $preset ) {
			$this->assertArrayHasKey( 'impact_label', $preset );
			$this->assertArrayHasKey( 'is_featured', $preset );
			$this->assertArrayHasKey( 'sort_order', $preset );
			$this->assertSame( '', $preset['impact_label'] );
			$this->assertFalse( $preset['is_featured'] );
		}
	}

	public function test_interval_block_includes_min_max_default_index(): void {
		$b = Defaults::interval_block();

		$this->assertSame( 5.0, $b['min'] );
		$this->assertSame( 10000.0, $b['max'] );
		$this->assertSame( 1, $b['default_index'] );
		$this->assertTrue( $b['custom_amount_enabled'] );
	}

	public function test_display_returns_show_title_show_image_cause_heading(): void {
		$d = Defaults::display();

		$this->assertTrue( $d['show_title'] );
		$this->assertTrue( $d['show_image'] );
		$this->assertSame( '', $d['cause_heading'] );
	}

	public function test_intervals_returns_canonical_list(): void {
		$this->assertSame( array( 'one_time', 'monthly', 'annual' ), Defaults::intervals() );
	}
}
