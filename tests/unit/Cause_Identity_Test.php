<?php
/**
 * Unit tests for Config\Cause_Identity.
 *
 * Pure-logic paths covered: lazy id minting, name↔id round-trip,
 * position lookups, prune-on-delete, rename-preserves-id. The cart hook
 * + order-item hook need WC stubs not in our test bootstrap; covered by
 * integration tests against wp-env.
 *
 * Parent's `donation-cause-names` storage quirk: it's a single-row meta
 * whose value is itself an array. The test stub stores raw arrays, so
 * our parent_names() defensively unwraps both shapes.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Cause_Identity;
use PHPUnit\Framework\TestCase;

final class Cause_Identity_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	private function seed_parent_names( array $names ): void {
		// Parent's wrapped storage shape: meta value is `[ [name1, name2, ...] ]`.
		update_post_meta( self::CAMPAIGN_ID, Cause_Identity::PARENT_NAMES_META, array( $names ) );
	}

	public function test_for_campaign_returns_empty_when_no_parent_names(): void {
		$this->assertSame( array(), Cause_Identity::for_campaign( self::CAMPAIGN_ID ) );
	}

	public function test_for_campaign_mints_ids_for_each_parent_cause(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare', 'Emergency Relief' ) );

		$ids = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertCount( 3, $ids );
		$this->assertNotEmpty( $ids[0] );
		$this->assertNotEmpty( $ids[1] );
		$this->assertNotEmpty( $ids[2] );
		$this->assertNotSame( $ids[0], $ids[1] );
		$this->assertNotSame( $ids[1], $ids[2] );
	}

	public function test_minted_ids_persist_across_calls(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );

		$first  = Cause_Identity::for_campaign( self::CAMPAIGN_ID );
		$second = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( $first, $second );
	}

	public function test_rename_preserves_id_at_same_position(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );
		$before = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		// Admin renames "Education" → "Schools" in parent's UI; position 0 same.
		$this->seed_parent_names( array( 'Schools', 'Healthcare' ) );
		$after = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( $before[0], $after[0], 'Position 0 id should survive rename.' );
		$this->assertSame( $before[1], $after[1] );
	}

	public function test_new_cause_at_new_position_gets_fresh_id(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );
		$before = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->seed_parent_names( array( 'Education', 'Healthcare', 'Emergency Relief' ) );
		$after = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( $before[0], $after[0] );
		$this->assertSame( $before[1], $after[1] );
		$this->assertNotEmpty( $after[2] );
		$this->assertNotContains( $after[2], array( $before[0], $before[1] ) );
	}

	public function test_deleted_cause_is_pruned(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare', 'Emergency Relief' ) );
		Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		// Admin deletes "Emergency Relief" (position 2).
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );
		$after = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertCount( 2, $after );
		$this->assertArrayNotHasKey( 2, $after );
	}

	public function test_id_for_name_returns_minted_id(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );
		$ids = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( $ids[0], Cause_Identity::id_for_name( self::CAMPAIGN_ID, 'Education' ) );
		$this->assertSame( $ids[1], Cause_Identity::id_for_name( self::CAMPAIGN_ID, 'Healthcare' ) );
	}

	public function test_id_for_name_returns_null_for_unknown(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );

		$this->assertNull( Cause_Identity::id_for_name( self::CAMPAIGN_ID, 'Nonexistent' ) );
		$this->assertNull( Cause_Identity::id_for_name( self::CAMPAIGN_ID, '' ) );
	}

	public function test_name_for_id_round_trips(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );
		$ids = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( 'Education', Cause_Identity::name_for_id( self::CAMPAIGN_ID, $ids[0] ) );
		$this->assertSame( 'Healthcare', Cause_Identity::name_for_id( self::CAMPAIGN_ID, $ids[1] ) );
	}

	public function test_name_for_id_returns_null_for_unknown(): void {
		$this->seed_parent_names( array( 'Education' ) );

		$this->assertNull( Cause_Identity::name_for_id( self::CAMPAIGN_ID, 'fake-uuid' ) );
		$this->assertNull( Cause_Identity::name_for_id( self::CAMPAIGN_ID, '' ) );
	}

	public function test_position_for_id(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare', 'Emergency Relief' ) );
		$ids = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( 0, Cause_Identity::position_for_id( self::CAMPAIGN_ID, $ids[0] ) );
		$this->assertSame( 2, Cause_Identity::position_for_id( self::CAMPAIGN_ID, $ids[2] ) );
		$this->assertNull( Cause_Identity::position_for_id( self::CAMPAIGN_ID, 'fake' ) );
	}

	public function test_id_for_position(): void {
		$this->seed_parent_names( array( 'Education', 'Healthcare' ) );
		$ids = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertSame( $ids[0], Cause_Identity::id_for_position( self::CAMPAIGN_ID, 0 ) );
		$this->assertSame( $ids[1], Cause_Identity::id_for_position( self::CAMPAIGN_ID, 1 ) );
		$this->assertNull( Cause_Identity::id_for_position( self::CAMPAIGN_ID, 99 ) );
	}

	public function test_empty_cause_names_are_skipped(): void {
		// Parent sometimes carries empty entries when admins delete via the
		// in-UI list (the entry remains, just with empty value). Skip those.
		$this->seed_parent_names( array( 'Education', '', 'Healthcare' ) );

		$ids = Cause_Identity::for_campaign( self::CAMPAIGN_ID );

		$this->assertArrayHasKey( 0, $ids );
		$this->assertArrayNotHasKey( 1, $ids );
		$this->assertArrayHasKey( 2, $ids );
	}
}
