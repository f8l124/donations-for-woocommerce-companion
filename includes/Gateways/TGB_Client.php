<?php
/**
 * TGB_Client — minimal REST client for The Giving Block API.
 *
 * Endpoints implemented at v2.3.0:
 *   ping( )                       → GET /v1/organization                — auth check, fetch org name
 *   list_projects( )              → GET /v1/projects                    — for per-campaign routing dropdown
 *   create_donation_intent( ... ) → POST /v1/donations                  — used by donor-side widget bridge
 *   get_donation( $tgb_id )       → GET /v1/donations/{id}              — for webhook reconciliation
 *
 * Uses wp_remote_post / wp_remote_get with 15s timeout. Constructor takes an
 * optional HTTP-callable (closure) so unit tests can inject a mock without
 * stubbing wp_remote_*.
 *
 * Authorization: Bearer <api_key>. The API key is supplied at construction
 * time (resolved by the caller from TGB_Token_Store) so the client itself
 * never touches credential decryption — single responsibility.
 *
 * Environment switching: the constructor takes a `$environment` string
 * ('sandbox' | 'production') and selects base URL accordingly. Production
 * is `https://api.tgbwidget.com/v1`; sandbox is the corresponding TGB
 * sandbox host. URLs are filterable via `dfwc_companion_tgb_base_url` so
 * admins on TGB-hosted private deployments can override.
 *
 * Returned shape: every method returns `[ 'ok' => bool, 'data' => array,
 * 'error' => string ]`. Callers branch on `ok` rather than catching
 * exceptions — keeps error handling explicit at every call site.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

final class TGB_Client {

	public const ENV_SANDBOX    = 'sandbox';
	public const ENV_PRODUCTION = 'production';

	private const BASE_URL_PRODUCTION = 'https://api.tgbwidget.com/v1';
	private const BASE_URL_SANDBOX    = 'https://api.sandbox.tgbwidget.com/v1';
	private const TIMEOUT_SECONDS     = 15;

	private string $api_key;
	private string $environment;

	/**
	 * Optional HTTP callable for tests. Signature:
	 *   fn( string $method, string $url, array $args ) : array{ ok: bool, status: int, body: string, error: string }
	 *
	 * @var (callable(string,string,array):array)|null
	 */
	private $http_callable;

	public function __construct( string $api_key, string $environment = self::ENV_SANDBOX, ?callable $http_callable = null ) {
		$this->api_key       = $api_key;
		$this->environment   = self::ENV_PRODUCTION === $environment ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
		$this->http_callable = $http_callable;
	}

	public function environment(): string {
		return $this->environment;
	}

	/**
	 * Auth check. Calls /organization which returns the org's display name +
	 * id when the API key is valid. Used by the Settings page "Test
	 * connection" button + the diagnostics tgb_connection check.
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string}
	 */
	public function ping(): array {
		return $this->request( 'GET', '/organization' );
	}

	/**
	 * Fetch the org's project list for the per-campaign routing dropdown.
	 * Cache the result at the call-site (5-min transient is the convention).
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string}
	 */
	public function list_projects(): array {
		return $this->request( 'GET', '/projects' );
	}

	/**
	 * Create a donation intent — used when the donor commits in the widget.
	 * Returns TGB's donation_id which we attach to the pending WC order's
	 * line-item meta for webhook reconciliation.
	 *
	 * @param array<string,mixed> $payload Whatever fields the widget passes
	 *                                     through (currency, amount, project_id,
	 *                                     donor_email_hashed?, etc.).
	 * @return array{ok:bool,data:array<string,mixed>,error:string}
	 */
	public function create_donation_intent( array $payload ): array {
		return $this->request( 'POST', '/donations', $payload );
	}

	/**
	 * Reconcile a donation by TGB id. Used by the webhook handler when the
	 * payload arrives but we want to verify against TGB's source-of-truth
	 * before flipping order status (defense against forged payloads even
	 * after HMAC passes — belt-and-suspenders).
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string}
	 */
	public function get_donation( string $tgb_donation_id ): array {
		return $this->request( 'GET', '/donations/' . rawurlencode( $tgb_donation_id ) );
	}

	/**
	 * @param string              $method GET or POST
	 * @param string              $path   Path starting with '/'
	 * @param array<string,mixed> $body   POST body (JSON-encoded); ignored for GET
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string}
	 */
	private function request( string $method, string $path, array $body = array() ): array {
		$url  = $this->base_url() . $path;
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'dfwc-companion/' . ( defined( 'DFWC_COMPANION_VERSION' ) ? DFWC_COMPANION_VERSION : 'dev' ),
			),
		);

		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = $this->dispatch( $method, $url, $args );

		if ( ! $response['ok'] ) {
			return array(
				'ok'    => false,
				'data'  => array(),
				'error' => '' !== $response['error'] ? $response['error'] : ( 'TGB API HTTP ' . $response['status'] ),
			);
		}

		$decoded = json_decode( $response['body'], true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'    => false,
				'data'  => array(),
				'error' => 'TGB API returned non-JSON response',
			);
		}

		if ( $response['status'] >= 400 ) {
			$error_msg = isset( $decoded['error'] ) ? (string) $decoded['error'] : 'TGB API HTTP ' . $response['status'];
			return array(
				'ok'    => false,
				'data'  => $decoded,
				'error' => $error_msg,
			);
		}

		return array(
			'ok'    => true,
			'data'  => $decoded,
			'error' => '',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array{ok:bool,status:int,body:string,error:string}
	 */
	private function dispatch( string $method, string $url, array $args ): array {
		if ( null !== $this->http_callable ) {
			return ( $this->http_callable )( $method, $url, $args );
		}

		$response = ( 'POST' === $method )
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}

		return array(
			'ok'     => true,
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => '',
		);
	}

	private function base_url(): string {
		$default = ( self::ENV_PRODUCTION === $this->environment )
			? self::BASE_URL_PRODUCTION
			: self::BASE_URL_SANDBOX;

		return (string) apply_filters( 'dfwc_companion_tgb_base_url', $default, $this->environment );
	}
}
