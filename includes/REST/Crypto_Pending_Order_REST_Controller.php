<?php
/**
 * Crypto_Pending_Order_REST_Controller — donor-facing endpoint that records
 * a crypto donation as an on-hold WC order at the moment the donor commits
 * in The Giving Block widget.
 *
 * `POST /wp-json/dfwc-companion/v1/crypto-pending-order`
 *
 * Donor-side dfwc-crypto.js calls this when TGB's widget fires its commit
 * callback. The endpoint:
 *
 * 1. Feature-gates on `crypto_donations_enabled` + Token_Store::is_configured.
 *    404 (not 403) when off so URL scrapers don't learn the endpoint exists.
 * 2. Rate-limits per IP (10 req/min — generous for legitimate donors).
 * 3. Validates input via TGB_Pending_Order::sanitize_payload().
 * 4. Calls TGB_Pending_Order::create() — idempotent, race-safe with the
 *    webhook handler (13.D).
 * 5. Returns 200 + order_id on success, 422 on validation, 500 on
 *    persistence failure.
 *
 * Permission: `__return_true` (public). Donors don't authenticate to give.
 * Defense: rate limit + strict input validation + idempotency. The forged
 * worst case is a phantom on-hold order with a fake donation_id, which
 * the webhook handler later refuses to flip to completed because TGB has
 * no matching record.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Gateways\TGB_Pending_Order;
use DFWC\Companion\Gateways\TGB_Token_Store;

final class Crypto_Pending_Order_REST_Controller {

	private const NAMESPACE_BASE = 'dfwc-companion/v1';
	private const ROUTE          = '/crypto-pending-order';
	private const RATE_LIMIT_TTL = 60;
	private const RATE_LIMIT_MAX = 10;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_BASE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_pending_order' ),
				'args'                => array(
					'donation_id'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'campaign_id'   => array(
						'required' => true,
						'type'     => 'integer',
					),
					'project_id'    => array(
						'required' => false,
						'type'     => 'string',
					),
					'currency'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'amount_crypto' => array(
						'required' => false,
						'type'     => 'number',
					),
					'amount_usd'    => array(
						'required' => true,
						'type'     => 'number',
					),
				),
			)
		);
	}

	public function handle_pending_order( \WP_REST_Request $request ): \WP_REST_Response {
		$global = Config_Resolver::get_global_settings();
		if ( empty( $global['crypto_donations_enabled'] ) || ! ( new TGB_Token_Store() )->is_configured() ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! $this->within_rate_limit( $request ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$raw = array(
			'donation_id'   => $request->get_param( 'donation_id' ),
			'campaign_id'   => $request->get_param( 'campaign_id' ),
			'project_id'    => $request->get_param( 'project_id' ),
			'currency'      => $request->get_param( 'currency' ),
			'amount_crypto' => $request->get_param( 'amount_crypto' ),
			'amount_usd'    => $request->get_param( 'amount_usd' ),
		);

		$sanitized = TGB_Pending_Order::sanitize_payload( $raw );
		if ( empty( $sanitized['ok'] ) ) {
			return new \WP_REST_Response(
				array(
					'error'  => 'validation_failed',
					'fields' => $sanitized['errors'] ?? array(),
				),
				422
			);
		}

		$result = TGB_Pending_Order::create( $sanitized['data'] );
		if ( $result instanceof \WP_Error ) {
			return new \WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		// Detect whether the order was just created or returned by
		// idempotency — useful for clients but not load-bearing.
		$existing = TGB_Pending_Order::find_existing_by_donation_id(
			(string) $sanitized['data']['donation_id']
		);
		$created  = ( $existing === (int) $result );

		return new \WP_REST_Response(
			array(
				'order_id'     => (int) $result,
				'status'       => 'on-hold',
				'idempotent'   => ! $created || null === $existing,
			),
			201
		);
	}

	private function within_rate_limit( \WP_REST_Request $request ): bool {
		$ip = $request->get_header( 'x_forwarded_for' );
		if ( empty( $ip ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}
		$key   = 'dfwc_crypto_pending_rl_' . wp_hash( (string) $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}
}
