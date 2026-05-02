<?php
/**
 * Unit tests for Gateways\TGB_Client.
 *
 * The client takes an injected HTTP callable in its constructor so tests
 * never touch wp_remote_*. Each test stitches a fake HTTP response and
 * asserts the client's parsing + error handling.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Gateways\TGB_Client;
use PHPUnit\Framework\TestCase;

final class TGB_Client_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	private function fake_http( int $status, string $body, string $error = '' ): callable {
		return static function ( $method, $url, $args ) use ( $status, $body, $error ) {
			return array(
				'ok'     => '' === $error,
				'status' => $status,
				'body'   => $body,
				'error'  => $error,
			);
		};
	}

	public function test_environment_defaults_to_sandbox(): void {
		$client = new TGB_Client( 'k' );
		$this->assertSame( TGB_Client::ENV_SANDBOX, $client->environment() );
	}

	public function test_environment_invalid_value_falls_back_to_sandbox(): void {
		$client = new TGB_Client( 'k', 'mainnet' );
		$this->assertSame( TGB_Client::ENV_SANDBOX, $client->environment() );
	}

	public function test_environment_production_is_respected(): void {
		$client = new TGB_Client( 'k', TGB_Client::ENV_PRODUCTION );
		$this->assertSame( TGB_Client::ENV_PRODUCTION, $client->environment() );
	}

	public function test_ping_success_returns_decoded_data(): void {
		$client = new TGB_Client(
			'api_key_xyz',
			TGB_Client::ENV_SANDBOX,
			$this->fake_http( 200, '{"id":"org_1","name":"Test Org"}' )
		);

		$result = $client->ping();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Test Org', $result['data']['name'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_ping_http_failure_returns_error(): void {
		$client = new TGB_Client(
			'k',
			TGB_Client::ENV_SANDBOX,
			$this->fake_http( 0, '', 'Connection timed out' )
		);

		$result = $client->ping();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Connection timed out', $result['error'] );
		$this->assertSame( array(), $result['data'] );
	}

	public function test_ping_4xx_response_with_json_error_propagates(): void {
		$client = new TGB_Client(
			'bad_key',
			TGB_Client::ENV_SANDBOX,
			$this->fake_http( 401, '{"error":"Invalid API key"}' )
		);

		$result = $client->ping();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Invalid API key', $result['error'] );
	}

	public function test_ping_500_response_falls_back_to_status_message(): void {
		$client = new TGB_Client(
			'k',
			TGB_Client::ENV_SANDBOX,
			$this->fake_http( 500, '{"unexpected":"shape"}' )
		);

		$result = $client->ping();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '500', $result['error'] );
	}

	public function test_non_json_response_is_rejected(): void {
		$client = new TGB_Client(
			'k',
			TGB_Client::ENV_SANDBOX,
			$this->fake_http( 200, '<html>not json</html>' )
		);

		$result = $client->ping();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'non-JSON', $result['error'] );
	}

	public function test_create_donation_intent_sends_post_with_body(): void {
		$captured = array();
		$client   = new TGB_Client(
			'k',
			TGB_Client::ENV_SANDBOX,
			static function ( $method, $url, $args ) use ( &$captured ) {
				$captured = compact( 'method', 'url', 'args' );
				return array( 'ok' => true, 'status' => 201, 'body' => '{"id":"don_abc"}', 'error' => '' );
			}
		);

		$result = $client->create_donation_intent(
			array( 'currency' => 'BTC', 'amount' => 0.01 )
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'don_abc', $result['data']['id'] );
		$this->assertSame( 'POST', $captured['method'] );
		$this->assertStringContainsString( '/donations', $captured['url'] );
		$this->assertSame(
			'application/json',
			$captured['args']['headers']['Content-Type']
		);
		$this->assertStringContainsString( '"currency":"BTC"', $captured['args']['body'] );
	}

	public function test_authorization_header_carries_bearer_token(): void {
		$captured_args = array();
		$client        = new TGB_Client(
			'my_secret_token',
			TGB_Client::ENV_SANDBOX,
			static function ( $method, $url, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array( 'ok' => true, 'status' => 200, 'body' => '{}', 'error' => '' );
			}
		);

		$client->ping();
		$this->assertSame( 'Bearer my_secret_token', $captured_args['headers']['Authorization'] );
	}

	public function test_get_donation_url_encodes_id(): void {
		$captured_url = '';
		$client       = new TGB_Client(
			'k',
			TGB_Client::ENV_SANDBOX,
			static function ( $method, $url, $args ) use ( &$captured_url ) {
				$captured_url = $url;
				return array( 'ok' => true, 'status' => 200, 'body' => '{}', 'error' => '' );
			}
		);

		$client->get_donation( 'don id with spaces' );
		$this->assertStringContainsString( 'don%20id%20with%20spaces', $captured_url );
	}

	public function test_sandbox_and_production_use_different_base_urls(): void {
		$capture_for = static function ( string $env ) {
			$captured = '';
			$client   = new TGB_Client(
				'k',
				$env,
				static function ( $method, $url, $args ) use ( &$captured ) {
					$captured = $url;
					return array( 'ok' => true, 'status' => 200, 'body' => '{}', 'error' => '' );
				}
			);
			$client->ping();
			return $captured;
		};

		$sandbox_url    = $capture_for( TGB_Client::ENV_SANDBOX );
		$production_url = $capture_for( TGB_Client::ENV_PRODUCTION );
		$this->assertNotSame( $sandbox_url, $production_url );
		$this->assertStringContainsString( 'sandbox', $sandbox_url );
	}
}
