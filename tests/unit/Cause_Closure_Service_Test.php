<?php
/**
 * Unit tests for Config\Cause_Closure_Service.
 *
 * Combines stubs from Cause_Goals_Schema (option-based) +
 * Cause_Identity (post-meta names + minted ids) + Cause_Raised_Aggregator
 * (transient-cached raised totals; we set the transient directly to
 * skip the SQL aggregate path).
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Cause_Closure_Service;
use DFWC\Companion\Config\Cause_Goals_Schema;
use DFWC\Companion\Config\Cause_Identity;
use DFWC\Companion\Config\Cause_Raised_Aggregator;
use PHPUnit\Framework\TestCase;

final class Cause_Closure_Service_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
		Cause_Raised_Aggregator::reset_memo();
	}

	/**
	 * Set up a campaign with two causes and the global feature toggle on.
	 * Returns the minted cause ids in parent's position order [0, 1].
	 *
	 * @return array<int,string>
	 */
	private function seed_two_cause_campaign(): array {
		update_option(
			'dfwc_companion_global_settings',
			array( 'enable_cause_goals' => true ),
			false
		);
		update_post_meta( self::CAMPAIGN_ID, Cause_Identity::PARENT_NAMES_META, array( array( 'Education', 'Healthcare' ) ) );
		return Cause_Identity::for_campaign( self::CAMPAIGN_ID );
	}

	/** Pre-seed the aggregator's transient to skip the SQL path. */
	private function seed_raised( string $cause_id, float $raised ): void {
		set_transient( Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, $cause_id ), $raised, 300 );
	}

	/* ----- is_closed() ----- */

	public function test_is_closed_returns_false_when_feature_disabled(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 100, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		// Toggle feature off.
		update_option( 'dfwc_companion_global_settings', array( 'enable_cause_goals' => false ), false );

		$this->assertFalse( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, $ids[0] ) );
	}

	public function test_is_closed_returns_false_when_cause_not_tracked(): void {
		$ids = $this->seed_two_cause_campaign();
		// No goal configured for this cause — defaults are enabled=false, goal=0.
		$this->seed_raised( $ids[0], 9999.0 );

		$this->assertFalse( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, $ids[0] ) );
	}

	public function test_is_closed_returns_false_when_goal_zero(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 0, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 9999.0 );

		$this->assertFalse( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, $ids[0] ) );
	}

	public function test_is_closed_returns_false_below_goal(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 999.99 );

		$this->assertFalse( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, $ids[0] ) );
	}

	public function test_is_closed_returns_true_at_goal(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		$this->assertTrue( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, $ids[0] ) );
	}

	public function test_is_closed_returns_true_above_goal(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 5000.0 );

		$this->assertTrue( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, $ids[0] ) );
	}

	public function test_is_closed_returns_false_for_invalid_inputs(): void {
		$this->assertFalse( Cause_Closure_Service::is_closed( 0, 'cause_id' ) );
		$this->assertFalse( Cause_Closure_Service::is_closed( self::CAMPAIGN_ID, '' ) );
	}

	/* ----- decision() ----- */

	public function test_decision_returns_canonical_shape_for_open_cause(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 500.0 );

		$d = Cause_Closure_Service::decision( self::CAMPAIGN_ID, $ids[0] );

		$this->assertFalse( $d['is_closed'] );
		$this->assertSame( 1000.0, $d['goal_amount'] );
		$this->assertSame( 500.0, $d['raised_amount'] );
		$this->assertSame( Cause_Goals_Schema::CLOSURE_MODE_HIDE, $d['mode'] );
		$this->assertSame( array(), $d['alternatives'] );
	}

	public function test_decision_includes_alternatives_for_redirect_to_cause_mode(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_TO_CAUSE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		$d = Cause_Closure_Service::decision( self::CAMPAIGN_ID, $ids[0] );

		$this->assertTrue( $d['is_closed'] );
		$this->assertCount( 1, $d['alternatives'] );
		$this->assertSame( 'Healthcare', $d['alternatives'][0]['name'] );
		$this->assertSame( $ids[1], $d['alternatives'][0]['cause_id'] );
	}

	public function test_decision_omits_alternatives_for_hide_mode(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		$d = Cause_Closure_Service::decision( self::CAMPAIGN_ID, $ids[0] );

		$this->assertTrue( $d['is_closed'] );
		// Hide mode doesn't need alternatives in the payload — JS just hides.
		$this->assertSame( array(), $d['alternatives'] );
	}

	/* ----- closed_causes_for_campaign() ----- */

	public function test_closed_causes_for_campaign_omits_open_causes(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			$ids[1] => array( 'enabled' => true, 'goal_amount' => 5000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 600.0 );  // closed
		$this->seed_raised( $ids[1], 1000.0 ); // open

		$out = Cause_Closure_Service::closed_causes_for_campaign( self::CAMPAIGN_ID );

		$this->assertCount( 1, $out );
		$this->assertArrayHasKey( $ids[0], $out );
		$this->assertArrayNotHasKey( $ids[1], $out );
	}

	public function test_closed_causes_for_campaign_includes_name(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		$out = Cause_Closure_Service::closed_causes_for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( 'Education', $out[ $ids[0] ]['name'] );
	}

	public function test_closed_causes_for_campaign_returns_empty_when_feature_off(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		// Toggle feature off.
		update_option( 'dfwc_companion_global_settings', array( 'enable_cause_goals' => false ), false );

		$this->assertSame( array(), Cause_Closure_Service::closed_causes_for_campaign( self::CAMPAIGN_ID ) );
	}

	/* ----- open_alternatives() ----- */

	public function test_open_alternatives_excludes_self_and_closed(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			$ids[1] => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 ); // closed
		$this->seed_raised( $ids[1], 1000.0 ); // also closed

		$alts = Cause_Closure_Service::open_alternatives( self::CAMPAIGN_ID, $ids[0] );

		// Both closed; no alternatives.
		$this->assertSame( array(), $alts );
	}

	public function test_open_alternatives_returns_other_open_causes(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 500, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 ); // closed
		// $ids[1] not configured for tracking — open.

		$alts = Cause_Closure_Service::open_alternatives( self::CAMPAIGN_ID, $ids[0] );

		$this->assertCount( 1, $alts );
		$this->assertSame( $ids[1], $alts[0]['cause_id'] );
		$this->assertSame( 'Healthcare', $alts[0]['name'] );
	}

	/* ----- progress_for_campaign() (v2.5.0) ----- */

	public function test_progress_returns_empty_when_feature_disabled(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );

		// Toggle feature off.
		update_option( 'dfwc_companion_global_settings', array( 'enable_cause_goals' => false ), false );

		$this->assertSame( array(), Cause_Closure_Service::progress_for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_progress_includes_only_tracked_causes(): void {
		$ids = $this->seed_two_cause_campaign();
		// Track Education only; Healthcare untracked.
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 250.0 );

		$progress = Cause_Closure_Service::progress_for_campaign( self::CAMPAIGN_ID );

		$this->assertArrayHasKey( $ids[0], $progress );
		$this->assertArrayNotHasKey( $ids[1], $progress );
	}

	public function test_progress_skips_zero_goal_causes(): void {
		// `enabled = true` + `goal_amount = 0` is a misconfiguration; skip.
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 0, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );

		$this->assertSame( array(), Cause_Closure_Service::progress_for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_progress_payload_shape_is_canonical(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 250.0 );

		$progress = Cause_Closure_Service::progress_for_campaign( self::CAMPAIGN_ID );

		$row = $progress[ $ids[0] ];
		$this->assertSame( 'Education', $row['name'] );
		$this->assertSame( 1000.0, $row['goal_amount'] );
		$this->assertSame( 250.0, $row['raised_amount'] );
		$this->assertSame( 25, $row['percent'] );
		$this->assertFalse( $row['is_closed'] );
	}

	public function test_progress_clamps_overfunded_to_100_percent(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 5000.0 ); // 500% of goal

		$progress = Cause_Closure_Service::progress_for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( 100, $progress[ $ids[0] ]['percent'], 'Overfunded causes show 100% (closure UX handles overage messaging)' );
		$this->assertTrue( $progress[ $ids[0] ]['is_closed'] );
	}

	public function test_progress_at_exactly_goal_marks_closed(): void {
		$ids = $this->seed_two_cause_campaign();
		Cause_Goals_Schema::set( self::CAMPAIGN_ID, array(
			$ids[0] => array( 'enabled' => true, 'goal_amount' => 1000, 'closure_mode' => Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
		) );
		$this->seed_raised( $ids[0], 1000.0 );

		$progress = Cause_Closure_Service::progress_for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( 100, $progress[ $ids[0] ]['percent'] );
		$this->assertTrue( $progress[ $ids[0] ]['is_closed'] );
	}
}
