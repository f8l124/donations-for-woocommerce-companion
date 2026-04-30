<?php
/**
 * QBO_OAuth_Callback_Controller — landing endpoint for Intuit's OAuth redirect.
 *
 * `GET /wp-json/dfwc-companion/v1/qbo-oauth-callback`
 *
 * Intuit redirects the admin back here with `?code=&state=&realmId=` after
 * they consent. We:
 *   1. Verify the admin is logged-in + has `manage_options` (the redirect
 *      lands in their browser, so the WP cookie should be there).
 *   2. Verify the `state` matches a transient we minted in
 *      `OAuth_Client::authorize_url()` and burn it (single-use).
 *   3. Exchange the code for tokens via `OAuth_Client::exchange_code()`.
 *   4. Redirect back to the QuickBooks admin page with success / error
 *      status surfaced as a query param (turned into a notice).
 *
 * Why a REST route and not admin-post.php: Intuit's developer portal lets
 * you register multiple redirect URIs but the convention in WP plugins is
 * to use a REST endpoint so the URL is deterministic + namespaced. The
 * REST route is registered with `permission_callback` that requires admin
 * cap; a non-admin who somehow lands here just gets a 401, no token leak.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\QuickBooks\OAuth_Client;

final class QBO_OAuth_Callback_Controller {

	private const NAMESPACE_BASE = 'dfwc-companion/v1';
	private const ROUTE          = '/qbo-oauth-callback';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_BASE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'check_permission' ),
				'callback'            => array( $this, 'handle_callback' ),
			)
		);
	}

	/**
	 * Only admins should land here — Intuit puts the redirect in the
	 * admin's own browser so the WP cookie is present. A direct hit by
	 * an attacker without the cookie gets 401.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function handle_callback( \WP_REST_Request $request ) {
		$code     = (string) $request->get_param( 'code' );
		$state    = (string) $request->get_param( 'state' );
		$realm_id = (string) $request->get_param( 'realmId' );
		$error    = (string) $request->get_param( 'error' );

		// Intuit returns ?error=access_denied if the admin cancels the
		// consent screen. Surface that cleanly rather than as a generic fail.
		if ( '' !== $error ) {
			return self::redirect_with(
				'error',
				sprintf(
					/* translators: %s: Intuit's error code (e.g., access_denied) */
					__( 'QuickBooks connection cancelled or denied: %s', 'dfwc-companion' ),
					$error
				)
			);
		}

		if ( ! OAuth_Client::consume_state( $state ) ) {
			return self::redirect_with(
				'error',
				__( 'OAuth state mismatch — please try connecting again.', 'dfwc-companion' )
			);
		}

		$result = OAuth_Client::exchange_code( $code, $realm_id );
		if ( is_wp_error( $result ) ) {
			return self::redirect_with(
				'error',
				$result->get_error_message()
			);
		}

		return self::redirect_with(
			'connected',
			__( 'QuickBooks connected.', 'dfwc-companion' )
		);
	}

	private static function redirect_with( string $status, string $message ): \WP_REST_Response {
		$url = add_query_arg(
			array(
				'page'       => Admin_Menu::QBO_PAGE_SLUG,
				'qbo_status' => rawurlencode( $status ),
				'qbo_msg'    => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);
		$response = new \WP_REST_Response();
		$response->set_status( 302 );
		$response->header( 'Location', $url );
		return $response;
	}
}
