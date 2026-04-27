<?php
/**
 * Per-campaign admin meta box: configure preset amounts per interval and the
 * form-mode override (replace parent's single-page form vs shortcode-only).
 *
 * Save handler force-sets the parent's `wc-donation-recurring` campaign meta
 * to `'user'` whenever monthly or annual is enabled — without this, the
 * parent's AJAX handler at class-wcdonationorder.php:1655 silently downgrades
 * every recurring submit to one-time. (Master plan deviation D1.)
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config_Resolver;
use DFWC\Companion\Engine_Detector;

final class Meta_Box {

	private const NONCE_ACTION    = 'dfwc_companion_save_meta';
	private const NONCE_FIELD     = '_dfwc_nonce';
	private const META_BOX_ID     = 'dfwc_companion_intervals';
	private const PARENT_POST_TYPE = 'wc-donation';

	/**
	 * Tracks campaigns saved during this request to dedupe between
	 * `wc_donation_after_save_campaign_meta` and the `save_post_wc-donation`
	 * fallback (both fire on classic-editor saves).
	 */
	private static array $saved_in_request = [];

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'wc_donation_after_save_campaign_meta', [ $this, 'save' ], 20, 1 );
		// Fallback for block-editor saves that bypass the parent's hook.
		add_action( 'save_post_' . self::PARENT_POST_TYPE, [ $this, 'save' ], 20, 1 );

		// 0.6.4: late re-assertion of subscription-product meta. Parent's
		// class-wcdonationsetting save handler calls wp_update_post on the
		// linked product later in the same save flow (line 681), which
		// indirectly fires woocommerce_process_product_meta, which fires
		// WPS SFW's handler that writes _wps_sfw_product='no' (because there
		// is no _wps_sfw_product field in $_POST when saving a campaign).
		// That last write was overwriting the 'yes' we wrote during save().
		// We now re-assert at two later points to guarantee our 'yes' wins:
		//
		//  (a) priority-9999 save_post_wc-donation — runs after all
		//      priority-10 handlers complete (including parent's product
		//      update chain).
		//  (b) priority-99 woocommerce_process_product_meta — runs whenever
		//      WC processes a product save for any of OUR campaigns'
		//      products, immediately after WPS SFW's priority-10 default-'no'
		//      write.
		add_action( 'save_post_' . self::PARENT_POST_TYPE, [ $this, 'late_reassert_product_subscription' ], 9999, 1 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'preserve_subscription_product_marker' ], 99, 2 );
	}

	/**
	 * Re-run product auto-configuration after all priority-10 save_post
	 * handlers have completed. Sees the same companion config we just saved
	 * (since save() already wrote it) and re-asserts the subscription-product
	 * meta, overriding any 'no' WPS SFW wrote during parent's nested
	 * wp_update_post call.
	 */
	public function late_reassert_product_subscription( int $post_id ): void {
		if ( self::PARENT_POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		// Re-read the persisted intervals (no $_POST dependency since save()
		// already ran). is_configured guards against unsaved campaigns.
		if ( ! Config_Resolver::is_configured( $post_id ) ) {
			return;
		}
		$config = Config_Resolver::resolve( $post_id );
		$recurring_enabled = ( $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled'] ?? false )
			|| ( $config[ Config_Resolver::INTERVAL_ANNUAL ]['enabled'] ?? false );
		if ( ! $recurring_enabled ) {
			return;
		}
		$primary_period = ( $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled'] ?? false ) ? 'month' : 'year';
		$this->auto_configure_product_subscription( $post_id, $primary_period );
	}

	/**
	 * Re-assert our subscription-product meta whenever WC processes a product
	 * save and the product is linked to one of our recurring-enabled campaigns.
	 * Hooked at priority 99 so we run AFTER WPS SFW's default-'no' write at
	 * priority 10. Without this, parent's wp_update_post on the linked product
	 * during a campaign save (or any other code path that triggers WC product
	 * processing) silently flips _wps_sfw_product back to 'no'.
	 *
	 * @param int      $product_id Product post ID
	 * @param \WP_Post $post       Unused; kept for hook signature.
	 */
	public function preserve_subscription_product_marker( $product_id, $post = null ): void {
		$product_id = (int) $product_id;
		if ( $product_id < 1 ) {
			return;
		}
		if ( ! class_exists( '\WcdonationSetting' ) ) {
			return;
		}
		$campaign_id = (int) \WcdonationSetting::get_campaign_id_by_product_id( $product_id );
		if ( $campaign_id < 1 ) {
			return;
		}
		if ( ! Config_Resolver::is_configured( $campaign_id ) ) {
			return;
		}
		$config = Config_Resolver::resolve( $campaign_id );
		$recurring_enabled = ( $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled'] ?? false )
			|| ( $config[ Config_Resolver::INTERVAL_ANNUAL ]['enabled'] ?? false );
		if ( ! $recurring_enabled ) {
			return;
		}
		$primary_period = ( $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled'] ?? false ) ? 'month' : 'year';
		$this->auto_configure_product_subscription( $campaign_id, $primary_period );
	}

	public function register(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Interval-First Donation Form', 'dfwc-companion' ),
			[ $this, 'render_intervals' ],
			self::PARENT_POST_TYPE,
			'normal',
			'high'
		);
		// 0.3.0: removed the form-mode side meta box. Replacement is now
		// unconditional; opt-out is via the `dfwc_should_replace_parent_form`
		// filter for power users.
	}

	public function render_intervals( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$config         = Config_Resolver::resolve( $post->ID );
		$display        = Config_Resolver::resolve_display( $post->ID );
		$engine         = Engine_Detector::detect();
		$product_warn   = $this->detect_linked_product_warning( $post->ID, $config, $engine );
		$intervals      = Config_Resolver::intervals();
		$interval_label = self::interval_labels();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		include DFWC_COMPANION_PATH . 'templates/meta-box-intervals.php';
	}

	public function save( int $post_id ): void {
		if ( isset( self::$saved_in_request[ $post_id ] ) ) {
			return;
		}

		// Use constants directly rather than wp_doing_autosave()/wp_doing_ajax() —
		// wp_doing_autosave() does not exist as a WP function (the canonical idiom
		// is the constant), and we want one less version-dependent surface anyway.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Reject classic-AJAX (autosave heartbeat etc.) but allow REST (block editor).
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) && ! defined( 'REST_REQUEST' ) ) {
			return;
		}

		if ( self::PARENT_POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Nonce field must be present (this prevents the bare save_post fallback from
		// running unauthenticated when the meta box wasn't actually rendered).
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD ) ) {
			return;
		}

		self::$saved_in_request[ $post_id ] = true;

		$raw = isset( $_POST['dfwc_intervals'] ) && is_array( $_POST['dfwc_intervals'] )
			? wp_unslash( $_POST['dfwc_intervals'] )
			: [];

		$config = [];
		foreach ( Config_Resolver::intervals() as $key ) {
			$config[ $key ] = $this->sanitize_interval_block(
				is_array( $raw[ $key ] ?? null ) ? $raw[ $key ] : []
			);
		}

		// Reject a config where a recurring tab is enabled with no usable presets
		// AND a min that's so high it can't yield a custom amount either —
		// almost always misconfiguration. Surface as admin notice; do NOT save.
		foreach ( [ Config_Resolver::INTERVAL_MONTHLY, Config_Resolver::INTERVAL_ANNUAL ] as $rk ) {
			if ( $config[ $rk ]['enabled'] && empty( $config[ $rk ]['presets'] ) && $config[ $rk ]['min'] > 1000 ) {
				add_settings_error(
					'dfwc_companion',
					'dfwc_invalid_recurring',
					sprintf(
						/* translators: %s: interval name (Monthly / Annually) */
						__( 'The %s tab is enabled but has no presets and an unusually high minimum. Please review the configuration.', 'dfwc-companion' ),
						esc_html( self::interval_labels()[ $rk ] )
					),
					'error'
				);
				return;
			}
		}

		update_post_meta( $post_id, Config_Resolver::META_KEY_INTERVALS, $config );

		// D1: parent only processes recurring POST data when wc-donation-recurring='user'.
		// Force it whenever monthly or annual is on; otherwise leave parent's value alone.
		$recurring_enabled = $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled']
			|| $config[ Config_Resolver::INTERVAL_ANNUAL ]['enabled'];

		if ( $recurring_enabled ) {
			update_post_meta( $post_id, 'wc-donation-recurring', 'user' );

			// Also seed the parent's subscription-period defaults so its renewal/end-date
			// helpers (WcdonationSubscription) compute the right cadence at cart time.
			$primary_interval = $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled']
				? Config_Resolver::INTERVAL_MONTHLY
				: Config_Resolver::INTERVAL_ANNUAL;
			$primary_period   = Config_Resolver::INTERVAL_MONTHLY === $primary_interval ? 'month' : 'year';

			update_post_meta( $post_id, '_subscription_period', $primary_period );
			update_post_meta( $post_id, '_subscription_period_interval', '1' );
			update_post_meta( $post_id, '_subscription_length', '0' );

			// 0.6.1: also auto-configure the linked WC product as a subscription
			// product. Without this, subscription engines (WPS SFW / WCS) treat
			// the product as one-time and the donation never enrolls as a
			// subscription, even though our POST data + the campaign's
			// wc-donation-recurring='user' meta tell parent to process it as
			// recurring. The recurring controls that v0.2.0 hid in the parent's
			// "Recurring Donations" tab were the admin's previous path to
			// configure this; now the companion does it automatically.
			$this->auto_configure_product_subscription( $post_id, $primary_period );
		}

		// 0.3.0: form-mode meta is no longer collected from the form. The
		// _dfwc_companion_form_mode key remains in the DB on legacy campaigns
		// but is now unused. We do not delete it (preserves user state in case
		// a future minor reintroduces the opt-out as something more granular).

		// 0.6.0: display options. Save the show_title/show_image/cause_heading
		// triplet under a separate meta key so it's clearly distinct from the
		// per-interval config and easy for power users to override via filter.
		$display_raw = isset( $_POST['dfwc_display'] ) && is_array( $_POST['dfwc_display'] )
			? wp_unslash( $_POST['dfwc_display'] )
			: [];
		update_post_meta(
			$post_id,
			Config_Resolver::META_KEY_DISPLAY,
			$this->sanitize_display( $display_raw )
		);
	}

	/**
	 * Clean up one interval block from raw POST. Re-runs the same validations
	 * Config_Resolver does, but starting from un-sanitized POST instead of
	 * already-stored array.
	 */
	private function sanitize_interval_block( array $raw ): array {
		$enabled               = ! empty( $raw['enabled'] );
		$custom_amount_enabled = isset( $raw['custom_amount_enabled'] ) ? (bool) $raw['custom_amount_enabled'] : true;

		$presets_raw = isset( $raw['presets'] ) && is_array( $raw['presets'] ) ? $raw['presets'] : [];
		$presets     = [];
		foreach ( $presets_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$amount_str = isset( $row['amount'] ) ? (string) $row['amount'] : '';
			$amount     = (float) wc_format_decimal( $amount_str, wc_get_price_decimals() );
			if ( $amount <= 0 ) {
				continue;
			}
			$label     = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$presets[] = [ 'amount' => $amount, 'label' => $label ];
		}

		$min = isset( $raw['min'] ) ? (float) wc_format_decimal( (string) $raw['min'], wc_get_price_decimals() ) : 0.0;
		$max = isset( $raw['max'] ) ? (float) wc_format_decimal( (string) $raw['max'], wc_get_price_decimals() ) : 0.0;
		if ( $min < 0.01 ) {
			$min = 0.01;
		}
		if ( $max < $min ) {
			$max = $min;
		}

		$default_index = isset( $raw['default_index'] ) ? (int) $raw['default_index'] : 0;
		$preset_count  = count( $presets );
		if ( $preset_count > 0 ) {
			$default_index = max( 0, min( $default_index, $preset_count - 1 ) );
		} else {
			$default_index = 0;
		}

		$cta = isset( $raw['cta_template'] ) ? sanitize_text_field( (string) $raw['cta_template'] ) : '';

		return [
			'enabled'               => $enabled,
			'presets'               => $presets,
			'min'                   => $min,
			'max'                   => $max,
			'default_index'         => $default_index,
			'cta_template'          => $cta,
			'custom_amount_enabled' => $custom_amount_enabled,
		];
	}

	/**
	 * Sanitize the display-options block from raw POST. All fields default
	 * to their `display_defaults()` values when missing.
	 */
	private function sanitize_display( array $raw ): array {
		$defaults = Config_Resolver::display_defaults();
		// HTML form-checkbox semantics: an UNCHECKED checkbox sends nothing in
		// $_POST. We can distinguish "missing" (treated as false) from "checked"
		// (string '1') only because we always emit the checkbox in our template.
		// Therefore, save handler honors form intent: missing = unchecked = false.
		return [
			'show_title'    => ! empty( $raw['show_title'] ),
			'show_image'    => ! empty( $raw['show_image'] ),
			'cause_heading' => isset( $raw['cause_heading'] )
				? sanitize_text_field( (string) $raw['cause_heading'] )
				: $defaults['cause_heading'],
		];
	}

	/**
	 * Auto-configure the linked WC product as a subscription product for the
	 * active engine. Called when the admin enables monthly/annual on the
	 * campaign — without this, subscription engines treat the product as
	 * one-time and the recurring donation flow silently downgrades despite
	 * our wc-donation-recurring='user' force-set.
	 *
	 * Engine semantics:
	 * - WPS SFW: the engine recognizes a product as a subscription when the
	 *   `_wps_sfw_users` meta == 'user'. We also seed the wps_sfw_subscription_*
	 *   meta on the product so the engine knows the cadence. Empty expiry =
	 *   open-ended (forever).
	 * - WCS: product type taxonomy term must be `subscription`. Plus the
	 *   _subscription_* product meta drives WCS's renewal logic.
	 *
	 * No-op when engine='none'. Defensive against missing/non-subscription-
	 * compatible products.
	 */
	private function auto_configure_product_subscription( int $campaign_id, string $primary_period ): void {
		if ( ! class_exists( '\WcdonationCampaignSetting' ) ) {
			return;
		}

		$object     = \WcdonationCampaignSetting::get_product_by_campaign( $campaign_id );
		$product_id = isset( $object->product['product_id'] ) ? (int) $object->product['product_id'] : 0;
		if ( $product_id < 1 ) {
			return;
		}

		$engine = Engine_Detector::detect();

		if ( Engine_Detector::ENGINE_WCS === $engine ) {
			// WCS: switch product type to subscription via the product_type taxonomy.
			wp_set_object_terms( $product_id, 'subscription', 'product_type' );
			update_post_meta( $product_id, '_subscription_period', $primary_period );
			update_post_meta( $product_id, '_subscription_period_interval', '1' );
			update_post_meta( $product_id, '_subscription_length', '0' );
			// _subscription_price intentionally not set — donor's amount is the price.
		} elseif ( Engine_Detector::ENGINE_WPS === $engine ) {
			// WPS SFW recognizes subscription products via _wps_sfw_product='yes'
			// (NOT _wps_sfw_users — that's parent's internal tracking key, used in
			// parent's AJAX handler at class-wcdonationorder.php:1601). Both must
			// be set: _wps_sfw_product so the engine treats the product as a
			// subscription, _wps_sfw_users so parent's flow processes recurring
			// POST data. Verified against WPS SFW source at
			// admin/class-subscriptions-for-woocommerce-admin.php:791 (where
			// admin save handler writes _wps_sfw_product) and parent's
			// class-wcdonationorder.php:1601 (where parent reads _wps_sfw_users).
			$writes = [
				'_wps_sfw_product'                     => 'yes',
				'_wps_sfw_users'                       => 'user',
				'wps_sfw_subscription_number'          => '1',
				'wps_sfw_subscription_interval'        => $primary_period,
				'wps_sfw_subscription_expiry_number'   => '',
				'wps_sfw_subscription_expiry_interval' => '',
			];
			foreach ( $writes as $key => $value ) {
				if ( function_exists( 'wps_sfw_update_meta_data' ) ) {
					wps_sfw_update_meta_data( $product_id, $key, $value );
				} else {
					update_post_meta( $product_id, $key, $value );
				}
			}

			// Belt-and-suspenders: in case wps_sfw_update_meta_data takes a
			// different path on some site configs, also write directly via
			// update_post_meta. WP dedupes same-value writes; harmless if
			// the helper already wrote there.
			foreach ( $writes as $key => $value ) {
				update_post_meta( $product_id, $key, $value );
			}

			// 0.6.3 diagnostic: log whether the write took. Helps debug cases
			// where the warning persists after save. Only logged when
			// WP_DEBUG_LOG is enabled (admin's choice).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$verify = (string) \wps_sfw_get_meta_data( $product_id, '_wps_sfw_product', true );
				$direct = (string) get_post_meta( $product_id, '_wps_sfw_product', true );
				error_log( sprintf(
					'[dfwc-companion] auto-configured product #%d for campaign #%d, engine=wps_sfw, period=%s. Read-back via wps_sfw_get_meta_data: "%s"; via get_post_meta: "%s".',
					$product_id,
					$campaign_id,
					$primary_period,
					$verify,
					$direct
				) );
			}
		}
		// engine 'none' falls through — recurring intervals can't be enabled
		// in that case anyway (companion forces them off in the meta box).
	}

	/**
	 * Inspect the WC product the parent has linked to this campaign and return
	 * a warning string if the product isn't a subscription product despite the
	 * campaign offering recurring intervals. Returns null when no warning needed.
	 */
	private function detect_linked_product_warning( int $campaign_id, array $config, string $engine ): ?string {
		$recurring_enabled = $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled']
			|| $config[ Config_Resolver::INTERVAL_ANNUAL ]['enabled'];
		if ( ! $recurring_enabled ) {
			return null;
		}

		if ( Engine_Detector::ENGINE_NONE === $engine ) {
			return null; // Engine-missing notice handled separately.
		}

		if ( ! class_exists( '\WcdonationCampaignSetting' ) ) {
			return null;
		}

		$object     = \WcdonationCampaignSetting::get_product_by_campaign( $campaign_id );
		$product_id = isset( $object->product['product_id'] ) ? (int) $object->product['product_id'] : 0;
		if ( $product_id < 1 ) {
			return null;
		}

		$is_subscription_product = false;
		$diagnostic              = '';

		if ( Engine_Detector::ENGINE_WCS === $engine && class_exists( '\WC_Subscriptions_Product' ) ) {
			$is_subscription_product = (bool) \WC_Subscriptions_Product::is_subscription( $product_id );
			$product_type            = function_exists( 'wp_get_post_terms' )
				? implode( ',', wp_get_post_terms( $product_id, 'product_type', [ 'fields' => 'slugs' ] ) )
				: '';
			$diagnostic              = "engine=wcs product_type=[{$product_type}] is_subscription=" . ( $is_subscription_product ? 'true' : 'false' );
		} elseif ( Engine_Detector::ENGINE_WPS === $engine && function_exists( 'wps_sfw_get_meta_data' ) ) {
			// _wps_sfw_product='yes' is WPS SFW's own marker for "subscription product";
			// _wps_sfw_users was parent's internal field, which we still write but
			// don't gate the warning on. (Fixed in 0.6.2 — 0.6.1 incorrectly used
			// the parent-internal key as the recognition marker.)
			$prod_marker             = (string) \wps_sfw_get_meta_data( $product_id, '_wps_sfw_product', true );
			$users_marker            = (string) \wps_sfw_get_meta_data( $product_id, '_wps_sfw_users', true );
			$is_subscription_product = 'yes' === $prod_marker;
			$diagnostic              = "engine=wps_sfw _wps_sfw_product='{$prod_marker}' _wps_sfw_users='{$users_marker}'";
		}

		if ( $is_subscription_product ) {
			return null;
		}

		// 0.6.3 diagnostic: include the actual meta read so we can tell from a
		// screenshot whether (a) auto-config never ran/wrote, (b) auto-config
		// wrote but something else cleared it, or (c) detection is wrong.
		return sprintf(
			/* translators: 1: WooCommerce product ID linked to this campaign, 2: diagnostic detail (engine + meta read) */
			__( 'The donation product linked to this campaign (#%1$d) is not yet recognized as a subscription product. Donors selecting Monthly or Annually will be charged once instead of being enrolled in a subscription. Save the campaign once and the companion will auto-configure the product. Diagnostic: %2$s', 'dfwc-companion' ),
			$product_id,
			$diagnostic
		);
	}

	/**
	 * Display labels for each interval. Translatable.
	 */
	public static function interval_labels(): array {
		return [
			Config_Resolver::INTERVAL_ONE_TIME => __( 'One-time', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_MONTHLY  => __( 'Monthly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL   => __( 'Annually', 'dfwc-companion' ),
		];
	}
}
