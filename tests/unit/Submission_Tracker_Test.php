<?php
/**
 * Regression test for the Submission_Tracker filter binding.
 *
 * v1.1.0 — v2.0.0 mistakenly registered the
 * `wc_donation_alter_donate_response` filter with `accepted_args=2`, but
 * the parent plugin's three apply_filters call sites (in
 * `class-wcdonationorder.php` lines 1592, 1634, 1821) all pass ONE argument.
 * The mismatch caused an ArgumentCountError mid-AJAX, which surfaced as
 * an infinite spinner on the donor's submit. v2.0.1 binds 1 arg + reads
 * `$_POST` directly. This test locks the binding so a future "let's
 * pass the request as the second arg!" refactor doesn't silently bring
 * the regression back.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Analytics\Submission_Tracker;
use PHPUnit\Framework\TestCase;

final class Submission_Tracker_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_callback_works_when_invoked_with_one_arg(): void {
		// Reproduce the production crash conditions: parent calls
		// apply_filters( 'wc_donation_alter_donate_response', $response )
		// with ONE arg. The callback must accept that and not throw.
		new Submission_Tracker();

		$_POST = array(
			'campaign_id' => '42',
			'amount'      => '25.00',
			'is_recurring' => 'no',
		);

		$captured = array();
		add_action(
			'dfwc_companion_donation_submitted',
			static function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) use ( &$captured ) {
				$captured = compact( 'campaign_id', 'interval', 'amount' );
			},
			10,
			7
		);

		$response = array( 'response' => 'success', 'message' => 'OK' );

		// CRITICAL: only one arg. If the binding is wrong (accepted_args=2,
		// signature requires 2), PHP throws ArgumentCountError here.
		$out = apply_filters( 'wc_donation_alter_donate_response', $response );

		$this->assertSame( $response, $out );
		$this->assertSame( 42, $captured['campaign_id'] );
		$this->assertSame( 25.0, $captured['amount'] );
	}

	public function test_callback_returns_response_unchanged_when_post_empty(): void {
		new Submission_Tracker();
		$_POST = array();

		$response = array( 'response' => 'success' );
		$out      = apply_filters( 'wc_donation_alter_donate_response', $response );

		$this->assertSame( $response, $out );
	}

	public function test_callback_short_circuits_on_missing_campaign_id(): void {
		new Submission_Tracker();
		$_POST = array( 'amount' => '25.00' ); // no campaign_id

		$fired = false;
		add_action(
			'dfwc_companion_donation_submitted',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		apply_filters( 'wc_donation_alter_donate_response', array( 'response' => 'success' ) );
		$this->assertFalse( $fired );
	}

	public function test_failed_response_fires_donation_failed(): void {
		new Submission_Tracker();
		$_POST = array(
			'campaign_id' => '7',
			'amount'      => '50.00',
			'is_recurring' => 'no',
		);

		$captured = array();
		add_action(
			'dfwc_companion_donation_failed',
			static function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) use ( &$captured ) {
				$captured = compact( 'campaign_id', 'amount', 'reason' );
			},
			10,
			7
		);

		$response = array( 'response' => 'error', 'message' => 'Card declined.' );
		apply_filters( 'wc_donation_alter_donate_response', $response );

		$this->assertSame( 7, $captured['campaign_id'] );
		$this->assertSame( 50.0, $captured['amount'] );
		$this->assertSame( 'Card declined.', $captured['reason'] );
	}
}
