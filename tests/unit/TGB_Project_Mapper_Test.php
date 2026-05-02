<?php
/**
 * Unit tests for Gateways\TGB_Project_Mapper.
 *
 * Pure persistence + resolution tests using the in-memory post-meta
 * stub. The reverse-lookup `find_campaign_by_project_id` requires
 * WP_Query (only stubbed at integration test layer); covered manually.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Gateways\TGB_Project_Mapper;
use PHPUnit\Framework\TestCase;

final class TGB_Project_Mapper_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	/* ----- resolve() ----- */

	public function test_resolve_returns_empty_when_no_global_or_campaign(): void {
		$this->assertSame( '', TGB_Project_Mapper::resolve( self::CAMPAIGN_ID ) );
	}

	public function test_resolve_falls_back_to_global_default(): void {
		update_option(
			'dfwc_companion_global_settings',
			array( 'tgb_default_project_id' => 'proj_org_default' ),
			false
		);

		$this->assertSame( 'proj_org_default', TGB_Project_Mapper::resolve( self::CAMPAIGN_ID ) );
	}

	public function test_resolve_per_campaign_wins_over_global(): void {
		update_option(
			'dfwc_companion_global_settings',
			array( 'tgb_default_project_id' => 'proj_org_default' ),
			false
		);
		TGB_Project_Mapper::set( self::CAMPAIGN_ID, array( 'tgb_project_id' => 'proj_specific' ) );

		$this->assertSame( 'proj_specific', TGB_Project_Mapper::resolve( self::CAMPAIGN_ID ) );
	}

	public function test_empty_per_campaign_project_id_falls_back_to_global(): void {
		update_option(
			'dfwc_companion_global_settings',
			array( 'tgb_default_project_id' => 'proj_org_default' ),
			false
		);
		// enabled flag stored, but no project_id — should still fall back.
		TGB_Project_Mapper::set( self::CAMPAIGN_ID, array( 'enabled' => true ) );

		$this->assertSame( 'proj_org_default', TGB_Project_Mapper::resolve( self::CAMPAIGN_ID ) );
	}

	/* ----- is_enabled_for_campaign() ----- */

	public function test_is_enabled_defaults_true_when_no_override(): void {
		$this->assertTrue( TGB_Project_Mapper::is_enabled_for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_is_enabled_explicitly_false_opts_out(): void {
		TGB_Project_Mapper::set( self::CAMPAIGN_ID, array( 'enabled' => false ) );

		$this->assertFalse( TGB_Project_Mapper::is_enabled_for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_is_enabled_explicitly_true_returns_true(): void {
		TGB_Project_Mapper::set( self::CAMPAIGN_ID, array( 'enabled' => true ) );

		$this->assertTrue( TGB_Project_Mapper::is_enabled_for_campaign( self::CAMPAIGN_ID ) );
	}

	/* ----- set() / for_campaign() ----- */

	public function test_set_persists_data_round_trip(): void {
		TGB_Project_Mapper::set(
			self::CAMPAIGN_ID,
			array(
				'enabled'        => true,
				'tgb_project_id' => 'proj_x',
			)
		);

		$loaded = TGB_Project_Mapper::for_campaign( self::CAMPAIGN_ID );
		$this->assertTrue( $loaded['enabled'] );
		$this->assertSame( 'proj_x', $loaded['tgb_project_id'] );
	}

	public function test_set_null_clears_crypto_overrides(): void {
		TGB_Project_Mapper::set( self::CAMPAIGN_ID, array( 'tgb_project_id' => 'proj_x' ) );
		$this->assertNotEmpty( TGB_Project_Mapper::for_campaign( self::CAMPAIGN_ID ) );

		TGB_Project_Mapper::set( self::CAMPAIGN_ID, null );
		$this->assertSame( array(), TGB_Project_Mapper::for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_set_does_not_stomp_other_namespaces(): void {
		// Pre-populate the campaign with a non-crypto override (e.g.,
		// goal-aware setting from v1.2.0).
		update_post_meta(
			self::CAMPAIGN_ID,
			TGB_Project_Mapper::OVERRIDES_META_KEY,
			array( 'goal_aware' => array( 'enable_clamp' => true ) )
		);

		TGB_Project_Mapper::set( self::CAMPAIGN_ID, array( 'tgb_project_id' => 'proj_x' ) );

		$loaded = get_post_meta( self::CAMPAIGN_ID, TGB_Project_Mapper::OVERRIDES_META_KEY, true );
		$this->assertArrayHasKey( 'goal_aware', $loaded );
		$this->assertArrayHasKey( 'crypto', $loaded );
		$this->assertSame( 'proj_x', $loaded['crypto']['tgb_project_id'] );
	}

	/* ----- sanitize() ----- */

	public function test_sanitize_drops_unknown_keys(): void {
		$out = TGB_Project_Mapper::sanitize(
			array(
				'enabled'         => true,
				'tgb_project_id'  => 'proj_x',
				'unknown_field'   => 'should be dropped',
				'__protocol_keys' => 'evil',
			)
		);

		$this->assertSame( array( 'enabled' => true, 'tgb_project_id' => 'proj_x' ), $out );
	}

	public function test_sanitize_coerces_enabled_to_bool(): void {
		$out = TGB_Project_Mapper::sanitize( array( 'enabled' => '1' ) );
		$this->assertSame( true, $out['enabled'] );

		$out = TGB_Project_Mapper::sanitize( array( 'enabled' => '' ) );
		$this->assertSame( false, $out['enabled'] );
	}

	public function test_sanitize_drops_empty_project_id(): void {
		$out = TGB_Project_Mapper::sanitize( array( 'tgb_project_id' => '' ) );
		$this->assertArrayNotHasKey( 'tgb_project_id', $out );
	}
}
