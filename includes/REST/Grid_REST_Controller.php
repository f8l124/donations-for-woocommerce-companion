<?php
/**
 * Grid_REST_Controller — public read-only endpoint for live grid updates.
 *
 * `GET /wp-json/dfwc-companion/v1/grid?cause=...&country=...&page=2`
 *
 * Returns the rendered grid HTML for the supplied filter set. JS uses this
 * for debounced search-as-you-type without full page reloads. Endpoint is
 * public (donor-facing search is fundamentally public-data) but
 * rate-limited to defend against scraping abuse.
 *
 * No PII, no donor data — only the same view a public visitor could
 * access by reloading the page with different query args.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Frontend\Campaign_Directory_Renderer;

final class Grid_REST_Controller {

	private const NAMESPACE      = 'dfwc-companion/v1';
	private const RATE_LIMIT_TTL = 60; // seconds
	private const RATE_LIMIT_MAX = 60; // requests per IP per minute

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/grid',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_grid' ),
				'args'                => $this->endpoint_args(),
			)
		);
	}

	/**
	 * Schema for the grid endpoint args. WP REST validates + sanitizes
	 * automatically when the schema is declared.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function endpoint_args(): array {
		$tax_arg = array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_title',
			'default'           => '',
		);

		return array(
			'cause'             => $tax_arg,
			'region'            => $tax_arg,
			'country'           => $tax_arg,
			'program'           => $tax_arg,
			'sponsorship_type'  => $tax_arg,
			'urgency'           => $tax_arg,
			'featured'          => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
			's' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'orderby' => array(
				'required'          => false,
				'type'              => 'string',
				'enum'              => array( 'menu_order', 'featured', 'title', 'date', 'rand', 'modified' ),
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'menu_order',
			),
			'order' => array(
				'required'          => false,
				'type'              => 'string',
				'enum'              => array( 'ASC', 'DESC' ),
				'default'           => 'ASC',
			),
			'page' => array(
				'required'          => false,
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 1000,
				'sanitize_callback' => 'absint',
				'default'           => 1,
			),
			'per_page' => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 50,
				'default'  => 12,
			),
			'layout' => array(
				'required' => false,
				'type'     => 'string',
				'enum'     => array( 'grid', 'list' ),
				'default'  => 'grid',
			),
			'show_filters' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false, // live updates don't include the filter bar — JS swaps just the grid
			),
		);
	}

	public function handle_grid( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->within_rate_limit( $request ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'rate_limited' ),
				429
			);
		}

		$args = array();
		foreach ( array_keys( $this->endpoint_args() ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value && false !== $value ) {
				$args[ $key ] = $value;
			}
		}

		$html = ( new Campaign_Directory_Renderer() )->render( $args );

		return new \WP_REST_Response(
			array(
				'html'        => $html,
				'rendered_at' => time(),
			),
			200
		);
	}

	/**
	 * Hash-the-IP rate limiter. Hashes are keyed transients; expire on TTL.
	 */
	private function within_rate_limit( \WP_REST_Request $request ): bool {
		$ip      = $request->get_header( 'x_forwarded_for' );
		if ( empty( $ip ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}
		$key = 'dfwc_grid_rl_' . wp_hash( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}
}
