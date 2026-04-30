<?php
/**
 * Stock_Pledge_Handler — sanitize + persist stock pledges; expose state
 * transitions; fire Phase 9 hooks at the right boundary.
 *
 * Two persistence paths:
 *
 * 1. **Donor-side (REST)**: `Stock_Pledge_REST_Controller` POSTs raw donor
 *    input → `sanitize_pledge_input` → `create_pledge` → `wp_insert_post`
 *    + `update_post_meta`. Fires `dfwc_companion_stock_pledge_created`.
 * 2. **Admin reconciliation**: `Stock_Pledges_Page` POSTs receipt details
 *    (actual_value, received_at, admin_notes) → `mark_received` →
 *    `update_post_meta`. Fires `dfwc_companion_donation_submitted` with
 *    `$context = 'stock'` and `$amount = actual_value`.
 *
 * Status transitions are enforced server-side: pledge → in_transit →
 * received OR pledge → cancelled. The status meta is the source of truth;
 * the WP post_status stays `publish` throughout so admin can query
 * pledges via the standard list-table without filtering.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Stock;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;

final class Stock_Pledge_Handler {

	/**
	 * Sanitize raw donor input into a canonical pledge payload. Returns
	 * `array{ok: true, data: array<string,mixed>}` on success or
	 * `array{ok: false, errors: array<string,string>}` on validation
	 * failure. Caller (REST handler) decides how to surface errors.
	 *
	 * @param array<string,mixed> $raw
	 * @return array{ok:bool,data?:array<string,mixed>,errors?:array<string,string>}
	 */
	public static function sanitize_pledge_input( array $raw ): array {
		$errors = array();

		$donor_name = isset( $raw['donor_name'] )
			? sanitize_text_field( (string) $raw['donor_name'] )
			: '';
		if ( '' === $donor_name ) {
			$errors['donor_name'] = __( 'Donor name is required.', 'dfwc-companion' );
		}

		$donor_email = isset( $raw['donor_email'] )
			? sanitize_email( (string) $raw['donor_email'] )
			: '';
		if ( '' === $donor_email || ! function_exists( 'is_email' ) || ! is_email( $donor_email ) ) {
			$errors['donor_email'] = __( 'A valid email address is required.', 'dfwc-companion' );
		}

		$donor_phone = isset( $raw['donor_phone'] )
			? sanitize_text_field( (string) $raw['donor_phone'] )
			: '';

		$broker_name = isset( $raw['broker_name'] )
			? sanitize_text_field( (string) $raw['broker_name'] )
			: '';
		if ( '' === $broker_name ) {
			$errors['broker_name'] = __( 'Broker name is required.', 'dfwc-companion' );
		}

		$ticker = isset( $raw['ticker'] )
			? strtoupper( sanitize_text_field( (string) $raw['ticker'] ) )
			: '';
		// Tickers are 1–5 ASCII letters; defensive against arbitrary input.
		if ( '' === $ticker || ! preg_match( '/^[A-Z]{1,5}(\.[A-Z]{1,2})?$/', $ticker ) ) {
			$errors['ticker'] = __( 'A valid stock ticker symbol is required (1–5 letters).', 'dfwc-companion' );
		}

		$shares = isset( $raw['shares'] ) ? (float) $raw['shares'] : 0.0;
		if ( $shares <= 0 || $shares > 1000000.0 ) {
			$errors['shares'] = __( 'Number of shares must be greater than 0 and less than 1,000,000.', 'dfwc-companion' );
		}

		$estimated_value = isset( $raw['estimated_value'] )
			? (float) $raw['estimated_value']
			: 0.0;
		if ( $estimated_value <= 0 || $estimated_value > 100000000.0 ) {
			$errors['estimated_value'] = __( 'Estimated value must be greater than 0 and less than $100,000,000.', 'dfwc-companion' );
		}

		$campaign_id = isset( $raw['campaign_id'] ) ? absint( $raw['campaign_id'] ) : 0;
		if ( $campaign_id < 1 ) {
			$errors['campaign_id'] = __( 'Campaign is required.', 'dfwc-companion' );
		} else {
			$post = function_exists( 'get_post' ) ? get_post( $campaign_id ) : null;
			if ( ! $post || 'wc-donation' !== $post->post_type ) {
				$errors['campaign_id'] = __( 'Invalid campaign.', 'dfwc-companion' );
			}
		}

		$donor_notes = isset( $raw['donor_notes'] )
			? sanitize_textarea_field( (string) $raw['donor_notes'] )
			: '';
		if ( strlen( $donor_notes ) > 2000 ) {
			$donor_notes = substr( $donor_notes, 0, 2000 );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
			);
		}

		return array(
			'ok'   => true,
			'data' => array(
				'donor_name'      => $donor_name,
				'donor_email'     => $donor_email,
				'donor_phone'     => $donor_phone,
				'broker_name'     => $broker_name,
				'ticker'          => $ticker,
				'shares'          => $shares,
				'estimated_value' => $estimated_value,
				'campaign_id'     => $campaign_id,
				'donor_notes'     => $donor_notes,
			),
		);
	}

	/**
	 * Create a pledge post with `pledged` status. Fires the
	 * `dfwc_companion_stock_pledge_created` action with a sha256-hashed
	 * donor email so listeners (CRMs, analytics) get a stable identifier
	 * without raw PII.
	 *
	 * @param array<string,mixed> $data Sanitized payload from sanitize_pledge_input.
	 * @return int|\WP_Error Pledge post ID on success, WP_Error on failure.
	 */
	public static function create_pledge( array $data ) {
		$campaign_title = function_exists( 'get_the_title' )
			? (string) get_the_title( (int) $data['campaign_id'] )
			: '';

		$post_id = wp_insert_post(
			array(
				'post_type'   => Stock_Pledge_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: ticker symbol, 2: shares count, 3: campaign title */
					__( '%1$s × %2$s — %3$s', 'dfwc-companion' ),
					$data['ticker'],
					rtrim( rtrim( number_format( (float) $data['shares'], 4, '.', '' ), '0' ), '.' ),
					$campaign_title
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$pledge_id = (int) $post_id;
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_NAME, $data['donor_name'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_EMAIL, $data['donor_email'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_PHONE, $data['donor_phone'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_BROKER_NAME, $data['broker_name'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_TICKER, $data['ticker'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_SHARES, (float) $data['shares'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ESTIMATED_VALUE, (float) $data['estimated_value'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_CAMPAIGN_ID, (int) $data['campaign_id'] );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_STATUS, Stock_Pledge_Post_Type::STATUS_PLEDGED );
		if ( '' !== $data['donor_notes'] ) {
			update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_NOTES, $data['donor_notes'] );
		}

		/**
		 * Fires after a stock pledge is recorded but before the donor /
		 * admin emails go out. Listeners can use this to push to CRM,
		 * notify Slack, etc. The donor email is sha256-hashed before
		 * being passed (no raw PII through Phase 9 hooks).
		 *
		 * @param int    $pledge_id          Pledge post ID.
		 * @param int    $campaign_id        Campaign post ID the pledge targets.
		 * @param string $donor_email_hashed sha256 of the donor email.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
		do_action(
			'dfwc_companion_stock_pledge_created',
			$pledge_id,
			(int) $data['campaign_id'],
			hash( 'sha256', strtolower( trim( (string) $data['donor_email'] ) ) )
		);

		return $pledge_id;
	}

	/**
	 * Mark a pledge `received` with the actual transferred value. This is
	 * the moment the donation is "real" — fires the standard Phase 9
	 * `dfwc_companion_donation_submitted` hook with `$context='stock'`.
	 *
	 * @param int   $pledge_id
	 * @param float $actual_value Value at transfer date in base currency.
	 * @param int   $received_at  Unix timestamp; falls back to time() if 0.
	 * @param string $admin_notes Optional notes admin recorded at receipt.
	 * @return bool|\WP_Error true on success, WP_Error if pledge missing or invalid value.
	 */
	public static function mark_received( int $pledge_id, float $actual_value, int $received_at = 0, string $admin_notes = '' ) {
		$post = function_exists( 'get_post' ) ? get_post( $pledge_id ) : null;
		if ( ! $post || Stock_Pledge_Post_Type::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'dfwc_pledge_not_found',
				__( 'Stock pledge not found.', 'dfwc-companion' )
			);
		}

		if ( $actual_value <= 0 || $actual_value > 100000000.0 ) {
			return new \WP_Error(
				'dfwc_pledge_invalid_value',
				__( 'Actual transfer value must be greater than 0 and less than $100,000,000.', 'dfwc-companion' )
			);
		}

		if ( $received_at < 1 ) {
			$received_at = time();
		}

		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ACTUAL_VALUE, (float) $actual_value );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_RECEIVED_AT, (int) $received_at );
		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_STATUS, Stock_Pledge_Post_Type::STATUS_RECEIVED );
		if ( '' !== $admin_notes ) {
			update_post_meta(
				$pledge_id,
				Stock_Pledge_Post_Type::META_ADMIN_NOTES,
				sanitize_textarea_field( $admin_notes )
			);
		}

		$campaign_id = (int) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_CAMPAIGN_ID, true );
		$currency    = Currency_Preset_Resolver::base_currency();

		/**
		 * Phase 9 standard hook — fired at the moment a donation becomes
		 * real. Stock context surfaces as `'stock'`; listeners can split
		 * cash vs stock metrics by checking $context.
		 *
		 * @param int    $campaign_id
		 * @param string $interval  Always 'one_time' for stock.
		 * @param float  $amount    Actual value at transfer date.
		 * @param string $context   'stock'.
		 * @param string $currency  Base currency (stock receipts denominate in base).
		 * @param string $language  Site locale.
		 * @param string $reason    Empty for success.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
		do_action(
			'dfwc_companion_donation_submitted',
			$campaign_id,
			Config_Resolver::INTERVAL_ONE_TIME,
			(float) $actual_value,
			'stock',
			$currency,
			function_exists( 'get_locale' ) ? (string) get_locale() : '',
			''
		);

		return true;
	}

	/**
	 * Transition a pledge to a non-receipt status. Used by admin to mark
	 * pledges in_transit (received broker confirmation but stock hasn't
	 * cleared yet) or cancelled (donor backed out).
	 *
	 * Doesn't fire the donation_submitted hook — that's reserved for
	 * `mark_received`, the moment the donation is real.
	 */
	public static function transition_status( int $pledge_id, string $new_status ) {
		$post = function_exists( 'get_post' ) ? get_post( $pledge_id ) : null;
		if ( ! $post || Stock_Pledge_Post_Type::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'dfwc_pledge_not_found',
				__( 'Stock pledge not found.', 'dfwc-companion' )
			);
		}

		if ( ! in_array( $new_status, Stock_Pledge_Post_Type::statuses(), true ) ) {
			return new \WP_Error(
				'dfwc_pledge_invalid_status',
				__( 'Invalid pledge status.', 'dfwc-companion' )
			);
		}

		// Refuse to transition to received via this method — use mark_received
		// so the Phase 9 hook fires correctly.
		if ( Stock_Pledge_Post_Type::STATUS_RECEIVED === $new_status ) {
			return new \WP_Error(
				'dfwc_pledge_use_mark_received',
				__( 'Use mark_received() to record a received pledge so the donation hook fires.', 'dfwc-companion' )
			);
		}

		update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_STATUS, $new_status );
		return true;
	}

	/**
	 * Read a pledge's full data for admin reconciliation UI.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_pledge( int $pledge_id ): ?array {
		$post = function_exists( 'get_post' ) ? get_post( $pledge_id ) : null;
		if ( ! $post || Stock_Pledge_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$status_meta = (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_STATUS, true );
		if ( '' === $status_meta ) {
			$status_meta = Stock_Pledge_Post_Type::STATUS_PLEDGED;
		}

		return array(
			'id'              => $pledge_id,
			'title'           => $post->post_title,
			'created_at'      => function_exists( 'get_post_time' ) ? (int) get_post_time( 'U', true, $post ) : 0,
			'donor_name'      => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_NAME, true ),
			'donor_email'     => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_EMAIL, true ),
			'donor_phone'     => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_PHONE, true ),
			'broker_name'     => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_BROKER_NAME, true ),
			'ticker'          => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_TICKER, true ),
			'shares'          => (float) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_SHARES, true ),
			'estimated_value' => (float) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ESTIMATED_VALUE, true ),
			'actual_value'    => (float) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ACTUAL_VALUE, true ),
			'received_at'     => (int) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_RECEIVED_AT, true ),
			'campaign_id'     => (int) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_CAMPAIGN_ID, true ),
			'status'          => $status_meta,
			'donor_notes'     => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_DONOR_NOTES, true ),
			'admin_notes'     => (string) get_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ADMIN_NOTES, true ),
		);
	}
}
