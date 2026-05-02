<?php
/**
 * Unit tests for Gateways\TGB_Token_Store.
 *
 * Verifies AES-256-CBC round-trip, missing-credential semantics, and the
 * is_configured() / clear() lifecycle. AUTH_KEY + AUTH_SALT are defined in
 * tests/bootstrap.php so encryption keys derive deterministically.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Gateways\TGB_Token_Store;
use PHPUnit\Framework\TestCase;

final class TGB_Token_Store_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_empty_store_returns_null_for_both_fields(): void {
		$store = new TGB_Token_Store();

		$this->assertNull( $store->get_api_key() );
		$this->assertNull( $store->get_webhook_secret() );
		$this->assertFalse( $store->is_configured() );
		$this->assertSame( 0, $store->updated_at() );
	}

	public function test_set_and_get_api_key_round_trip(): void {
		$store = new TGB_Token_Store();

		$ok = $store->set_api_key( 'tgb_live_abc123XYZ' );
		$this->assertTrue( $ok );
		$this->assertSame( 'tgb_live_abc123XYZ', $store->get_api_key() );
	}

	public function test_set_and_get_webhook_secret_round_trip(): void {
		$store = new TGB_Token_Store();

		$ok = $store->set_webhook_secret( 'whsec_super_secret_xyz' );
		$this->assertTrue( $ok );
		$this->assertSame( 'whsec_super_secret_xyz', $store->get_webhook_secret() );
	}

	public function test_setting_empty_string_is_rejected(): void {
		$store = new TGB_Token_Store();

		$this->assertFalse( $store->set_api_key( '' ) );
		$this->assertNull( $store->get_api_key() );
	}

	public function test_is_configured_only_true_when_both_set(): void {
		$store = new TGB_Token_Store();

		$store->set_api_key( 'a_key' );
		$this->assertFalse( $store->is_configured(), 'webhook secret missing' );

		$store->set_webhook_secret( 'a_secret' );
		$this->assertTrue( $store->is_configured() );
	}

	public function test_clear_wipes_both_credentials(): void {
		$store = new TGB_Token_Store();
		$store->set_api_key( 'k' );
		$store->set_webhook_secret( 's' );

		$this->assertTrue( $store->is_configured() );

		$store->clear();
		$this->assertNull( $store->get_api_key() );
		$this->assertNull( $store->get_webhook_secret() );
		$this->assertFalse( $store->is_configured() );
	}

	public function test_setting_a_field_updates_timestamp(): void {
		$store  = new TGB_Token_Store();
		$before = time() - 1; // allow 1s slack
		$store->set_api_key( 'k' );

		$this->assertGreaterThanOrEqual( $before, $store->updated_at() );
	}

	public function test_ciphertext_is_not_plaintext_in_storage(): void {
		$store = new TGB_Token_Store();
		$store->set_api_key( 'tgb_live_plaintext_marker' );

		$raw = (array) get_option( TGB_Token_Store::OPTION_KEY, array() );
		$this->assertArrayHasKey( 'api_key_blob', $raw );
		$this->assertIsString( $raw['api_key_blob'] );
		$this->assertStringNotContainsString( 'tgb_live_plaintext_marker', $raw['api_key_blob'] );
	}

	public function test_two_encryptions_of_same_plaintext_differ(): void {
		// Random IV per encrypt → ciphertext differs across calls even when
		// plaintext is identical. Important property for not leaking equality.
		$store = new TGB_Token_Store();
		$store->set_api_key( 'same_value' );
		$first = (array) get_option( TGB_Token_Store::OPTION_KEY, array() );
		$store->set_api_key( 'same_value' );
		$second = (array) get_option( TGB_Token_Store::OPTION_KEY, array() );

		$this->assertNotSame(
			$first['api_key_blob'],
			$second['api_key_blob'],
			'Random IV must produce different ciphertexts for the same plaintext.'
		);
	}

	public function test_corrupted_blob_returns_null_not_throws(): void {
		// Simulate AUTH_KEY rotation / blob corruption by writing garbage.
		update_option(
			TGB_Token_Store::OPTION_KEY,
			array( 'api_key_blob' => 'not-valid-base64-cipher!!!', 'updated_at' => time() ),
			false
		);

		$store = new TGB_Token_Store();
		$this->assertNull( $store->get_api_key() );
		$this->assertFalse( $store->is_configured() );
	}
}
