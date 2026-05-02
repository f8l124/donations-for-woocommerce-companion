<?php
/**
 * Unit tests for Config\Cause_Raised_Aggregator.
 *
 * Cache + memo layer is fully unit-testable: cache-key derivation,
 * transient round-trip, per-request memo, invalidate semantics, guard
 * paths for invalid inputs.
 *
 * The aggregate() SQL path requires wpdb + WC orders + line-item meta
 * stubs not present in our test bootstrap; covered by integration
 * tests against wp-env (per docs/cause-aware-giving.md when 14.E
 * ships). Same approach as the v2.3.0 TGB_Pending_Order::create
 * integration-only path.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Cause_Raised_Aggregator;
use PHPUnit\Framework\TestCase;

final class Cause_Raised_Aggregator_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;
	private const CAUSE_ID    = 'cause-uuid-aaa';
	private const CAUSE_2_ID  = 'cause-uuid-bbb';

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
		Cause_Raised_Aggregator::reset_memo();
	}

	public function test_zero_when_campaign_id_invalid(): void {
		$this->assertSame( 0.0, Cause_Raised_Aggregator::for_cause( 0, self::CAUSE_ID ) );
		$this->assertSame( 0.0, Cause_Raised_Aggregator::for_cause( -5, self::CAUSE_ID ) );
	}

	public function test_zero_when_cause_id_empty(): void {
		$this->assertSame( 0.0, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, '' ) );
	}

	public function test_cache_key_is_deterministic(): void {
		$a = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		$b = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		$this->assertSame( $a, $b );
	}

	public function test_cache_key_differs_per_cause(): void {
		$a = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		$b = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_2_ID );
		$this->assertNotSame( $a, $b );
	}

	public function test_cache_key_differs_per_campaign(): void {
		$a = Cause_Raised_Aggregator::cache_key( 100, self::CAUSE_ID );
		$b = Cause_Raised_Aggregator::cache_key( 200, self::CAUSE_ID );
		$this->assertNotSame( $a, $b );
	}

	public function test_for_cause_reads_transient_when_present(): void {
		$key = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		set_transient( $key, 1234.56, 300 );

		$this->assertSame( 1234.56, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID ) );
	}

	public function test_for_cause_memo_serves_repeat_calls_without_re_reading_transient(): void {
		$key = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		set_transient( $key, 500.0, 300 );

		// Prime the memo.
		$first = Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );

		// Mutate the transient out-of-band; memo should still serve the
		// original value because the per-request layer wins until reset.
		set_transient( $key, 9999.0, 300 );
		$second = Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );

		$this->assertSame( $first, $second );
		$this->assertSame( 500.0, $second );
	}

	public function test_invalidate_clears_both_layers(): void {
		$key = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		set_transient( $key, 750.0, 300 );

		// Prime memo.
		$this->assertSame( 750.0, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID ) );

		Cause_Raised_Aggregator::invalidate( self::CAMPAIGN_ID, self::CAUSE_ID );

		// Re-set the transient to a different value; invalidate should
		// have cleared the memo so the new transient value wins.
		set_transient( $key, 1500.0, 300 );

		$this->assertSame( 1500.0, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID ) );
	}

	public function test_invalidate_only_affects_target_cause(): void {
		$key_a = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		$key_b = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_2_ID );
		set_transient( $key_a, 100.0, 300 );
		set_transient( $key_b, 200.0, 300 );

		// Prime both memos.
		Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );
		Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_2_ID );

		// Invalidate only cause A.
		Cause_Raised_Aggregator::invalidate( self::CAMPAIGN_ID, self::CAUSE_ID );

		// Cause B's memo + transient still serve the original.
		$this->assertSame( 200.0, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_2_ID ) );
	}

	public function test_reset_memo_clears_per_request_only(): void {
		$key = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		set_transient( $key, 123.0, 300 );

		// Prime memo.
		Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID );

		Cause_Raised_Aggregator::reset_memo();

		// Transient untouched — next call re-reads from there.
		$this->assertSame( 123.0, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID ) );
	}

	public function test_zero_transient_value_is_respected(): void {
		// Cause with no donations yet — aggregator stores 0.0 in the
		// transient. `false !== $cached` correctly handles 0.0 (vs.
		// the false sentinel meaning "no cache entry").
		$key = Cause_Raised_Aggregator::cache_key( self::CAMPAIGN_ID, self::CAUSE_ID );
		set_transient( $key, 0.0, 300 );

		$this->assertSame( 0.0, Cause_Raised_Aggregator::for_cause( self::CAMPAIGN_ID, self::CAUSE_ID ) );
	}
}
