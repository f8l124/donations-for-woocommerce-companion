<?php
/**
 * Track_REST_Controller — donor-side event ingestion endpoint.
 *
 * `POST /wp-json/dfwc-companion/v1/track`
 *
 * The donor-side overlay JS buffers analytics events for ~1 second then
 * POSTs them in a single batch to this endpoint. Each event in the batch
 * passes through `Privacy_Guard::sanitize_event` before being surfaced to
 * PHP listeners via `do_action`. The endpoint never stores anything —
 * listeners decide what to do with each event.
 *
 * The endpoint is intentionally `__return_true` (public). Donor-side
 * analytics is fundamentally public-by-design — nothing the endpoint
 * accepts is private data, so requiring auth would make the feature
 * unusable for nopriv donors. Defenses:
 *
 * - **Rate limit:** 100 requests per IP per minute (hashed IP keys).
 * - **Allow-list at the guard:** only known event types fire hooks.
 * - **Allow-list on contexts / intervals / currency / language:** the
 *   guard rejects anything off-script before `do_action`.
 * - **Server-replaced timestamp:** client-supplied `ts` is ignored.
 * - **Batch size cap:** at most 50 events per request.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\REST;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Analytics\Privacy_Guard;

final class Track_REST_Controller {

	private const NAMESPACE_BASE   = 'dfwc-companion/v1';
	private const ROUTE            = '/track';
	private const MAX_BATCH        = 50;
	private const RATE_LIMIT_TTL   = 60;  // seconds
	private const RATE_LIMIT_MAX   = 100; // events per IP per minute

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
				'callback'            => array( $this, 'handle_track' ),
				'args'                => array(
					'events' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	public function handle_track( \WP_REST_Request $request ): \WP_REST_Response {
		$events = $request->get_param( 'events' );
		if ( ! is_array( $events ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		if ( count( $events ) > self::MAX_BATCH ) {
			return new \WP_REST_Response(
				array(
					'error' => 'too_many_events',
					'limit' => self::MAX_BATCH,
				),
				422
			);
		}

		if ( ! $this->within_rate_limit( $request ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$received = 0;
		foreach ( $events as $raw_event ) {
			if ( ! is_array( $raw_event ) ) {
				continue;
			}
			$event = Privacy_Guard::sanitize_event( $raw_event );
			if ( '' === $event['type'] ) {
				continue; // unknown / disallowed type
			}
			$this->fire( $event );
			++$received;
		}

		return new \WP_REST_Response( array( 'received' => $received ), 200 );
	}

	/**
	 * Map a sanitized event to a `do_action` call. The hook signatures are
	 * documented in docs/event-hooks.md; the guarantee is stable through
	 * v1.x. New event types added in future minors should add a case here
	 * without changing existing signatures.
	 *
	 * @param array<string,mixed> $event
	 */
	private function fire( array $event ): void {
		$hook = 'dfwc_companion_' . $event['type'];

		switch ( $event['type'] ) {
			case 'form_viewed':
				/**
				 * Fires when a donor sees a companion-rendered donation form.
				 *
				 * @param int    $campaign_id
				 * @param string $context     One of single|shortcode|widget|checkout|cart|cart_block|block|elementor|preview|unknown.
				 * @param string $language    WPML language code, or empty if unknown.
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
				do_action( $hook, $event['campaign_id'], $event['context'], $event['language'] );
				break;

			case 'interval_selected':
				/**
				 * Fires when a donor changes the active interval tab.
				 *
				 * @param int    $campaign_id
				 * @param string $interval
				 * @param string $context
				 * @param string $language
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
				do_action( $hook, $event['campaign_id'], $event['interval'], $event['context'], $event['language'] );
				break;

			case 'preset_selected':
			case 'custom_amount_entered':
				/**
				 * Fires when a donor picks a preset or types in a custom amount.
				 *
				 * @param int    $campaign_id
				 * @param string $interval
				 * @param float  $amount
				 * @param string $context
				 * @param string $language
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
				do_action( $hook, $event['campaign_id'], $event['interval'], $event['amount'], $event['context'], $event['language'] );
				break;

			case 'donation_submitted':
			case 'donation_failed':
				// These usually fire server-side via Submission_Tracker, but the
				// frontend buffer can also surface them when the donor's submit
				// is rejected purely client-side. Same arg shape.
				/**
				 * Fires after a donation submit completes (success or failure).
				 *
				 * @param int    $campaign_id
				 * @param string $interval
				 * @param float  $amount
				 * @param string $context
				 * @param string $currency
				 * @param string $language
				 * @param string $reason   Empty for success; failure message for failed.
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
				do_action(
					$hook,
					$event['campaign_id'],
					$event['interval'],
					$event['amount'],
					$event['context'],
					$event['currency'],
					$event['language'],
					$event['reason']
				);
				break;
		}
	}

	/**
	 * Hash-the-IP rate limiter. Hashes are keyed transients; expire on TTL.
	 *
	 * Counts EVENTS, not requests — a batch of 50 events bumps the counter
	 * by 50, not 1. That matches the spec's "100 events per IP per minute"
	 * limit and prevents a malicious client from packing a 50-event batch
	 * into every request to amplify their effective rate.
	 */
	private function within_rate_limit( \WP_REST_Request $request ): bool {
		$ip = $request->get_header( 'x_forwarded_for' );
		if ( empty( $ip ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}
		$key       = 'dfwc_track_rl_' . wp_hash( $ip );
		$count     = (int) get_transient( $key );
		$increment = max( 1, count( (array) $request->get_param( 'events' ) ) );
		if ( $count + $increment > self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + $increment, self::RATE_LIMIT_TTL );
		return true;
	}
}
