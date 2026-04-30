<?php
/**
 * Overflow_Webhook_REST_Controller — receives webhooks from Overflow
 * (overflow.co) when a stock donation completes.
 *
 * `POST /wp-json/dfwc-companion/v1/overflow-webhook`
 *
 * When the org has Overflow mode enabled AND configured a webhook secret,
 * Overflow's platform POSTs a JSON payload to this endpoint after a
 * donor completes a stock transfer through their hosted flow. The
 * companion verifies the HMAC signature, creates a `dfwc_stock_pledge`
 * post in `received` status, and fires the standard Phase 9
 * `dfwc_companion_donation_submitted` hook with `$context = 'stock'`.
 *
 * Defenses:
 *
 * - Permission `__return_true` (webhook receivers can't authenticate the
 *   way human-driven REST endpoints can; Overflow doesn't carry a WP nonce).
 *   Forgery is blocked at the HMAC verification layer.
 * - HMAC-SHA256 signature verification using the `stock_overflow_webhook_secret`
 *   global setting. Constant-time comparison via `hash_equals`. Reject
 *   unmatched signatures with HTTP 401.
 * - Idempotency: each Overflow webhook carries a unique transaction ID;
 *   we skip re-creating a pledge when one with the same external ID
 *   already exists.
 * - When `stock_donations_enabled` is off OR mode isn't `overflow` OR the
 *   secret isn't set, the endpoint returns 404 (not 403) so probes can't
 *   distinguish "feature off" from "endpoint doesn't exist".
 *
 * Note: this controller is opt-in. The link-mode integration in
 * `assets/js/dfwc-overlay.js` works without a webhook — donors complete
 * on Overflow, Overflow records the donation in their dashboard, and
 * admins reconcile in Overflow. The webhook is for orgs that want
 * automatic in-companion pledge records to feed Phase 9 listeners
 * (CRM, GA4, Zapier).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Stock\Stock_Pledge_Post_Type;

final class Overflow_Webhook_REST_Controller {

	private const NAMESPACE_BASE   = 'dfwc-companion/v1';
	private const ROUTE            = '/overflow-webhook';
	private const SIGNATURE_HEADER = 'X-Overflow-Signature';

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
				'callback'            => array( $this, 'handle_webhook' ),
			)
		);
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$global = Config_Resolver::get_global_settings();

		// Feature gate. 404 (not 403) — probes shouldn't learn the endpoint
		// exists when it's disabled.
		$mode   = (string) ( $global['stock_giving_mode'] ?? '' );
		$secret = (string) ( $global['stock_overflow_webhook_secret'] ?? '' );
		if ( empty( $global['stock_donations_enabled'] ) || 'overflow' !== $mode || '' === $secret ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$payload  = (string) $request->get_body();
		$received = $request->get_header( self::SIGNATURE_HEADER );
		if ( ! is_string( $received ) || '' === $received ) {
			return new \WP_REST_Response( array( 'error' => 'missing_signature' ), 401 );
		}

		// HMAC-SHA256 over the raw request body. Constant-time compare.
		$expected = hash_hmac( 'sha256', $payload, $secret );
		if ( ! hash_equals( $expected, $received ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$body = json_decode( $payload, true );
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
		}

		// Required fields. We're permissive about Overflow's exact field
		// names changing — fall back through likely aliases.
		$external_id = (string) ( $body['transaction_id'] ?? $body['donation_id'] ?? $body['id'] ?? '' );
		if ( '' === $external_id ) {
			return new \WP_REST_Response( array( 'error' => 'missing_transaction_id' ), 400 );
		}

		// Idempotency check — skip if we already created a pledge for this
		// external id. Returns 200 (not error) so Overflow's retry loop
		// stops cleanly.
		if ( self::pledge_exists_for_external_id( $external_id ) ) {
			return new \WP_REST_Response(
				array(
					'received'    => true,
					'duplicate'   => true,
					'external_id' => $external_id,
				),
				200
			);
		}

		$donor_name      = sanitize_text_field( (string) ( $body['donor_name'] ?? '' ) );
		$donor_email     = sanitize_email( (string) ( $body['donor_email'] ?? '' ) );
		$ticker          = strtoupper( sanitize_text_field( (string) ( $body['ticker'] ?? $body['symbol'] ?? '' ) ) );
		$shares          = (float) ( $body['shares'] ?? $body['quantity'] ?? 0 );
		$actual_value    = (float) ( $body['fair_market_value'] ?? $body['value'] ?? $body['amount'] ?? 0 );
		$campaign_id     = absint( $body['campaign'] ?? $body['campaign_id'] ?? 0 );
		$broker_name     = sanitize_text_field( (string) ( $body['broker_name'] ?? $body['donor_broker'] ?? '' ) );
		$received_at_str = (string) ( $body['transferred_at'] ?? $body['completed_at'] ?? '' );

		if ( $actual_value <= 0 ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_amount' ), 422 );
		}

		$received_at = '' !== $received_at_str ? (int) strtotime( $received_at_str ) : time();
		if ( $received_at < 1 ) {
			$received_at = time();
		}

		// Validate campaign — if Overflow sent an unknown id, fall back to
		// the configured general-fund campaign or 0 (admin reconciles
		// manually). Don't reject the webhook over this; the donation
		// already happened.
		if ( $campaign_id > 0 ) {
			$post = function_exists( 'get_post' ) ? get_post( $campaign_id ) : null;
			if ( ! $post || 'wc-donation' !== $post->post_type ) {
				$campaign_id = 0;
			}
		}

		$pledge_id = wp_insert_post(
			array(
				'post_type'   => Stock_Pledge_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: ticker, 2: shares (formatted), 3: external id */
					__( '[Overflow] %1$s × %2$s — %3$s', 'dfwc-companion' ),
					'' !== $ticker ? $ticker : __( 'Stock', 'dfwc-companion' ),
					rtrim( rtrim( number_format( (float) $shares, 4, '.', '' ), '0' ), '.' ),
					$external_id
				),
			),
			true
		);

		if ( is_wp_error( $pledge_id ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => 'pledge_create_failed',
					'message' => $pledge_id->get_error_message(),
				),
				500
			);
		}

		$pledge_id = (int) $pledge_id;
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_NAME, $donor_name );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_EMAIL, $donor_email );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_BROKER_NAME, $broker_name );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_TICKER, $ticker );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_SHARES, (float) $shares );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ESTIMATED_VALUE, (float) $actual_value );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ACTUAL_VALUE, (float) $actual_value );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_RECEIVED_AT, (int) $received_at );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_CAMPAIGN_ID, (int) $campaign_id );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_STATUS, Stock_Pledge_Post_Type::STATUS_RECEIVED );
		update_post_meta( $pledge_id, '_dfwc_stock_pledge_overflow_id', $external_id );

		// Fire the Phase 9 standard hook so existing CRM/analytics listeners
		// route the stock donation alongside cash donations.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
		do_action(
			'dfwc_companion_donation_submitted',
			$campaign_id,
			\DFWC\Companion\Config\Config_Resolver::INTERVAL_ONE_TIME,
			(float) $actual_value,
			'stock',
			Currency_Preset_Resolver::base_currency(),
			function_exists( 'get_locale' ) ? (string) get_locale() : '',
			''
		);

		return new \WP_REST_Response(
			array(
				'received'    => true,
				'pledge_id'   => $pledge_id,
				'external_id' => $external_id,
			),
			201
		);
	}

	private static function pledge_exists_for_external_id( string $external_id ): bool {
		if ( '' === $external_id || ! function_exists( 'get_posts' ) ) {
			return false;
		}
		$existing = get_posts(
			array(
				'post_type'      => Stock_Pledge_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_dfwc_stock_pledge_overflow_id',
						'value' => $external_id,
					),
				),
			)
		);
		return ! empty( $existing );
	}
}
