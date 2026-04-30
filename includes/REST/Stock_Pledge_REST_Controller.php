<?php
/**
 * Stock_Pledge_REST_Controller — donor-facing pledge submission endpoint.
 *
 * `POST /wp-json/dfwc-companion/v1/stock-pledge`
 *
 * The donor-side pledge form posts a JSON payload here. The endpoint
 * sanitizes via `Stock_Pledge_Handler::sanitize_pledge_input`, persists
 * the pledge as a `dfwc_stock_pledge` custom post via `create_pledge`,
 * triggers the donor + admin email notifications, and returns a pledge
 * confirmation payload (id, DTC instructions for the donor's broker).
 *
 * Defenses (mirrors the Phase 9 `track` endpoint):
 *
 * - Permission `__return_true` (public — donors don't authenticate).
 * - Rate-limited: 5 pledges per IP per minute. Hashed-IP transient keys.
 * - Allow-list at the handler: every field validated against an explicit
 *   shape; unknown keys silently dropped.
 * - The `stock_donations_enabled` global setting gates the endpoint —
 *   off means the endpoint returns 404 (donors who scrape the URL get a
 *   404, not a clue that the feature exists but is disabled).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Stock\Stock_Pledge_Handler;
use DFWC\Companion\Stock\Stock_Pledge_Email_Sender;

final class Stock_Pledge_REST_Controller {

	private const NAMESPACE_BASE = 'dfwc-companion/v1';
	private const ROUTE          = '/stock-pledge';
	private const RATE_LIMIT_TTL = 60;  // seconds
	private const RATE_LIMIT_MAX = 5;   // pledges per IP per minute

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
				'callback'            => array( $this, 'handle_pledge' ),
				'args'                => array(
					'campaign_id'     => array(
						'required' => true,
						'type'     => 'integer',
					),
					'donor_name'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'donor_email'     => array(
						'required' => true,
						'type'     => 'string',
						'format'   => 'email',
					),
					'donor_phone'     => array(
						'required' => false,
						'type'     => 'string',
					),
					'broker_name'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'ticker'          => array(
						'required' => true,
						'type'     => 'string',
					),
					'shares'          => array(
						'required' => true,
						'type'     => 'number',
					),
					'estimated_value' => array(
						'required' => true,
						'type'     => 'number',
					),
					'donor_notes'     => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public function handle_pledge( \WP_REST_Request $request ): \WP_REST_Response {
		// Feature gate: stock donations must be enabled at the global level.
		// 404 (not 403) so a donor who scrapes the URL doesn't learn the
		// endpoint exists.
		$global = Config_Resolver::get_global_settings();
		if ( empty( $global['stock_donations_enabled'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! $this->within_rate_limit( $request ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$raw = array(
			'campaign_id'     => $request->get_param( 'campaign_id' ),
			'donor_name'      => $request->get_param( 'donor_name' ),
			'donor_email'     => $request->get_param( 'donor_email' ),
			'donor_phone'     => $request->get_param( 'donor_phone' ),
			'broker_name'     => $request->get_param( 'broker_name' ),
			'ticker'          => $request->get_param( 'ticker' ),
			'shares'          => $request->get_param( 'shares' ),
			'estimated_value' => $request->get_param( 'estimated_value' ),
			'donor_notes'     => $request->get_param( 'donor_notes' ),
		);

		$sanitized = Stock_Pledge_Handler::sanitize_pledge_input( $raw );
		if ( empty( $sanitized['ok'] ) ) {
			return new \WP_REST_Response(
				array(
					'error'  => 'validation_failed',
					'fields' => $sanitized['errors'] ?? array(),
				),
				422
			);
		}

		$pledge_id = Stock_Pledge_Handler::create_pledge( $sanitized['data'] );
		if ( $pledge_id instanceof \WP_Error ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'pledge_create_failed',
					'message' => $pledge_id->get_error_message(),
				),
				500
			);
		}

		// Fire-and-forget email notifications. Failures don't fail the
		// pledge creation — donor's pledge is recorded; admin can chase
		// missing emails out-of-band.
		if ( class_exists( '\\DFWC\\Companion\\Stock\\Stock_Pledge_Email_Sender' ) ) {
			Stock_Pledge_Email_Sender::send_donor_confirmation( (int) $pledge_id );
			Stock_Pledge_Email_Sender::send_admin_notification( (int) $pledge_id );
		}

		return new \WP_REST_Response(
			array(
				'pledge_id' => (int) $pledge_id,
				'status'    => 'pledged',
				'dtc_instructions' => array(
					'broker_name'             => (string) ( $global['stock_broker_name'] ?? '' ),
					'dtc_account_number'      => (string) ( $global['stock_dtc_account_number'] ?? '' ),
					'dtc_clearing_house_number' => (string) ( $global['stock_dtc_clearing_house_number'] ?? '' ),
				),
			),
			201
		);
	}

	/**
	 * Hash-the-IP rate limiter. Hashes are keyed transients; expire on TTL.
	 */
	private function within_rate_limit( \WP_REST_Request $request ): bool {
		$ip = $request->get_header( 'x_forwarded_for' );
		if ( empty( $ip ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}
		$key   = 'dfwc_stock_pledge_rl_' . wp_hash( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}
}
