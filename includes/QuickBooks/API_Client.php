<?php
/**
 * API_Client — narrow QBO REST surface used by the sync handler + admin UI.
 *
 * Three endpoints in scope for v2.0.0:
 *   - POST /v3/company/{realmId}/salesreceipt — create donation receipt
 *   - GET  /v3/company/{realmId}/account?...  — list income accounts (mapping UI)
 *   - GET  /v3/company/{realmId}/companyinfo/{realmId} — display name (admin status)
 *
 * Refund handling is out of scope for v2.0.0 — flagged as v2.1+ in
 * `docs/quickbooks-sync.md`. Listing it here would imply support that
 * doesn't exist.
 *
 * Every public method calls `OAuth_Client::ensure_fresh_token()` first
 * so callers never have to think about token expiry. On 401 the access
 * token is force-refreshed once and the request retried; a second 401
 * surfaces as a WP_Error.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class API_Client {

	private const BASE_PROD    = 'https://quickbooks.api.intuit.com';
	private const BASE_SANDBOX = 'https://sandbox-quickbooks.api.intuit.com';
	private const MINOR_VER    = '70'; // pin to a known-good API minor version

	/**
	 * Base URL for the configured environment. Public so OAuth_Client +
	 * other helpers can reuse without duplicating the env-switch logic.
	 */
	public static function base_url(): string {
		$settings = Config_Resolver::get_global_settings();
		$env      = isset( $settings['qbo_environment'] ) ? (string) $settings['qbo_environment'] : 'production';
		return 'sandbox' === $env ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	/**
	 * Create a Sales Receipt for a single donation. Caller assembles the
	 * payload (amount, account ID, currency, customer ref).
	 *
	 * Returns the QBO receipt ID on success, WP_Error on failure.
	 *
	 * @param array<string,mixed> $payload sales-receipt fields
	 * @return string|\WP_Error QBO receipt ID
	 */
	public static function create_sales_receipt( array $payload ) {
		$realm_id = Token_Store::realm_id();
		if ( '' === $realm_id ) {
			return new \WP_Error( 'dfwc_qbo_no_realm', __( 'QuickBooks is not connected.', 'dfwc-companion' ) );
		}

		$path = sprintf( '/v3/company/%s/salesreceipt', rawurlencode( $realm_id ) );
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'dfwc_qbo_payload_encode', __( 'Failed to encode receipt payload.', 'dfwc-companion' ) );
		}

		$response = self::request( 'POST', $path, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response['SalesReceipt']['Id'] ) ) {
			return new \WP_Error(
				'dfwc_qbo_receipt_no_id',
				__( 'QuickBooks accepted the request but returned no receipt ID.', 'dfwc-companion' )
			);
		}
		return (string) $response['SalesReceipt']['Id'];
	}

	/**
	 * List active Income accounts, used by the admin mapping UI. Returns
	 * `[ id => display_name, ... ]`. QBO's `query` endpoint expects a SQL-
	 * like string; we hand-roll a narrow allow-listed query.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function list_income_accounts() {
		$realm_id = Token_Store::realm_id();
		if ( '' === $realm_id ) {
			return new \WP_Error( 'dfwc_qbo_no_realm', __( 'QuickBooks is not connected.', 'dfwc-companion' ) );
		}

		// SELECT all active Income accounts. Hard-cap MAXRESULTS at 200 so a
		// pathological book with thousands of accounts can't pin the admin UI.
		$query = "SELECT Id, Name, AccountType FROM Account WHERE AccountType = 'Income' AND Active = true MAXRESULTS 200";
		$path  = sprintf(
			'/v3/company/%s/query?query=%s',
			rawurlencode( $realm_id ),
			rawurlencode( $query )
		);

		$response = self::request( 'GET', $path );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$accounts = $response['QueryResponse']['Account'] ?? array();
		if ( ! is_array( $accounts ) ) {
			return array();
		}
		$out = array();
		foreach ( $accounts as $account ) {
			if ( isset( $account['Id'], $account['Name'] ) ) {
				$out[ (string) $account['Id'] ] = (string) $account['Name'];
			}
		}
		return $out;
	}

	/**
	 * Fetch the connected company's display name. Optional — used to
	 * refresh the cached name when the admin clicks "Refresh status".
	 *
	 * @return string|\WP_Error
	 */
	public static function fetch_company_name() {
		$realm_id = Token_Store::realm_id();
		if ( '' === $realm_id ) {
			return new \WP_Error( 'dfwc_qbo_no_realm', __( 'QuickBooks is not connected.', 'dfwc-companion' ) );
		}
		$path     = sprintf( '/v3/company/%s/companyinfo/%s', rawurlencode( $realm_id ), rawurlencode( $realm_id ) );
		$response = self::request( 'GET', $path );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return (string) ( $response['CompanyInfo']['CompanyName'] ?? '' );
	}

	/**
	 * Internal request wrapper. Ensures the access token is fresh, makes
	 * the call, and on 401 force-refreshes once and retries (covers the
	 * edge case where Intuit invalidates a token before its declared
	 * expiry — rare but documented).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function request( string $method, string $path, ?string $body = null, bool $is_retry = false ) {
		$fresh = OAuth_Client::ensure_fresh_token();
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$url  = self::base_url() . $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'minorversion=' . self::MINOR_VER;
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . Token_Store::access_token(),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		// 401 → force-refresh + retry once. After that, real failure.
		if ( 401 === $code && ! $is_retry ) {
			$refreshed = OAuth_Client::refresh();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return self::request( $method, $path, $body, true );
		}

		$decoded = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'dfwc_qbo_api_error',
				sprintf(
					/* translators: 1: HTTP status, 2: error body */
					__( 'QuickBooks API returned %1$d: %2$s', 'dfwc-companion' ),
					$code,
					self::summarize_error( $decoded, $raw )
				),
				array( 'status' => $code )
			);
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Pull a short error summary out of QBO's verbose error payload.
	 * Falls back to the raw response when the shape's unfamiliar.
	 *
	 * @param mixed  $decoded JSON-decoded body
	 * @param string $raw     raw body
	 */
	private static function summarize_error( $decoded, string $raw ): string {
		if ( is_array( $decoded ) && isset( $decoded['Fault']['Error'][0]['Message'] ) ) {
			$msg = (string) $decoded['Fault']['Error'][0]['Message'];
			if ( isset( $decoded['Fault']['Error'][0]['Detail'] ) ) {
				$msg .= ' — ' . (string) $decoded['Fault']['Error'][0]['Detail'];
			}
			return $msg;
		}
		return strlen( $raw ) > 500 ? substr( $raw, 0, 500 ) . '…' : $raw;
	}
}
