<?php
/**
 * Persistence-bug reproduction.
 *
 * User reports: configure a campaign with 12 presets + monthly + annual
 * enabled, save, then reload — all fields revert to defaults.
 *
 * This test simulates what the meta-box save handler does and asserts
 * that the resulting overrides round-trip correctly through the resolver.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Campaign_Config_Repository;
use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class Persistence_Repro_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_user_scenario_round_trip(): void {
		$repo = new Campaign_Config_Repository();

		// Build the user's submitted_config: 12 presets on monthly,
		// monthly enabled, annual enabled.
		$twelve_presets = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$twelve_presets[] = array(
				'amount'       => (float) ( 10 + $i * 5 ),
				'label'        => '',
				'impact_label' => '',
				'is_featured'  => false,
				'sort_order'   => ( $i + 1 ) * 10,
			);
		}

		$submitted = Defaults::for_campaign();
		$submitted['monthly']['enabled']  = true;
		$submitted['monthly']['presets']  = $twelve_presets;
		$submitted['annual']['enabled']   = true;
		$submitted['annual']['presets']   = $twelve_presets;
		$submitted['display']             = array(
			'show_title'    => true,
			'show_image'    => false,
			'cause_heading' => '',
		);

		// Step 1: First save — compute baseline + delta + store.
		$baseline = $repo->compute_baseline( self::CAMPAIGN_ID );
		$delta    = $repo->compute_override_delta( $baseline, $submitted );
		$repo->set_overrides( self::CAMPAIGN_ID, $delta );

		// Step 2: Read back what's stored.
		$stored = $repo->get_overrides( self::CAMPAIGN_ID );
		$this->assertNotEmpty( $stored, 'Overrides should not be empty after first save.' );

		// Step 3: Resolver should give back the user's config.
		$resolved = Config_Resolver::resolve( self::CAMPAIGN_ID );
		$this->assertTrue(
			$resolved['monthly']['enabled'],
			'Monthly should still be enabled after first save → reload.'
		);
		$this->assertTrue(
			$resolved['annual']['enabled'],
			'Annual should still be enabled after first save → reload.'
		);
		$this->assertCount(
			12,
			$resolved['monthly']['presets'],
			'Monthly should have 12 presets after first save → reload.'
		);

		// Step 4: SECOND save (admin reloads + saves again with same data).
		$baseline_2 = $repo->compute_baseline( self::CAMPAIGN_ID );
		$delta_2    = $repo->compute_override_delta( $baseline_2, $submitted );
		$repo->set_overrides( self::CAMPAIGN_ID, $delta_2 );

		// Step 5: Read back. Settings should STILL be there.
		$resolved_after_second = Config_Resolver::resolve( self::CAMPAIGN_ID );
		$this->assertTrue(
			$resolved_after_second['monthly']['enabled'],
			'Monthly should still be enabled after second save → reload.'
		);
		$this->assertTrue(
			$resolved_after_second['annual']['enabled'],
			'Annual should still be enabled after second save → reload.'
		);
		$this->assertCount(
			12,
			$resolved_after_second['monthly']['presets'],
			'Monthly should still have 12 presets after second save → reload.'
		);
	}
}
