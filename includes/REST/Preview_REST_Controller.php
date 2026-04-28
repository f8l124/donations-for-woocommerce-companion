<?php
/**
 * Preview_REST_Controller — admin-only preview endpoint.
 *
 * `POST /wp-json/dfwc-companion/v1/preview`
 *
 * Body: pending config payload + simulate args. Sanitizes through
 * Template_Validator, renders via Preview_Renderer, returns the HTML
 * string. Admins point an iframe at the response (or set its srcdoc).
 *
 * Security:
 * - permission_callback gated on `manage_woocommerce`
 * - Endpoint is write-shaped (POST + body) but does NOT write anything;
 *   no donation submit, no cart write, no option write
 * - Rate limit 10 req/sec/user via transient (admin-friendly threshold)
 * - data-preview="1" in rendered HTML triggers Submit_Guard's
 *   defense-in-depth refusal at the donor-side AJAX layer (see Phase 8.4)
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Frontend\Preview_Renderer;
use DFWC\Companion\Validation\Template_Validator;

final class Preview_REST_Controller {

	private const NAMESPACE       = 'dfwc-companion/v1';
	private const RATE_LIMIT_TTL  = 1;  // seconds
	private const RATE_LIMIT_MAX  = 10; // requests per user per second

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission_callback' ),
				'callback'            => array( $this, 'handle_preview' ),
				'args'                => array(
					'config'            => array(
						'required' => true,
						'type'     => 'object',
					),
					'simulate_engine'   => array(
						'required'          => false,
						'type'              => 'string',
						'enum'              => array( 'auto', 'wcs', 'wps_sfw', 'none' ),
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'auto',
					),
					'simulate_currency' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'simulate_language' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'viewport'          => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'desktop', 'tablet', 'mobile' ),
						'default'  => 'desktop',
					),
				),
			)
		);
	}

	public function permission_callback(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function handle_preview( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate limit per user. WP REST already requires the X-WP-Nonce so
		// the requester is authenticated; user_id is the right key.
		$user_id = get_current_user_id();
		if ( ! $this->within_rate_limit( $user_id ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$raw_config = (array) $request->get_param( 'config' );
		$validator  = new Template_Validator();
		$sanitized  = $validator->sanitize( $raw_config );

		$args = array(
			'engine'   => (string) $request->get_param( 'simulate_engine' ),
			'currency' => (string) $request->get_param( 'simulate_currency' ),
			'language' => (string) $request->get_param( 'simulate_language' ),
			'viewport' => (string) $request->get_param( 'viewport' ),
		);

		// WPML language switch: render the preview as if the donor's site
		// were in $args['language']. Restore on the way out.
		$prev_language = null;
		if ( '' !== $args['language'] && \DFWC\Companion\I18n\WPML_Strings::wpml_active() ) {
			$prev_language = \DFWC\Companion\I18n\WPML_Strings::current_language();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook name
			do_action( 'wpml_switch_language', $args['language'] );
		}

		$html = ( new Preview_Renderer() )->render( $sanitized, $args );

		if ( null !== $prev_language ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook name
			do_action( 'wpml_switch_language', $prev_language );
		}

		/**
		 * Allow third parties to mutate the rendered preview HTML
		 * (e.g., wrap in a custom theme shell).
		 *
		 * @param string              $html
		 * @param array<string,mixed> $sanitized Final sanitized config.
		 * @param array<string,mixed> $args      Simulate args.
		 */
		$html = (string) apply_filters( 'dfwc_companion_preview_html', $html, $sanitized, $args );

		$response = new \WP_REST_Response(
			array(
				'html'        => $html,
				'rendered_at' => time(),
			),
			200
		);

		// Defense-in-depth: prevent caching of the preview response. Each
		// request reflects the latest unsaved config; stale caches would
		// confuse admins.
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	private function within_rate_limit( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false; // unauthenticated requests should never reach here
		}
		$key   = 'dfwc_preview_rl_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}
}
