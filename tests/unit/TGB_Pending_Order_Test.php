<?php
/**
 * Unit tests for Gateways\TGB_Pending_Order::sanitize_payload.
 *
 * Pure validation logic — no WC, no wpdb. The order-creation +
 * idempotency-lookup paths exercise wc_create_order, wc_get_product, +
 * raw $wpdb queries; those are integration-tested manually against a
 * wp-env install (per docs/crypto-donations.md once it ships in 13.E).
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Gateways\TGB_Pending_Order;
use PHPUnit\Framework\TestCase;

final class TGB_Pending_Order_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	private function valid_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'donation_id'   => 'don_abc123',
				'campaign_id'   => 4242,
				'project_id'    => 'proj_xyz',
				'currency'      => 'BTC',
				'amount_crypto' => 0.00125,
				'amount_usd'    => 75.50,
			),
			$overrides
		);
	}

	public function test_valid_payload_passes_with_canonical_data(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'don_abc123', $result['data']['donation_id'] );
		$this->assertSame( 4242, $result['data']['campaign_id'] );
		$this->assertSame( 'BTC', $result['data']['currency'] );
		$this->assertSame( 75.50, $result['data']['amount_usd'] );
	}

	public function test_currency_is_uppercased(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'currency' => 'eth' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ETH', $result['data']['currency'] );
	}

	public function test_missing_donation_id_fails(): void {
		$payload = $this->valid_payload();
		unset( $payload['donation_id'] );
		$result = TGB_Pending_Order::sanitize_payload( $payload );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'donation_id', $result['errors'] );
	}

	public function test_donation_id_with_special_chars_fails(): void {
		// Use a char that survives sanitize_text_field but fails our
		// alphanumeric+underscore+hyphen regex. `<` would be stripped first
		// (sanitize strips HTML tags); period is the cleanest test case.
		$result = TGB_Pending_Order::sanitize_payload(
			$this->valid_payload( array( 'donation_id' => 'don.abc/etc' ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'donation_id', $result['errors'] );
	}

	public function test_donation_id_too_long_fails(): void {
		$result = TGB_Pending_Order::sanitize_payload(
			$this->valid_payload( array( 'donation_id' => str_repeat( 'a', 256 ) ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'donation_id', $result['errors'] );
	}

	public function test_zero_campaign_id_fails(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'campaign_id' => 0 ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'campaign_id', $result['errors'] );
	}

	public function test_zero_amount_usd_fails(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'amount_usd' => 0 ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'amount_usd', $result['errors'] );
	}

	public function test_excessive_amount_usd_fails(): void {
		// Cap at $10M — gifts above this trigger TGB manual review and
		// don't flow through the standard widget commit path.
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'amount_usd' => 100000000 ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'amount_usd', $result['errors'] );
	}

	public function test_negative_amount_crypto_fails(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'amount_crypto' => -1.0 ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'amount_crypto', $result['errors'] );
	}

	public function test_zero_amount_crypto_is_allowed(): void {
		// TGB sometimes reports just USD value (e.g., during certain
		// stablecoin flows). Crypto amount 0 is OK; USD amount must be > 0.
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'amount_crypto' => 0 ) ) );

		$this->assertTrue( $result['ok'] );
	}

	public function test_invalid_currency_fails(): void {
		$result = TGB_Pending_Order::sanitize_payload(
			$this->valid_payload( array( 'currency' => 'BTC<script>alert(1)</script>' ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'currency', $result['errors'] );
	}

	public function test_empty_currency_is_allowed(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'currency' => '' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['data']['currency'] );
	}

	public function test_optional_project_id_can_be_empty(): void {
		$result = TGB_Pending_Order::sanitize_payload( $this->valid_payload( array( 'project_id' => '' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['data']['project_id'] );
	}

	public function test_multiple_validation_errors_all_returned(): void {
		$result = TGB_Pending_Order::sanitize_payload(
			array(
				'donation_id' => '',
				'campaign_id' => 0,
				'amount_usd'  => 0,
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'donation_id', $result['errors'] );
		$this->assertArrayHasKey( 'campaign_id', $result['errors'] );
		$this->assertArrayHasKey( 'amount_usd', $result['errors'] );
	}
}
