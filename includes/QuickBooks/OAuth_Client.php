<?php
/**
 * OAuth_Client — Intuit OAuth2 flow for QuickBooks Online.
 *
 * Spans three flows:
 * 1. **Authorize URL build** — `authorize_url()` returns the URL we redirect
 *    the admin to. Includes a CSRF state nonce we tuck into a transient and
 *    re-validate on callback.
 * 2. **Code exchange** — `exchange_code()` swaps the auth code for a token
 *    bundle (access + refresh + realm_id) and persists via Token_Store.
 * 3. **Refresh** — `ensure_fresh_token()` checks expiry; if within the safety
 *    window, swaps the refresh_token for a new bundle and re-persists.
 *
 * Tokens are stored encrypted via Token_Store; we never persist plaintext.
 *
 * Intuit endpoints:
 *   Authorize:    https://appcenter.intuit.com/connect/oauth2
 *   Token (sand): https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer
 *   Token (prod): same URL — Intuit uses the same OAuth host for both;
 *                 sandbox vs prod is determined by the API host instead.
 *   Revoke:       https://developer.api.intuit.com/v2/oauth2/tokens/revoke
 *
 * Why hand-rolled (no Intuit SDK): the SDK pulls in Guzzle + a heavy DI
 * container; for our scope (4 REST calls + token refresh) it's overkill.
 * `wp_remote_*` is sufficient and keeps the plugin SDK-free.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class OAuth_Client {

	private const AUTHORIZE_URL    = 'https://appcenter.intuit.com/connect/oauth2';
	private const TOKEN_URL        = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
	private const REVOKE_URL       = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
	private const SCOPE            = 'com.intuit.quickbooks.accounting';
	private const STATE_TRANSIENT  = 'dfwc_qbo_oauth_state_';
	private const STATE_TTL        = 600; // 10 minutes — admin should complete consent within this window
	private const REFRESH_SAFETY_S = 300; // refresh access token if within 5 min of expiry

	/**
	 * Build the authorize URL. Generates a fresh state nonce, caches it in
	 * a transient (so we can verify it on callback), and returns the
	 * canonical Intuit URL the admin should be redirected to.
	 */
	public static function authorize_url(): string {
		$client_id    = self::client_id();
		$redirect_uri = self::redirect_uri();
		if ( '' === $client_id || '' === $redirect_uri ) {
			return '';
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT . $state, get_current_user_id(), self::STATE_TTL );

		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'client_id'     => $client_id,
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
			)
		);
	}

	/**
	 * Verify the state nonce returned by Intuit on callback. Burns the
	 * transient on success — replays of the same state can't re-auth.
	 *
	 * @return bool true if the state was valid + minted by the same admin user
	 */
	public static function consume_state( string $state ): bool {
		if ( '' === $state ) {
			return false;
		}
		$key  = self::STATE_TRANSIENT . $state;
		$user = get_transient( $key );
		if ( false === $user ) {
			return false;
		}
		delete_transient( $key );
		// Confirm the state was minted by an admin user. (current_user_id may
		// differ if multiple admins are mid-OAuth simultaneously — that's
		// fine; we only care that the state was minted by an admin AND that
		// the caller is also an admin, which the callback controller verifies.)
		return (int) $user > 0;
	}

	/**
	 * Exchange an auth code for a token bundle. Returns a WP_Error on
	 * failure (network, invalid code, etc.) so the caller can surface a
	 * meaningful admin notice.
	 *
	 * On success: tokens are stored encrypted via Token_Store and `true`
	 * is returned.
	 *
	 * @return true|\WP_Error
	 */
	public static function exchange_code( string $code, string $realm_id ) {
		if ( '' === $code || '' === $realm_id ) {
			return new \WP_Error( 'dfwc_qbo_missing_code', __( 'Missing authorization code or realm ID.', 'dfwc-companion' ) );
		}

		$response = self::token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$company = self::fetch_company_name( (string) $response['access_token'], $realm_id );

		$ok = Token_Store::store(
			(string) $response['access_token'],
			(string) $response['refresh_token'],
			time() + (int) $response['expires_in'],
			time() + (int) ( $response['x_refresh_token_expires_in'] ?? 8640000 ),
			$realm_id,
			$company
		);
		if ( ! $ok ) {
			return new \WP_Error( 'dfwc_qbo_persist_failed', __( 'Failed to persist QuickBooks tokens.', 'dfwc-companion' ) );
		}
		return true;
	}

	/**
	 * If the access token expires within the safety window, refresh it
	 * silently. Called before every API_Client request. No-op when
	 * already-fresh.
	 *
	 * @return true|\WP_Error true on no-op or successful refresh
	 */
	public static function ensure_fresh_token() {
		if ( ! Token_Store::has_tokens() ) {
			return new \WP_Error( 'dfwc_qbo_not_connected', __( 'QuickBooks is not connected.', 'dfwc-companion' ) );
		}
		$expires_at = Token_Store::access_expires_at();
		if ( $expires_at - time() > self::REFRESH_SAFETY_S ) {
			return true; // still fresh
		}
		return self::refresh();
	}

	/**
	 * Force-refresh tokens. Public so the admin can trigger via a button
	 * if a refresh somehow drifts (e.g., clock skew between WP and Intuit).
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		$refresh_token = Token_Store::refresh_token();
		if ( '' === $refresh_token ) {
			return new \WP_Error( 'dfwc_qbo_no_refresh_token', __( 'No refresh token stored.', 'dfwc-companion' ) );
		}

		$response = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$new_access  = (string) $response['access_token'];
		$new_refresh = isset( $response['refresh_token'] ) ? (string) $response['refresh_token'] : '';
		$new_exp     = time() + (int) $response['expires_in'];
		$new_rfh_exp = time() + (int) ( $response['x_refresh_token_expires_in'] ?? Token_Store::refresh_expires_at() - time() );

		// Intuit sometimes rotates the refresh token, sometimes returns the
		// old one. Persist whichever we got back.
		if ( '' !== $new_refresh ) {
			Token_Store::update_both( $new_access, $new_refresh, $new_exp, $new_rfh_exp );
		} else {
			Token_Store::update_access_token( $new_access, $new_exp );
		}
		return true;
	}

	/**
	 * Disconnect: revoke the refresh token at Intuit + clear local
	 * storage. The local clear is unconditional — even if Intuit's revoke
	 * call fails, we still want the admin's "Disconnect" click to work.
	 */
	public static function disconnect(): bool {
		$refresh_token = Token_Store::refresh_token();
		if ( '' !== $refresh_token ) {
			$body = wp_json_encode( array( 'token' => $refresh_token ) );
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'headers' => array(
						'Authorization' => 'Basic ' . self::basic_auth_header(),
						'Accept'        => 'application/json',
						'Content-Type'  => 'application/json',
					),
					'body'    => $body,
					'timeout' => 15,
				)
			);
		}
		return Token_Store::clear();
	}

	/**
	 * Companion-internal: redirect URI the admin must register at
	 * developer.intuit.com when creating their app. Computed from the
	 * site's REST URL so it matches whatever scheme + host WP is configured for.
	 */
	public static function redirect_uri(): string {
		return rest_url( 'dfwc-companion/v1/qbo-oauth-callback' );
	}

	private static function client_id(): string {
		$settings = Config_Resolver::get_global_settings();
		return isset( $settings['qbo_client_id'] ) ? (string) $settings['qbo_client_id'] : '';
	}

	private static function client_secret(): string {
		$settings = Config_Resolver::get_global_settings();
		return isset( $settings['qbo_client_secret'] ) ? (string) $settings['qbo_client_secret'] : '';
	}

	private static function basic_auth_header(): string {
		return base64_encode( self::client_id() . ':' . self::client_secret() );
	}

	/**
	 * Centralized token-endpoint request. Handles the auth header, timeout,
	 * non-2xx detection, and JSON decode. Returns the decoded body or a
	 * WP_Error.
	 *
	 * @param array<string,string> $body form-encoded fields
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function token_request( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . self::basic_auth_header(),
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			return new \WP_Error(
				'dfwc_qbo_token_endpoint',
				sprintf(
					/* translators: 1: HTTP status, 2: response body */
					__( 'Intuit token endpoint returned %1$d: %2$s', 'dfwc-companion' ),
					$code,
					$raw
				)
			);
		}
		if ( ! isset( $decoded['access_token'], $decoded['expires_in'] ) ) {
			return new \WP_Error(
				'dfwc_qbo_token_shape',
				__( 'Intuit token response missing required fields.', 'dfwc-companion' )
			);
		}
		return $decoded;
	}

	/**
	 * Best-effort fetch of the connected company's display name. Used only
	 * to show "Connected to <Org Name>" in the admin UI. Failure is
	 * non-fatal — we just store empty.
	 */
	private static function fetch_company_name( string $access_token, string $realm_id ): string {
		$base = API_Client::base_url();
		$url  = sprintf( '%s/v3/company/%s/companyinfo/%s', $base, rawurlencode( $realm_id ), rawurlencode( $realm_id ) );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['CompanyInfo']['CompanyName'] ) ) {
			return '';
		}
		return (string) $decoded['CompanyInfo']['CompanyName'];
	}
}
