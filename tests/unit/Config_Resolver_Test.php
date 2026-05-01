<?php
/**
 * Unit tests for Config\Config_Resolver (Phase 3 — layered model).
 *
 * Tests cover:
 * - Default-only resolution (no template, no overrides)
 * - Global settings layer
 * - Template layer
 * - Campaign override layer
 * - Detached state (template assigned but ignored)
 * - Legacy v0.6.x intervals/display fallback
 * - Deep-merge sequential-vs-associative semantics
 * - Validate-and-clamp (min/max, default_index, sort_order)
 * - is_configured semantics
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Campaign_Config_Repository;
use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Template_Config;
use DFWC\Companion\Config\Template_Repository;
use PHPUnit\Framework\TestCase;

final class Config_Resolver_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	// === Backward-compat (existing tests) ===

	public function test_resolve_returns_defaults_for_unconfigured_campaign(): void {
		$config = Config_Resolver::resolve( 1 );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'one_time', $config );
		$this->assertArrayHasKey( 'monthly', $config );
		$this->assertArrayHasKey( 'annual', $config );
		$this->assertTrue( $config['one_time']['enabled'] );
		$this->assertFalse( $config['monthly']['enabled'] );
	}

	public function test_intervals_returns_three_keys(): void {
		$this->assertSame(
			array( 'one_time', 'monthly', 'annual' ),
			Config_Resolver::intervals()
		);
	}

	public function test_is_configured_false_for_uninitialized_campaign(): void {
		$this->assertFalse( Config_Resolver::is_configured( 0 ) );
		$this->assertFalse( Config_Resolver::is_configured( 9999 ) );
	}

	public function test_is_configured_true_when_template_set(): void {
		update_post_meta( 42, Config_Resolver::META_KEY_TEMPLATE, 'foo' );
		$this->assertTrue( Config_Resolver::is_configured( 42 ) );
	}

	public function test_is_configured_true_when_overrides_set(): void {
		update_post_meta( 42, Config_Resolver::META_KEY_OVERRIDES, array( 'monthly' => array( 'enabled' => true ) ) );
		$this->assertTrue( Config_Resolver::is_configured( 42 ) );
	}

	public function test_is_configured_true_when_legacy_intervals_set(): void {
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array( 'monthly' => array( 'enabled' => true ) ) );
		$this->assertTrue( Config_Resolver::is_configured( 42 ) );
	}

	// === Layer 1: defaults ===

	public function test_default_layer_uses_defaults_for_campaign(): void {
		$config = Config_Resolver::resolve( 42 );
		$this->assertSame( Defaults::for_campaign()['one_time']['cta_template'], $config['one_time']['cta_template'] );
	}

	// === Layer 2: global settings ===

	public function test_global_settings_override_defaults(): void {
		Config_Resolver::update_global_settings( array(
			'monthly' => array_merge(
				Defaults::interval_block(),
				array( 'enabled' => true, 'cta_template' => 'Custom global CTA' )
			),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertTrue( $config['monthly']['enabled'] );
		$this->assertSame( 'Custom global CTA', $config['monthly']['cta_template'] );
	}

	public function test_get_global_settings_returns_defaults_when_unset(): void {
		$g = Config_Resolver::get_global_settings();
		$this->assertSame( '', $g['default_template_id'] );
		$this->assertTrue( $g['preserve_data_on_uninstall'] );
	}

	// === Layer 3: template ===

	public function test_template_layer_overrides_defaults(): void {
		$tpl_config = Defaults::for_campaign();
		$tpl_config['monthly']['enabled']      = true;
		$tpl_config['monthly']['cta_template'] = 'School Sponsorship CTA';
		$tpl_config['monthly']['presets']      = array(
			array( 'amount' => 25.0, 'label' => '', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 10 ),
			array( 'amount' => 50.0, 'label' => 'Sustainer', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 20 ),
		);

		( new Template_Repository() )->save(
			new Template_Config( 'school', 'School', '', time(), time(), $tpl_config )
		);

		( new Campaign_Config_Repository() )->apply_template( 42, 'school' );

		$config = Config_Resolver::resolve( 42 );

		$this->assertTrue( $config['monthly']['enabled'] );
		$this->assertSame( 'School Sponsorship CTA', $config['monthly']['cta_template'] );
		$this->assertSame( 'Sustainer', $config['monthly']['presets'][1]['label'] );
	}

	// === Layer 4: campaign overrides ===

	public function test_campaign_overrides_beat_template(): void {
		$tpl_config = Defaults::for_campaign();
		$tpl_config['monthly']['cta_template'] = 'Template CTA';
		( new Template_Repository() )->save(
			new Template_Config( 'foo', 'Foo', '', time(), time(), $tpl_config )
		);

		$repo = new Campaign_Config_Repository();
		$repo->apply_template( 42, 'foo' );
		$repo->set_overrides( 42, array(
			'monthly' => array( 'cta_template' => 'Campaign-Specific CTA' ),
		) );

		$config = Config_Resolver::resolve( 42 );
		$this->assertSame( 'Campaign-Specific CTA', $config['monthly']['cta_template'] );
	}

	public function test_campaign_overrides_with_no_template_use_defaults_as_baseline(): void {
		( new Campaign_Config_Repository() )->set_overrides( 42, array(
			'monthly' => array( 'enabled' => true, 'cta_template' => 'Just override' ),
		) );

		$config = Config_Resolver::resolve( 42 );
		$this->assertTrue( $config['monthly']['enabled'] );
		$this->assertSame( 'Just override', $config['monthly']['cta_template'] );
	}

	// === Detached campaign ===

	public function test_detached_campaign_ignores_template_changes(): void {
		// 1. Create + apply template.
		$tpl_config = Defaults::for_campaign();
		$tpl_config['monthly']['enabled'] = true;
		$tpl_config['monthly']['presets'] = array(
			array( 'amount' => 25.0, 'label' => '', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 10 ),
		);
		$tpl_repo = new Template_Repository();
		$tpl_repo->save( new Template_Config( 'foo', 'Foo', '', time(), time(), $tpl_config ) );

		$campaign_repo = new Campaign_Config_Repository();
		$campaign_repo->apply_template( 42, 'foo' );

		// 2. Detach.
		$campaign_repo->detach_from_template( 42 );

		// 3. Change template — campaign should NOT pick up the change.
		$tpl_config['monthly']['presets'][0]['amount'] = 999.0;
		$tpl_repo->save( new Template_Config( 'foo', 'Foo', '', time(), time(), $tpl_config ) );

		$config = Config_Resolver::resolve( 42 );

		// Campaign retains the value frozen at detach time, not the new template value.
		$this->assertSame( 25.0, $config['monthly']['presets'][0]['amount'] );
	}

	// === Legacy v0.6.x fallback ===

	public function test_legacy_v0_6_intervals_meta_resolves_correctly(): void {
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array(
			'monthly' => array(
				'enabled'      => true,
				'cta_template' => 'Legacy CTA',
				'presets'      => array(
					array( 'amount' => 50.0, 'label' => 'Sustainer' ),
				),
				'min'          => 10.0,
				'max'          => 5000.0,
			),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertTrue( $config['monthly']['enabled'] );
		$this->assertSame( 'Legacy CTA', $config['monthly']['cta_template'] );
		$this->assertSame( 50.0, $config['monthly']['presets'][0]['amount'] );
		$this->assertSame( 'Sustainer', $config['monthly']['presets'][0]['label'] );
	}

	public function test_legacy_v0_6_skipped_when_template_assigned(): void {
		// v0.7.0+ admin assigning a template indicates a deliberate choice;
		// stale legacy meta should NOT shadow the template's values.
		$tpl_config = Defaults::for_campaign();
		$tpl_config['monthly']['enabled']      = true;
		$tpl_config['monthly']['cta_template'] = 'Template CTA';
		( new Template_Repository() )->save(
			new Template_Config( 'foo', 'Foo', '', time(), time(), $tpl_config )
		);

		( new Campaign_Config_Repository() )->apply_template( 42, 'foo' );
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array(
			'monthly' => array( 'cta_template' => 'STALE LEGACY' ),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertSame( 'Template CTA', $config['monthly']['cta_template'] );
	}

	public function test_legacy_v0_6_display_meta_resolves(): void {
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array(
			'monthly' => array( 'enabled' => true ),
		) );
		update_post_meta( 42, Config_Resolver::META_KEY_DISPLAY, array(
			'show_title' => false,
			'show_image' => false,
			'cause_heading' => 'Choose your impact',
		) );

		$display = Config_Resolver::resolve_display( 42 );

		$this->assertFalse( $display['show_title'] );
		$this->assertFalse( $display['show_image'] );
		$this->assertSame( 'Choose your impact', $display['cause_heading'] );
	}

	// === Validate-and-clamp ===

	public function test_resolve_clamps_min_to_at_least_one_cent(): void {
		( new Campaign_Config_Repository() )->set_overrides( 42, array(
			'monthly' => array( 'min' => -5.0 ),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertSame( 0.01, $config['monthly']['min'] );
	}

	public function test_resolve_clamps_max_to_at_least_min(): void {
		( new Campaign_Config_Repository() )->set_overrides( 42, array(
			'monthly' => array( 'min' => 100.0, 'max' => 10.0 ),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertSame( 100.0, $config['monthly']['max'] );
	}

	public function test_resolve_sorts_presets_by_sort_order(): void {
		( new Campaign_Config_Repository() )->set_overrides( 42, array(
			'monthly' => array(
				'presets' => array(
					array( 'amount' => 30.0, 'label' => 'C', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 30 ),
					array( 'amount' => 10.0, 'label' => 'A', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 10 ),
					array( 'amount' => 20.0, 'label' => 'B', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 20 ),
				),
			),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertSame( 'A', $config['monthly']['presets'][0]['label'] );
		$this->assertSame( 'B', $config['monthly']['presets'][1]['label'] );
		$this->assertSame( 'C', $config['monthly']['presets'][2]['label'] );
	}

	public function test_resolve_clamps_default_index_to_preset_range(): void {
		( new Campaign_Config_Repository() )->set_overrides( 42, array(
			'monthly' => array(
				'default_index' => 99,
				'presets'       => array(
					array( 'amount' => 25.0, 'label' => '', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 10 ),
					array( 'amount' => 50.0, 'label' => '', 'impact_label' => '', 'is_featured' => false, 'sort_order' => 20 ),
				),
			),
		) );

		$config = Config_Resolver::resolve( 42 );

		$this->assertSame( 1, $config['monthly']['default_index'] );
	}

	// === Resolved display ===

	public function test_resolve_display_returns_defaults_when_unset(): void {
		$display = Config_Resolver::resolve_display( 42 );
		$this->assertTrue( $display['show_title'] );
		// v2.2.1: show_image defaults OFF.
		$this->assertFalse( $display['show_image'] );
		$this->assertSame( '', $display['cause_heading'] );
	}

	// === Filter ===

	public function test_dfwc_companion_resolved_config_filter_runs_last(): void {
		add_filter(
			'dfwc_companion_resolved_config',
			static function ( $config, $campaign_id ) {
				$config['filter_marker'] = 'modified-by-filter';
				return $config;
			},
			10,
			2
		);

		$config = Config_Resolver::resolve( 42 );

		$this->assertSame( 'modified-by-filter', $config['filter_marker'] );
	}

	// === trace_inheritance ===

	public function test_trace_inheritance_records_layer_per_path(): void {
		$tpl_config = Defaults::for_campaign();
		$tpl_config['monthly']['cta_template'] = 'Template CTA';
		( new Template_Repository() )->save(
			new Template_Config( 'foo', 'Foo', '', time(), time(), $tpl_config )
		);

		$repo = new Campaign_Config_Repository();
		$repo->apply_template( 42, 'foo' );
		$repo->set_overrides( 42, array(
			'one_time' => array( 'cta_template' => 'Campaign One-Time' ),
		) );

		$trace = Config_Resolver::trace_inheritance( 42 );

		$this->assertSame( 'campaign_override', $trace['one_time.cta_template'] );
		$this->assertSame( 'template:foo', $trace['monthly.cta_template'] );
	}
}
