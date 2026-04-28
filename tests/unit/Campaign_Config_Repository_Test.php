<?php
/**
 * Unit tests for Config\Campaign_Config_Repository.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Campaign_Config_Repository;
use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Template_Config;
use DFWC\Companion\Config\Template_Repository;
use PHPUnit\Framework\TestCase;

final class Campaign_Config_Repository_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_get_template_id_returns_empty_when_unset(): void {
		$repo = new Campaign_Config_Repository();
		$this->assertSame( '', $repo->get_template_id( 42 ) );
	}

	public function test_set_template_id_persists(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_template_id( 42, 'school_sponsorship' );
		$this->assertSame( 'school_sponsorship', $repo->get_template_id( 42 ) );
	}

	public function test_set_template_id_empty_string_clears(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_template_id( 42, 'school_sponsorship' );
		$repo->set_template_id( 42, '' );
		$this->assertSame( '', $repo->get_template_id( 42 ) );
	}

	public function test_set_template_id_clears_detached_flag(): void {
		$repo = new Campaign_Config_Repository();
		update_post_meta( 42, Config_Resolver::META_KEY_DETACHED, true );

		$repo->set_template_id( 42, 'foo' );

		$this->assertFalse( $repo->is_detached( 42 ) );
	}

	public function test_get_overrides_returns_array(): void {
		$repo = new Campaign_Config_Repository();
		$this->assertSame( array(), $repo->get_overrides( 42 ) );

		$repo->set_overrides( 42, array( 'monthly' => array( 'enabled' => true ) ) );
		$this->assertSame( array( 'monthly' => array( 'enabled' => true ) ), $repo->get_overrides( 42 ) );
	}

	public function test_set_overrides_empty_clears(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_overrides( 42, array( 'monthly' => array( 'enabled' => true ) ) );
		$repo->set_overrides( 42, array() );

		$this->assertSame( array(), $repo->get_overrides( 42 ) );
	}

	public function test_apply_template_persists_template_id_and_clears_overrides(): void {
		$tpl_repo = new Template_Repository();
		$tpl_repo->save( new Template_Config( 'foo', 'Foo', '', time(), time(), Defaults::for_campaign() ) );

		$repo = new Campaign_Config_Repository();
		$repo->set_overrides( 42, array( 'monthly' => array( 'enabled' => true ) ) );

		$this->assertTrue( $repo->apply_template( 42, 'foo' ) );
		$this->assertSame( 'foo', $repo->get_template_id( 42 ) );
		$this->assertSame( array(), $repo->get_overrides( 42 ) );
	}

	public function test_apply_template_returns_false_for_missing_template(): void {
		$repo = new Campaign_Config_Repository();
		$this->assertFalse( $repo->apply_template( 42, 'nonexistent' ) );
		$this->assertSame( '', $repo->get_template_id( 42 ) );
	}

	public function test_reset_to_template_clears_overrides_and_detached(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_overrides( 42, array( 'monthly' => array( 'enabled' => true ) ) );
		update_post_meta( 42, Config_Resolver::META_KEY_DETACHED, true );

		$repo->reset_to_template( 42 );

		$this->assertSame( array(), $repo->get_overrides( 42 ) );
		$this->assertFalse( $repo->is_detached( 42 ) );
	}

	public function test_reset_to_defaults_clears_everything(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_template_id( 42, 'foo' );
		$repo->set_overrides( 42, array( 'monthly' => array() ) );
		update_post_meta( 42, Config_Resolver::META_KEY_DETACHED, true );
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array( 'legacy' => true ) );
		update_post_meta( 42, Config_Resolver::META_KEY_DISPLAY, array( 'show_title' => false ) );

		$repo->reset_to_defaults( 42 );

		$this->assertSame( '', $repo->get_template_id( 42 ) );
		$this->assertSame( array(), $repo->get_overrides( 42 ) );
		$this->assertFalse( $repo->is_detached( 42 ) );
		$this->assertSame( '', get_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, true ) );
		$this->assertSame( '', get_post_meta( 42, Config_Resolver::META_KEY_DISPLAY, true ) );
	}

	public function test_detach_from_template_freezes_resolved_values(): void {
		$tpl_repo = new Template_Repository();
		$config   = Defaults::for_campaign();
		$config['monthly']['enabled']             = true;
		$config['monthly']['presets'][0]['amount'] = 25.0;
		$tpl_repo->save( new Template_Config( 'foo', 'Foo', '', time(), time(), $config ) );

		$campaign_repo = new Campaign_Config_Repository();
		$campaign_repo->apply_template( 42, 'foo' );

		// Now detach.
		$campaign_repo->detach_from_template( 42 );

		// Verify state.
		$this->assertSame( '', $campaign_repo->get_template_id( 42 ) );
		$this->assertTrue( $campaign_repo->is_detached( 42 ) );

		$frozen = $campaign_repo->get_overrides( 42 );
		$this->assertTrue( $frozen['monthly']['enabled'] );
		$this->assertSame( 25.0, $frozen['monthly']['presets'][0]['amount'] );
	}

	public function test_compute_override_delta_returns_only_diffs(): void {
		$repo = new Campaign_Config_Repository();

		$baseline = array(
			'monthly' => array(
				'enabled'       => false,
				'cta_template'  => 'Donate {amount}/month',
				'min'           => 5.0,
			),
			'one_time' => array(
				'enabled'       => true,
				'cta_template'  => 'Donate {amount}',
			),
		);

		$submitted = array(
			'monthly' => array(
				'enabled'       => true,                   // changed
				'cta_template'  => 'Donate {amount}/month', // unchanged
				'min'           => 5.0,                    // unchanged
			),
			'one_time' => array(
				'enabled'       => true,                   // unchanged
				'cta_template'  => 'Donate {amount}',      // unchanged
			),
		);

		$delta = $repo->compute_override_delta( $baseline, $submitted );

		$this->assertArrayHasKey( 'monthly', $delta );
		$this->assertArrayNotHasKey( 'one_time', $delta );
		$this->assertSame( array( 'enabled' => true ), $delta['monthly'] );
	}

	public function test_compute_override_delta_replaces_sequential_arrays_wholesale(): void {
		$repo = new Campaign_Config_Repository();

		$baseline = array(
			'monthly' => array(
				'presets' => array(
					array( 'amount' => 25.0 ),
					array( 'amount' => 50.0 ),
				),
			),
		);

		$submitted = array(
			'monthly' => array(
				'presets' => array(
					array( 'amount' => 30.0 ),  // any change ⇒ whole array replaces
					array( 'amount' => 50.0 ),
				),
			),
		);

		$delta = $repo->compute_override_delta( $baseline, $submitted );

		$this->assertArrayHasKey( 'monthly', $delta );
		$this->assertCount( 2, $delta['monthly']['presets'] );
		$this->assertSame( 30.0, $delta['monthly']['presets'][0]['amount'] );
		$this->assertSame( 50.0, $delta['monthly']['presets'][1]['amount'] );
	}

	public function test_compute_override_delta_empty_when_identical(): void {
		$repo = new Campaign_Config_Repository();

		$baseline = array(
			'monthly' => array(
				'enabled' => false,
				'min'     => 5.0,
				'presets' => array( array( 'amount' => 25.0 ) ),
			),
		);

		$delta = $repo->compute_override_delta( $baseline, $baseline );

		$this->assertSame( array(), $delta );
	}

	public function test_migrate_legacy_to_overrides_is_idempotent(): void {
		$repo = new Campaign_Config_Repository();

		// No legacy meta → no-op.
		$repo->migrate_legacy_to_overrides_if_needed( 42 );
		$this->assertSame( array(), $repo->get_overrides( 42 ) );

		// Set legacy + display meta.
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array(
			'monthly' => array( 'enabled' => true, 'presets' => array( array( 'amount' => 25 ) ) ),
		) );
		update_post_meta( 42, Config_Resolver::META_KEY_DISPLAY, array( 'show_title' => false ) );

		$repo->migrate_legacy_to_overrides_if_needed( 42 );

		// Legacy keys cleared.
		$this->assertSame( '', get_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, true ) );
		$this->assertSame( '', get_post_meta( 42, Config_Resolver::META_KEY_DISPLAY, true ) );

		// Overrides populated.
		$overrides = $repo->get_overrides( 42 );
		$this->assertTrue( $overrides['monthly']['enabled'] );
		$this->assertFalse( $overrides['display']['show_title'] );

		// Re-running is a no-op.
		$repo->migrate_legacy_to_overrides_if_needed( 42 );
		$this->assertSame( $overrides, $repo->get_overrides( 42 ) );
	}

	public function test_migrate_skips_when_overrides_already_set(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_overrides( 42, array( 'monthly' => array( 'enabled' => true ) ) );
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array( 'monthly' => array( 'enabled' => false ) ) );

		$repo->migrate_legacy_to_overrides_if_needed( 42 );

		// Existing overrides preserved; legacy meta NOT touched.
		$this->assertTrue( $repo->get_overrides( 42 )['monthly']['enabled'] );
		$this->assertSame( false, get_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, true )['monthly']['enabled'] );
	}

	public function test_migrate_skips_when_template_already_set(): void {
		$repo = new Campaign_Config_Repository();
		$repo->set_template_id( 42, 'foo' );
		update_post_meta( 42, Config_Resolver::META_KEY_INTERVALS, array( 'monthly' => array( 'enabled' => true ) ) );

		$repo->migrate_legacy_to_overrides_if_needed( 42 );

		// Legacy NOT migrated because template assignment indicates v0.7.0+ admin choice.
		$this->assertSame( array(), $repo->get_overrides( 42 ) );
	}
}
