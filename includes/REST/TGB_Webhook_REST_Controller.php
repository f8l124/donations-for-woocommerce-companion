<?php
/**
 * TGB_Webhook_REST_Controller — receives The Giving Block webhooks.
 *
 * `POST /wp-json/dfwc-companion/v1/tgb-webhook`
 *
 * Public endpoint (TGB doesn't authenticate to WordPress). Defense:
 *
 * 1. Feature gate: 404 (not 403) when crypto is globally disabled OR no
 *    Token_Store credentials. URL scrapers can't fingerprint that the
 *    endpoint exists but is dormant.
 * 2. Rate limit per IP: 60 req/min — generous for legitimate TGB traffic
 *    (which retries on 5xx but throttles itself).
 * 3. Body size limit: 64KB. TGB payloads are <2KB; reject larger as junk.
 * 4. HMAC-SHA256 signature verification via TGB_Webhook_Handler against
 *    the stored webhook secret. Constant-time compare via hash_equals.
 *    Failure → 401, no body, no further processing.
 * 5. JSON parse + field validation via TGB_Webhook_Handler. Failure → 400.
 * 6. Idempotency by donation_id (handled inside TGB_Webhook_Handler::process).
 *    Replays return 200 OK without re-firing Phase 9.
 *
 * Returns 200 OK on success even for "no-op" replays so TGB doesn't
 * retry. Genuine failures return 4xx/5xx so TGB DOES retry per their
 * delivery policy.
 *
 * Signature header name and verification scheme are filterable for TGB
 * API revisions:
 *   - dfwc_companion_tgb_webhook_signature_header (default: X-TGB-Signature)
 *   - dfwc_companion_tgb_webhook_verify (custom verifier closure)
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Gateways\TGB_Token_Store;
use DFWC\Companion\Gateways\TGB_Webhook_Handler;

final class TGB_Webhook_REST_Controller {

	private const NAMESPACE_BASE = 'dfwc-companion/v1';
	private const ROUTE          = '/tgb-webhook';
	private const RATE_LIMIT_TTL = 60;
	private const RATE_LIMIT_MAX = 60;
	private const MAX_BODY_BYTES = 65536;

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
		$store  = new TGB_Token_Store();
		if ( empty( $global['crypto_donations_enabled'] ) || ! $store->is_configured() ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! $this->within_rate_limit( $request ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$raw_body = (string) $request->get_body();
		if ( '' === $raw_body || strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return new \WP_REST_Response( array( 'error' => 'bad_request' ), 400 );
		}

		// HMAC verification. Header name + verification scheme are
		// filterable so TGB's evolving signature format (Stripe-style
		// timestamped headers, etc.) can be supported without code
		// changes here.
		$header_name = (string) apply_filters(
			'dfwc_companion_tgb_webhook_signature_header',
			'X-TGB-Signature'
		);
		$signature   = (string) $request->get_header( strtolower( str_replace( '-', '_', $header_name ) ) );
		$secret      = (string) $store->get_webhook_secret();

		$verified = apply_filters(
			'dfwc_companion_tgb_webhook_verify',
			TGB_Webhook_Handler::verify_signature( $raw_body, $signature, $secret ),
			$raw_body,
			$signature,
			$secret
		);
		if ( ! $verified ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$parsed = TGB_Webhook_Handler::parse_and_validate( $raw_body );
		if ( empty( $parsed['ok'] ) ) {
			return new \WP_REST_Response(
				array(
					'error' => 'bad_payload',
					'detail' => $parsed['error'] ?? '',
				),
				400
			);
		}

		$result = TGB_Webhook_Handler::process( $parsed['data'] );
		if ( empty( $result['ok'] ) ) {
			// Genuine processing failure — return 5xx so TGB retries on
			// their schedule. Common cause: no campaign matches the
			// project_id (admin misconfigured the per-campaign override).
			return new \WP_REST_Response(
				array(
					'error' => 'processing_failed',
					'detail' => $result['error'] ?? '',
				),
				500
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'action'   => $result['action'] ?? '',
				'order_id' => $result['order_id'] ?? 0,
			),
			200
		);
	}

	private function within_rate_limit( \WP_REST_Request $request ): bool {
		$ip = $request->get_header( 'x_forwarded_for' );
		if ( empty( $ip ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}
		$key   = 'dfwc_tgb_webhook_rl_' . wp_hash( (string) $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}
}
