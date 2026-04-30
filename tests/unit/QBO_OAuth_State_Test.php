<?php
/**
 * Unit tests for the OAuth state CSRF guard in QuickBooks\OAuth_Client.
 *
 * Token exchange + refresh hit Intuit's live endpoint and aren't testable
 * in isolation. The state-nonce flow IS pure logic against transients
 * + current_user_id, which the bootstrap stubs cleanly.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\QuickBooks\OAuth_Client;
use PHPUnit\Framework\TestCase;

final class QBO_OAuth_State_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
		// Pretend we have an admin logged in; OAuth_Client::authorize_url
		// reads get_current_user_id and stores it in the transient.
		$GLOBALS['_dfwc_test_current_user_id'] = 1;
	}

	public function test_consume_state_rejects_empty(): void {
		$this->assertFalse( OAuth_Client::consume_state( '' ) );
	}

	public function test_consume_state_rejects_unknown(): void {
		$this->assertFalse( OAuth_Client::consume_state( 'never-minted' ) );
	}

	public function test_authorize_url_then_consume_state_succeeds_once(): void {
		// authorize_url() requires client_id + redirect_uri to be configured
		// or it returns ''. Seed the global option with a client_id; the
		// rest_url stub provides the redirect URI.
		update_option(
			'dfwc_companion_global_settings',
			array( 'qbo_client_id' => 'test-client-id', 'qbo_client_secret' => 'shh' )
		);

		$url = OAuth_Client::authorize_url();
		$this->assertNotSame( '', $url );

		// Pull the state out of the URL.
		$query = parse_url( $url, PHP_URL_QUERY );
		parse_str( (string) $query, $params );
		$state = (string) ( $params['state'] ?? '' );
		$this->assertNotSame( '', $state );

		// First consume succeeds + burns the transient.
		$this->assertTrue( OAuth_Client::consume_state( $state ) );
		// Replay → false (single-use).
		$this->assertFalse( OAuth_Client::consume_state( $state ) );
	}

	public function test_authorize_url_returns_empty_when_credentials_missing(): void {
		// No client_id stored → authorize_url returns '' so the admin UI
		// can show a friendly "configure credentials first" notice rather
		// than redirecting to a malformed Intuit URL.
		$url = OAuth_Client::authorize_url();
		$this->assertSame( '', $url );
	}

	public function test_authorize_url_includes_correct_scope(): void {
		update_option(
			'dfwc_companion_global_settings',
			array( 'qbo_client_id' => 'cid', 'qbo_client_secret' => 'csec' )
		);
		$url = OAuth_Client::authorize_url();
		$this->assertStringContainsString( 'scope=' . rawurlencode( 'com.intuit.quickbooks.accounting' ), $url );
		$this->assertStringContainsString( 'response_type=code', $url );
	}
}
