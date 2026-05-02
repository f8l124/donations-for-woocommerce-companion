<?php
/**
 * Unit tests for Gateways\TGB_Webhook_Handler.
 *
 * Pure-function paths covered: verify_signature, parse_and_validate.
 * The process() side-effect path requires WC + wpdb stubs; covered by
 * integration tests against wp-env (per docs/crypto-donations.md when
 * 13.E ships).
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Gateways\TGB_Webhook_Handler;
use PHPUnit\Framework\TestCase;

final class TGB_Webhook_Handler_Test extends TestCase {

	private const SECRET = 'whsec_test_super_secret';

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	/* ----- verify_signature ----- */

	private function sign( string $body, string $secret = self::SECRET ): string {
		return hash_hmac( 'sha256', $body, $secret );
	}

	public function test_signature_passes_for_correct_hmac(): void {
		$body = '{"event":"donation.confirmed"}';
		$sig  = $this->sign( $body );

		$this->assertTrue( TGB_Webhook_Handler::verify_signature( $body, $sig, self::SECRET ) );
	}

	public function test_signature_fails_for_wrong_hmac(): void {
		$body = '{"event":"donation.confirmed"}';
		$bad  = $this->sign( 'different body' );

		$this->assertFalse( TGB_Webhook_Handler::verify_signature( $body, $bad, self::SECRET ) );
	}

	public function test_signature_fails_for_empty_signature(): void {
		$this->assertFalse( TGB_Webhook_Handler::verify_signature( 'body', '', self::SECRET ) );
	}

	public function test_signature_fails_for_empty_secret(): void {
		$body = 'body';
		$sig  = hash_hmac( 'sha256', $body, '' );

		$this->assertFalse( TGB_Webhook_Handler::verify_signature( $body, $sig, '' ) );
	}

	public function test_signature_fails_for_wrong_secret(): void {
		$body = '{"event":"donation.confirmed"}';
		$sig  = $this->sign( $body, 'wrong_secret' );

		$this->assertFalse( TGB_Webhook_Handler::verify_signature( $body, $sig, self::SECRET ) );
	}

	/* ----- parse_and_validate ----- */

	private function valid_payload_json( array $overrides = array() ): string {
		$base = array(
			'event'         => 'donation.confirmed',
			'donation_id'   => 'don_abc123',
			'amount_usd'    => 75.50,
			'currency'      => 'BTC',
			'amount_crypto' => 0.00125,
			'project_id'    => 'proj_xyz',
		);
		return wp_json_encode( array_merge( $base, $overrides ) );
	}

	public function test_valid_payload_parses_with_canonical_data(): void {
		$result = TGB_Webhook_Handler::parse_and_validate( $this->valid_payload_json() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'donation.confirmed', $result['data']['event'] );
		$this->assertSame( 'don_abc123', $result['data']['donation_id'] );
		$this->assertSame( 75.50, $result['data']['amount_usd'] );
		$this->assertSame( 'BTC', $result['data']['currency'] );
	}

	public function test_empty_body_fails(): void {
		$result = TGB_Webhook_Handler::parse_and_validate( '' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'empty', $result['error'] );
	}

	public function test_invalid_json_fails(): void {
		$result = TGB_Webhook_Handler::parse_and_validate( '{not json' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'invalid json', $result['error'] );
	}

	public function test_unknown_event_rejected(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'event' => 'donation.refunded' ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'unknown event', $result['error'] );
	}

	public function test_missing_donation_id_rejected(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			wp_json_encode( array( 'event' => 'donation.confirmed', 'amount_usd' => 50 ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'donation_id', $result['error'] );
	}

	public function test_invalid_donation_id_rejected(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'donation_id' => 'don/with/slashes' ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'donation_id', $result['error'] );
	}

	public function test_zero_amount_rejected(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'amount_usd' => 0 ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'amount_usd', $result['error'] );
	}

	public function test_excessive_amount_rejected(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'amount_usd' => 100000000 ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'amount_usd', $result['error'] );
	}

	public function test_invalid_currency_rejected(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'currency' => 'BTC<bad>' ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'currency', $result['error'] );
	}

	public function test_currency_uppercased(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'currency' => 'eth' ) )
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ETH', $result['data']['currency'] );
	}

	public function test_optional_currency_can_be_empty(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json( array( 'currency' => '' ) )
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['data']['currency'] );
	}

	public function test_optional_fields_pass_through_when_present(): void {
		$result = TGB_Webhook_Handler::parse_and_validate(
			$this->valid_payload_json(
				array(
					'tx_hash'       => '0xabc123',
					'confirmed_at'  => '2026-05-02T12:34:56Z',
				)
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0xabc123', $result['data']['tx_hash'] );
		$this->assertSame( '2026-05-02T12:34:56Z', $result['data']['confirmed_at'] );
	}

	public function test_known_events_includes_donation_confirmed(): void {
		$this->assertContains( TGB_Webhook_Handler::EVENT_DONATION_CONFIRMED, TGB_Webhook_Handler::known_events() );
	}
}
