<?php
/**
 * Unit tests for Config\Goal_State.
 *
 * Each test seeds the in-memory post-meta store via update_post_meta()
 * (the bootstrap stub backs it with $GLOBALS['_dfwc_test_post_meta']).
 * Goal_State::for_campaign() reads from there; we don't need a live WP.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Goal_State;
use PHPUnit\Framework\TestCase;

final class Goal_State_Test extends TestCase {

	private const CAMPAIGN_ID = 42;
	private const PRODUCT_ID  = 99;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
		Goal_State::reset_cache();
	}

	public function test_no_goal_returns_zero_everywhere(): void {
		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );

		$this->assertFalse( $g->goal_enabled() );
		$this->assertFalse( $g->is_amount_goal() );
		$this->assertSame( 0.0, $g->goal_amount() );
		$this->assertSame( 0.0, $g->raised_amount() );
		$this->assertSame( 0.0, $g->remaining_amount() );
		$this->assertFalse( $g->is_fully_funded() );
	}

	public function test_non_amount_goal_type_is_not_amount_goal(): void {
		$this->seed_goal( 'no_of_donation', 1000.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertTrue( $g->goal_enabled() );
		$this->assertSame( 'no_of_donation', $g->goal_type() );
		$this->assertFalse( $g->is_amount_goal() );
		$this->assertSame( 0.0, $g->goal_amount() );
		$this->assertSame( 0.0, $g->remaining_amount() );
	}

	public function test_fixed_amount_goal_with_no_donations(): void {
		$this->seed_goal( 'fixed_amount', 1000.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertTrue( $g->is_amount_goal() );
		$this->assertSame( 1000.0, $g->goal_amount() );
		$this->assertSame( 0.0, $g->raised_amount() );
		$this->assertSame( 1000.0, $g->remaining_amount() );
		$this->assertFalse( $g->is_fully_funded() );
	}

	public function test_initial_amount_counts_toward_raised(): void {
		// Goal $1,000 with $200 seed; no actual donations yet.
		$this->seed_goal( 'fixed_amount', 1000.0, 200.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertSame( 200.0, $g->raised_amount() );
		$this->assertSame( 800.0, $g->remaining_amount() );
	}

	public function test_actual_donations_add_to_raised(): void {
		$this->seed_goal( 'fixed_amount', 1000.0, 100.0 );
		$this->seed_raised( 350.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertSame( 450.0, $g->raised_amount() ); // seed + donations
		$this->assertSame( 550.0, $g->remaining_amount() );
		$this->assertFalse( $g->is_fully_funded() );
	}

	public function test_fully_funded_when_raised_meets_goal(): void {
		$this->seed_goal( 'fixed_amount', 1000.0 );
		$this->seed_raised( 1000.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertTrue( $g->is_fully_funded() );
		$this->assertSame( 0.0, $g->remaining_amount() );
	}

	public function test_fully_funded_when_raised_exceeds_goal(): void {
		// Over-funded campaigns are common (donors keep giving past the goal).
		$this->seed_goal( 'fixed_amount', 1000.0 );
		$this->seed_raised( 1500.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertTrue( $g->is_fully_funded() );
		$this->assertSame( 0.0, $g->remaining_amount() ); // never negative
	}

	public function test_percentage_amount_goal_treated_as_amount(): void {
		// percentage_amount is a display-mode variant; underlying target is
		// still the fixed amount field. Same clamp behavior.
		$this->seed_goal( 'percentage_amount', 5000.0 );
		$this->seed_raised( 2000.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertTrue( $g->is_amount_goal() );
		$this->assertSame( 3000.0, $g->remaining_amount() );
	}

	public function test_no_linked_product_treats_raised_as_zero(): void {
		// Goal set but campaign has no linked product yet (newly published).
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-display-option', 'on' );
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-display-type', 'fixed_amount' );
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-fixed-amount-field', 1000 );
		// no `wc_donation_product` meta

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertSame( 0.0, $g->raised_amount() );
		$this->assertSame( 1000.0, $g->remaining_amount() );
	}

	public function test_negative_raised_clamps_to_zero(): void {
		// Defensive: shouldn't happen but parent might write a refund-aware
		// negative value at some point. Clamp.
		$this->seed_goal( 'fixed_amount', 1000.0 );
		$this->seed_raised( -50.0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertSame( 0.0, $g->raised_amount() );
	}

	public function test_zero_goal_amount_disables_amount_goal(): void {
		// Goal type is fixed_amount but no target was set.
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-display-option', 'on' );
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-display-type', 'fixed_amount' );
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-fixed-amount-field', 0 );

		$g = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$this->assertFalse( $g->is_amount_goal() );
		$this->assertFalse( $g->is_fully_funded() );
	}

	public function test_for_campaign_caches_per_campaign_id(): void {
		$this->seed_goal( 'fixed_amount', 1000.0 );

		$first  = Goal_State::for_campaign( self::CAMPAIGN_ID );
		$second = Goal_State::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( $first, $second ); // same instance — cached
	}

	public function test_reset_cache_returns_fresh_instance(): void {
		$this->seed_goal( 'fixed_amount', 1000.0 );
		$first = Goal_State::for_campaign( self::CAMPAIGN_ID );

		Goal_State::reset_cache();
		$second = Goal_State::for_campaign( self::CAMPAIGN_ID );

		$this->assertNotSame( $first, $second );
	}

	// === Helpers ===

	private function seed_goal( string $type, float $amount, float $initial = 0.0 ): void {
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-display-option', 'on' );
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-display-type', $type );
		update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-fixed-amount-field', $amount );
		if ( $initial > 0 ) {
			update_post_meta( self::CAMPAIGN_ID, 'wc-donation-goal-fixed-initial-amount-field', $initial );
		}
		update_post_meta( self::CAMPAIGN_ID, 'wc_donation_product', self::PRODUCT_ID );
	}

	private function seed_raised( float $amount ): void {
		update_post_meta( self::PRODUCT_ID, 'total_donation_amount', $amount );
	}
}
