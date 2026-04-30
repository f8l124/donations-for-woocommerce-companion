<?php
/**
 * Unit tests for Stock\Stock_Pledge_Handler.
 *
 * Focuses on the donor-input sanitizer (the primary trust boundary) and the
 * happy-path of `mark_received` (the moment Phase 9 fires for stock).
 * Email-sending paths and admin reconciliation UI are exercised in e2e.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Stock\Stock_Pledge_Handler;
use DFWC\Companion\Stock\Stock_Pledge_Post_Type;
use PHPUnit\Framework\TestCase;

final class Stock_Pledge_Handler_Test extends TestCase {

	private const CAMPAIGN_ID = 42;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
		// Seed a valid campaign so sanitize_pledge_input campaign checks pass.
		$GLOBALS['_dfwc_test_posts'][ self::CAMPAIGN_ID ] = (object) array(
			'ID'         => self::CAMPAIGN_ID,
			'post_type'  => 'wc-donation',
			'post_title' => 'School Sponsorship',
		);
	}

	/**
	 * Build a base payload that passes every check. Tests override a single
	 * field to assert the corresponding error path.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_payload(): array {
		return array(
			'donor_name'      => 'Jane Donor',
			'donor_email'     => 'jane@example.com',
			'donor_phone'     => '+1 555 123 4567',
			'broker_name'     => 'Fidelity Investments',
			'ticker'          => 'AAPL',
			'shares'          => 50,
			'estimated_value' => 8500.00,
			'campaign_id'     => self::CAMPAIGN_ID,
			'donor_notes'     => 'Estate gift in memory of...',
		);
	}

	public function test_valid_payload_returns_ok_with_canonical_data(): void {
		$out = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'Jane Donor', $out['data']['donor_name'] );
		$this->assertSame( 'jane@example.com', $out['data']['donor_email'] );
		$this->assertSame( 'AAPL', $out['data']['ticker'] );
		$this->assertSame( 50.0, $out['data']['shares'] );
		$this->assertSame( 8500.0, $out['data']['estimated_value'] );
		$this->assertSame( self::CAMPAIGN_ID, $out['data']['campaign_id'] );
	}

	public function test_ticker_uppercased_for_consistency(): void {
		$payload           = $this->valid_payload();
		$payload['ticker'] = 'aapl';
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'AAPL', $out['data']['ticker'] );
	}

	public function test_dotted_ticker_accepted(): void {
		// Class shares like BRK.B should be allowed.
		$payload           = $this->valid_payload();
		$payload['ticker'] = 'BRK.B';
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'BRK.B', $out['data']['ticker'] );
	}

	public function test_empty_donor_name_rejected(): void {
		$payload               = $this->valid_payload();
		$payload['donor_name'] = '';
		$out                   = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'donor_name', $out['errors'] );
	}

	public function test_invalid_email_rejected(): void {
		$payload                = $this->valid_payload();
		$payload['donor_email'] = 'not-an-email';
		$out                    = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'donor_email', $out['errors'] );
	}

	public function test_empty_broker_name_rejected(): void {
		$payload                = $this->valid_payload();
		$payload['broker_name'] = '';
		$out                    = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'broker_name', $out['errors'] );
	}

	public function test_ticker_with_digits_rejected(): void {
		$payload           = $this->valid_payload();
		$payload['ticker'] = 'AAPL1';
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'ticker', $out['errors'] );
	}

	public function test_ticker_too_long_rejected(): void {
		$payload           = $this->valid_payload();
		$payload['ticker'] = 'TOOLONG';
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'ticker', $out['errors'] );
	}

	public function test_zero_shares_rejected(): void {
		$payload           = $this->valid_payload();
		$payload['shares'] = 0;
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'shares', $out['errors'] );
	}

	public function test_negative_shares_rejected(): void {
		$payload           = $this->valid_payload();
		$payload['shares'] = -10;
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'shares', $out['errors'] );
	}

	public function test_overflow_shares_rejected(): void {
		$payload           = $this->valid_payload();
		$payload['shares'] = 1000001;
		$out               = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'shares', $out['errors'] );
	}

	public function test_zero_estimated_value_rejected(): void {
		$payload                    = $this->valid_payload();
		$payload['estimated_value'] = 0;
		$out                        = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'estimated_value', $out['errors'] );
	}

	public function test_overflow_estimated_value_rejected(): void {
		$payload                    = $this->valid_payload();
		$payload['estimated_value'] = 100000001;
		$out                        = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'estimated_value', $out['errors'] );
	}

	public function test_missing_campaign_rejected(): void {
		$payload                = $this->valid_payload();
		$payload['campaign_id'] = 0;
		$out                    = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'campaign_id', $out['errors'] );
	}

	public function test_non_donation_post_rejected_as_campaign(): void {
		// Seed a regular page post — sanitize must reject it.
		$GLOBALS['_dfwc_test_posts'][ 999 ] = (object) array(
			'ID'        => 999,
			'post_type' => 'page',
		);
		$payload                = $this->valid_payload();
		$payload['campaign_id'] = 999;
		$out                    = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertFalse( $out['ok'] );
		$this->assertArrayHasKey( 'campaign_id', $out['errors'] );
	}

	public function test_donor_notes_truncated_at_2000(): void {
		$payload                = $this->valid_payload();
		$payload['donor_notes'] = str_repeat( 'A', 5000 );
		$out                    = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 2000, strlen( $out['data']['donor_notes'] ) );
	}

	public function test_html_tags_stripped_from_donor_name(): void {
		$payload               = $this->valid_payload();
		$payload['donor_name'] = '<script>alert(1)</script>Jane';
		$out                   = Stock_Pledge_Handler::sanitize_pledge_input( $payload );

		$this->assertTrue( $out['ok'] );
		$this->assertStringNotContainsString( '<', $out['data']['donor_name'] );
		$this->assertStringContainsString( 'Jane', $out['data']['donor_name'] );
	}

	public function test_create_pledge_fires_sha256_hashed_email_hook(): void {
		$captured = array();
		add_action(
			'dfwc_companion_stock_pledge_created',
			static function ( $pledge_id, $campaign_id, $hashed_email ) use ( &$captured ) {
				$captured = array(
					'pledge_id'    => $pledge_id,
					'campaign_id'  => $campaign_id,
					'hashed_email' => $hashed_email,
				);
			},
			10,
			3
		);

		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );
		$this->assertTrue( $sanitized['ok'] );

		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );
		$this->assertIsInt( $pledge_id );

		$this->assertSame( $pledge_id, $captured['pledge_id'] );
		$this->assertSame( self::CAMPAIGN_ID, $captured['campaign_id'] );
		// Donor email never appears raw in the hook payload.
		$this->assertNotSame( 'jane@example.com', $captured['hashed_email'] );
		$this->assertSame(
			hash( 'sha256', 'jane@example.com' ),
			$captured['hashed_email']
		);
	}

	public function test_mark_received_fires_phase_9_donation_submitted_hook(): void {
		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );
		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );

		$captured = array();
		add_action(
			'dfwc_companion_donation_submitted',
			static function ( $campaign_id, $interval, $amount, $context, $currency, $language, $reason ) use ( &$captured ) {
				$captured = compact( 'campaign_id', 'interval', 'amount', 'context', 'currency', 'language', 'reason' );
			},
			10,
			7
		);

		$result = Stock_Pledge_Handler::mark_received( $pledge_id, 8650.00, time(), 'Cleared today.' );
		$this->assertTrue( $result );

		$this->assertSame( self::CAMPAIGN_ID, $captured['campaign_id'] );
		$this->assertSame( 'one_time', $captured['interval'] );
		$this->assertSame( 8650.0, $captured['amount'] );
		$this->assertSame( 'stock', $captured['context'] );
		$this->assertSame( 'USD', $captured['currency'] );
	}

	public function test_mark_received_rejects_unknown_pledge(): void {
		$result = Stock_Pledge_Handler::mark_received( 99999, 1000.0, time() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dfwc_pledge_not_found', $result->get_error_code() );
	}

	public function test_mark_received_rejects_invalid_value(): void {
		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );
		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );

		$result = Stock_Pledge_Handler::mark_received( $pledge_id, 0.0, time() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dfwc_pledge_invalid_value', $result->get_error_code() );
	}

	public function test_transition_status_rejects_received(): void {
		// mark_received() is the only path that should set status=received
		// (so the Phase 9 hook fires). transition_status must refuse.
		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );
		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );

		$result = Stock_Pledge_Handler::transition_status( $pledge_id, Stock_Pledge_Post_Type::STATUS_RECEIVED );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dfwc_pledge_use_mark_received', $result->get_error_code() );
	}

	public function test_transition_status_accepts_in_transit_and_cancelled(): void {
		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );
		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );

		$this->assertTrue( Stock_Pledge_Handler::transition_status( $pledge_id, Stock_Pledge_Post_Type::STATUS_IN_TRANSIT ) );
		$pledge = Stock_Pledge_Handler::get_pledge( $pledge_id );
		$this->assertSame( Stock_Pledge_Post_Type::STATUS_IN_TRANSIT, $pledge['status'] );

		$this->assertTrue( Stock_Pledge_Handler::transition_status( $pledge_id, Stock_Pledge_Post_Type::STATUS_CANCELLED ) );
		$pledge = Stock_Pledge_Handler::get_pledge( $pledge_id );
		$this->assertSame( Stock_Pledge_Post_Type::STATUS_CANCELLED, $pledge['status'] );
	}

	public function test_transition_status_rejects_unknown_status(): void {
		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $this->valid_payload() );
		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );

		$result = Stock_Pledge_Handler::transition_status( $pledge_id, 'made_up_status' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dfwc_pledge_invalid_status', $result->get_error_code() );
	}

	public function test_get_pledge_returns_null_for_unknown(): void {
		$this->assertNull( Stock_Pledge_Handler::get_pledge( 99999 ) );
	}

	public function test_get_pledge_returns_pledged_status_when_meta_unset(): void {
		// Defensive default: if the status meta is somehow missing (e.g.
		// from a manual DB insert), reading should return 'pledged' not ''.
		$GLOBALS['_dfwc_test_posts'][ 7777 ] = (object) array(
			'ID'         => 7777,
			'post_type'  => Stock_Pledge_Post_Type::POST_TYPE,
			'post_title' => 'Stub',
		);
		$pledge = Stock_Pledge_Handler::get_pledge( 7777 );
		$this->assertNotNull( $pledge );
		$this->assertSame( Stock_Pledge_Post_Type::STATUS_PLEDGED, $pledge['status'] );
	}
}
