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
	private const META_BOX_FORM   = 'dfwc_companion_form_mode';
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

		add_meta_box(
			self::META_BOX_FORM,
			__( 'Companion: Form Mode', 'dfwc-companion' ),
			[ $this, 'render_form_mode' ],
			self::PARENT_POST_TYPE,
			'side',
			'low'
		);
	}

	public function render_intervals( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$config         = Config_Resolver::resolve( $post->ID );
		$engine         = Engine_Detector::detect();
		$product_warn   = $this->detect_linked_product_warning( $post->ID, $config, $engine );
		$intervals      = Config_Resolver::intervals();
		$interval_label = self::interval_labels();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		include DFWC_COMPANION_PATH . 'templates/meta-box-intervals.php';
	}

	public function render_form_mode( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$mode = Config_Resolver::form_mode( $post->ID );
		?>
		<p>
			<label>
				<input type="radio" name="dfwc_form_mode" value="<?php echo esc_attr( Config_Resolver::FORM_MODE_REPLACE ); ?>"
					<?php checked( Config_Resolver::FORM_MODE_REPLACE, $mode ); ?>>
				<strong><?php esc_html_e( 'Replace the default donation form (recommended)', 'dfwc-companion' ); ?></strong>
			</label>
		</p>
		<p>
			<label>
				<input type="radio" name="dfwc_form_mode" value="<?php echo esc_attr( Config_Resolver::FORM_MODE_SHORTCODE_ONLY ); ?>"
					<?php checked( Config_Resolver::FORM_MODE_SHORTCODE_ONLY, $mode ); ?>>
				<?php esc_html_e( 'Render only via shortcode / block / widget', 'dfwc-companion' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( '"Replace" is the default. The interval-first form replaces the parent plugin\'s default donation form on this campaign\'s single page AND on any [wc_woo_donation] shortcode for this campaign. Pick the second option if you want to keep using the parent\'s form alongside our shortcode/block/widget on specific pages.', 'dfwc-companion' ); ?>
		</p>
		<?php
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
		}

		// Form-mode meta.
		$mode_raw = isset( $_POST['dfwc_form_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['dfwc_form_mode'] ) ) : '';
		$mode     = in_array( $mode_raw, [ Config_Resolver::FORM_MODE_REPLACE, Config_Resolver::FORM_MODE_SHORTCODE_ONLY ], true )
			? $mode_raw
			: Config_Resolver::FORM_MODE_SHORTCODE_ONLY;
		update_post_meta( $post_id, Config_Resolver::META_KEY_FORM_MODE, $mode );
	}

	/**
	 * Clean up one interval block from raw POST. Re-runs the same validations
	 * Config_Resolver does, but starting from un-sanitized POST instead of
	 * already-stored array.
	 */
	private function sanitize_interval_block( array $raw ): array {
		$enabled = ! empty( $raw['enabled'] );

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
			'enabled'       => $enabled,
			'presets'       => $presets,
			'min'           => $min,
			'max'           => $max,
			'default_index' => $default_index,
			'cta_template'  => $cta,
		];
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

		if ( Engine_Detector::ENGINE_WCS === $engine && class_exists( '\WC_Subscriptions_Product' ) ) {
			$is_subscription_product = (bool) \WC_Subscriptions_Product::is_subscription( $product_id );
		} elseif ( Engine_Detector::ENGINE_WPS === $engine && function_exists( 'wps_sfw_get_meta_data' ) ) {
			$is_subscription_product = 'user' === \wps_sfw_get_meta_data( $product_id, '_wps_sfw_users', true );
		}

		if ( $is_subscription_product ) {
			return null;
		}

		return sprintf(
			/* translators: %d: WooCommerce product ID linked to this campaign */
			__( 'The donation product linked to this campaign (#%d) is not configured as a subscription product. Donors selecting Monthly or Annually will be charged once instead of being enrolled in a subscription. Configure the product as a subscription via the parent plugin\'s product editor.', 'dfwc-companion' ),
			$product_id
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
