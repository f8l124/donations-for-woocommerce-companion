<?php
/**
 * Unit tests for QuickBooks\Token_Store.
 *
 * Focus: round-trip encrypt/decrypt, individual setters, and the privacy
 * guarantee that ciphertext is NOT plaintext.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\QuickBooks\Token_Store;
use PHPUnit\Framework\TestCase;

final class QBO_Token_Store_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_initial_state_has_no_tokens(): void {
		$this->assertFalse( Token_Store::has_tokens() );
		$this->assertSame( '', Token_Store::access_token() );
		$this->assertSame( '', Token_Store::refresh_token() );
		$this->assertSame( '', Token_Store::realm_id() );
	}

	public function test_store_and_round_trip(): void {
		$ok = Token_Store::store(
			'plain-access-token-abc123',
			'plain-refresh-token-xyz789',
			time() + 3600,
			time() + 8640000,
			'4620816365316892901',
			'Example Nonprofit Inc'
		);
		$this->assertTrue( $ok );
		$this->assertTrue( Token_Store::has_tokens() );
		$this->assertSame( 'plain-access-token-abc123', Token_Store::access_token() );
		$this->assertSame( 'plain-refresh-token-xyz789', Token_Store::refresh_token() );
		$this->assertSame( '4620816365316892901', Token_Store::realm_id() );
		$this->assertSame( 'Example Nonprofit Inc', Token_Store::company_name() );
	}

	public function test_stored_blob_does_not_contain_plaintext(): void {
		Token_Store::store(
			'plain-access-token-abc123',
			'plain-refresh-token-xyz789',
			time() + 3600,
			time() + 8640000,
			'4620816365316892901',
			'Example Nonprofit Inc'
		);
		// Read the raw option directly. Plaintext must NOT appear in the
		// stored value — that's the whole point of the encryption layer.
		$raw = serialize( get_option( Token_Store::OPTION_KEY ) );
		$this->assertStringNotContainsString( 'plain-access-token-abc123', $raw );
		$this->assertStringNotContainsString( 'plain-refresh-token-xyz789', $raw );
	}

	public function test_two_writes_produce_different_ciphertext(): void {
		// Same plaintext → different IV → different ciphertext. Defends
		// against an attacker who can compare two backups and derive
		// "did this token rotate?" purely from the stored bytes.
		Token_Store::store( 'same', 'same', 1, 1, 'same', '' );
		$first  = (string) get_option( Token_Store::OPTION_KEY )['access_token_enc'];
		Token_Store::store( 'same', 'same', 1, 1, 'same', '' );
		$second = (string) get_option( Token_Store::OPTION_KEY )['access_token_enc'];
		$this->assertNotSame( $first, $second );
	}

	public function test_update_access_token_preserves_refresh(): void {
		Token_Store::store( 'access1', 'refresh1', time() + 3600, time() + 8640000, 'r1', '' );
		$ok = Token_Store::update_access_token( 'access2', time() + 7200 );
		$this->assertTrue( $ok );
		$this->assertSame( 'access2', Token_Store::access_token() );
		$this->assertSame( 'refresh1', Token_Store::refresh_token() );
	}

	public function test_update_both_replaces_both(): void {
		Token_Store::store( 'access1', 'refresh1', time() + 3600, time() + 8640000, 'r1', '' );
		$ok = Token_Store::update_both( 'access2', 'refresh2', time() + 7200, time() + 9000000 );
		$this->assertTrue( $ok );
		$this->assertSame( 'access2', Token_Store::access_token() );
		$this->assertSame( 'refresh2', Token_Store::refresh_token() );
	}

	public function test_clear_wipes_state(): void {
		Token_Store::store( 'a', 'b', 1, 1, 'r', '' );
		Token_Store::clear();
		$this->assertFalse( Token_Store::has_tokens() );
		$this->assertSame( '', Token_Store::access_token() );
	}

	public function test_update_without_prior_store_returns_false(): void {
		$this->assertFalse( Token_Store::update_access_token( 'a', 1 ) );
		$this->assertFalse( Token_Store::update_both( 'a', 'b', 1, 1 ) );
	}

	public function test_corrupt_blob_decrypts_to_empty(): void {
		// Simulate manual tampering of wp_options: an attacker who can write
		// (not just read) could replace the ciphertext with garbage. Decrypt
		// must fail closed — return empty string, never throw.
		update_option(
			Token_Store::OPTION_KEY,
			array(
				'access_token_enc'   => 'not-real-base64!!!',
				'refresh_token_enc'  => 'also-not-real',
				'access_expires_at'  => time() + 3600,
				'refresh_expires_at' => time() + 8640000,
				'realm_id'           => 'r1',
				'company_name'       => '',
				'connected_at'       => time(),
			)
		);
		// has_tokens() returns true based on shape, but the access_token
		// returns empty since decrypt fails — caller treats that as "not connected".
		$this->assertTrue( Token_Store::has_tokens() );
		$this->assertSame( '', Token_Store::access_token() );
		$this->assertSame( '', Token_Store::refresh_token() );
	}
}
