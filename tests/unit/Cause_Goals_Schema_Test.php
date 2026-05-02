<?php
/**
 * Unit tests for Config\Cause_Goals_Schema.
 *
 * Pure persistence + resolution tests using the in-memory post-meta and
 * options stubs. Covers: closure-mode allow-listing, sanitize drops
 * unknown keys + all-default rows, set() merges into existing overrides
 * without stomping other namespaces, for_cause() applies global default
 * for inherit, set(null) clears.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Cause_Goals_Schema;
use PHPUnit\Framework\TestCase;

final class Cause_Goals_Schema_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;
	private const CAUSE_ID    = 'cause-uuid-aaa';
	private const CAUSE_2_ID  = 'cause-uuid-bbb';

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_for_campaign_returns_empty_when_none_configured(): void {
		$this->assertSame( array(), Cause_Goals_Schema::for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_set_persists_round_trip(): void {
		Cause_Goals_Schema::set(
			self::CAMPAIGN_ID,
			array(
				self::CAUSE_ID => array(
					'enabled'      => true,
					'goal_amount'  => 1500.0,
					'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE,
				),
			)
		);

		$loaded = Cause_Goals_Schema::for_campaign( self::CAMPAIGN_ID );
		$this->assertArrayHasKey( self::CAUSE_ID, $loaded );
		$this->assertSame( 1500.0, $loaded[ self::CAUSE_ID ]['goal_amount'] );
		$this->assertSame( Cause_Goals_Schema::CLOSURE_MODE_HIDE, $loaded[ self::CAUSE_ID ]['closure_mode'] );
	}

	public function test_set_null_clears_namespace(): void {
		Cause_Goals_Schema::set(
			self::CAMPAIGN_ID,
			array( self::CAUSE_ID => array( 'enabled' => true, 'goal_amount' => 100, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_INHERIT ) )
		);
		$this->assertNotEmpty( Cause_Goals_Schema::for_campaign( self::CAMPAIGN_ID ) );

		Cause_Goals_Schema::set( self::CAMPAIGN_ID, null );
		$this->assertSame( array(), Cause_Goals_Schema::for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_set_does_not_stomp_other_namespaces(): void {
		// Pre-populate a non-cause-goals override (e.g., crypto from v2.3.0).
		update_post_meta(
			self::CAMPAIGN_ID,
			Cause_Goals_Schema::OVERRIDES_META_KEY,
			array( 'crypto' => array( 'enabled' => true, 'tgb_project_id' => 'proj_x' ) )
		);

		Cause_Goals_Schema::set(
			self::CAMPAIGN_ID,
			array( self::CAUSE_ID => array( 'enabled' => true, 'goal_amount' => 250.0, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ) )
		);

		$loaded = get_post_meta( self::CAMPAIGN_ID, Cause_Goals_Schema::OVERRIDES_META_KEY, true );
		$this->assertArrayHasKey( 'crypto', $loaded );
		$this->assertArrayHasKey( 'cause_goals', $loaded );
		$this->assertSame( 'proj_x', $loaded['crypto']['tgb_project_id'] );
	}

	public function test_sanitize_drops_all_default_rows(): void {
		// All-defaults row (enabled=false, goal=0, mode=inherit) is
		// equivalent to "not configured" — sanitizer should drop it.
		$out = Cause_Goals_Schema::sanitize(
			array(
				self::CAUSE_ID => array(
					'enabled'      => false,
					'goal_amount'  => 0,
					'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_INHERIT,
				),
			)
		);

		$this->assertSame( array(), $out );
	}

	public function test_sanitize_drops_empty_cause_id_keys(): void {
		$out = Cause_Goals_Schema::sanitize(
			array(
				''                 => array( 'enabled' => true, 'goal_amount' => 100 ),
				self::CAUSE_ID     => array( 'enabled' => true, 'goal_amount' => 100, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			)
		);

		$this->assertArrayNotHasKey( '', $out );
		$this->assertArrayHasKey( self::CAUSE_ID, $out );
	}

	public function test_sanitize_clamps_negative_goal_amount(): void {
		$out = Cause_Goals_Schema::sanitize(
			array(
				self::CAUSE_ID => array( 'enabled' => true, 'goal_amount' => -500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			)
		);

		$this->assertSame( 0.0, $out[ self::CAUSE_ID ]['goal_amount'] );
	}

	public function test_sanitize_invalid_closure_mode_falls_to_inherit(): void {
		$out = Cause_Goals_Schema::sanitize(
			array(
				self::CAUSE_ID => array( 'enabled' => true, 'goal_amount' => 100, 'closure_mode' => 'evil_value' ),
			)
		);

		$this->assertSame( Cause_Goals_Schema::CLOSURE_MODE_INHERIT, $out[ self::CAUSE_ID ]['closure_mode'] );
	}

	public function test_for_cause_returns_defaults_when_unset(): void {
		$row = Cause_Goals_Schema::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );

		$this->assertFalse( $row['enabled'] );
		$this->assertSame( 0.0, $row['goal_amount'] );
		// Default global mode (no global option set) falls back to
		// DEFAULT_CLOSURE_MODE = redirect_to_cause.
		$this->assertSame( Cause_Goals_Schema::DEFAULT_CLOSURE_MODE, $row['closure_mode'] );
	}

	public function test_for_cause_resolves_inherit_to_global(): void {
		update_option(
			'dfwc_companion_global_settings',
			array( 'default_cause_closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			false
		);
		Cause_Goals_Schema::set(
			self::CAMPAIGN_ID,
			array(
				self::CAUSE_ID => array(
					'enabled'      => true,
					'goal_amount'  => 500.0,
					'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_INHERIT,
				),
			)
		);

		$row = Cause_Goals_Schema::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );

		$this->assertTrue( $row['enabled'] );
		$this->assertSame( Cause_Goals_Schema::CLOSURE_MODE_HIDE, $row['closure_mode'], 'inherit should resolve to the global setting' );
	}

	public function test_for_cause_explicit_mode_wins_over_global(): void {
		update_option(
			'dfwc_companion_global_settings',
			array( 'default_cause_closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			false
		);
		Cause_Goals_Schema::set(
			self::CAMPAIGN_ID,
			array(
				self::CAUSE_ID => array(
					'enabled'      => true,
					'goal_amount'  => 500.0,
					'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN,
				),
			)
		);

		$row = Cause_Goals_Schema::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );
		$this->assertSame( Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN, $row['closure_mode'] );
	}

	public function test_resolve_global_default_mode_falls_back_when_invalid(): void {
		update_option( 'dfwc_companion_global_settings', array( 'default_cause_closure_mode' => 'evil' ), false );

		$this->assertSame( Cause_Goals_Schema::DEFAULT_CLOSURE_MODE, Cause_Goals_Schema::resolve_global_default_mode() );
	}

	public function test_resolve_global_default_mode_rejects_inherit_at_global_level(): void {
		// `inherit` is a sentinel for per-cause; setting it as the global
		// default would create infinite inheritance. Sanitizer rejects it
		// AND resolve_global_default_mode falls back to DEFAULT.
		update_option( 'dfwc_companion_global_settings', array( 'default_cause_closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_INHERIT ), false );

		$this->assertSame( Cause_Goals_Schema::DEFAULT_CLOSURE_MODE, Cause_Goals_Schema::resolve_global_default_mode() );
	}

	public function test_feature_enabled_reads_global_toggle(): void {
		$this->assertFalse( Cause_Goals_Schema::feature_enabled() );

		update_option( 'dfwc_companion_global_settings', array( 'enable_cause_goals' => true ), false );
		$this->assertTrue( Cause_Goals_Schema::feature_enabled() );
	}

	public function test_multiple_causes_all_persist(): void {
		Cause_Goals_Schema::set(
			self::CAMPAIGN_ID,
			array(
				self::CAUSE_ID    => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
				self::CAUSE_2_ID  => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_TO_CAUSE ),
			)
		);

		$loaded = Cause_Goals_Schema::for_campaign( self::CAMPAIGN_ID );
		$this->assertCount( 2, $loaded );
		$this->assertSame( 500.0, $loaded[ self::CAUSE_ID ]['goal_amount'] );
		$this->assertSame( 1000.0, $loaded[ self::CAUSE_2_ID ]['goal_amount'] );
	}
}
