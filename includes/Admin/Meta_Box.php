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

use DFWC\Companion\Config\Campaign_Config_Repository;
use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Engine_Interval_Map;
use DFWC\Companion\Config\Template_Repository;
use DFWC\Companion\Engine_Detector;
use DFWC\Companion\I18n\WPML_Strings;
use DFWC\Companion\Taxonomy\Campaign_Taxonomies;

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
	private static array $saved_in_request = array();

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'wc_donation_after_save_campaign_meta', array( $this, 'save' ), 20, 1 );
		// Fallback for block-editor saves that bypass the parent's hook.
		add_action( 'save_post_' . self::PARENT_POST_TYPE, array( $this, 'save' ), 20, 1 );
	}

	public function register(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Interval-First Donation Form', 'dfwc-companion' ),
			array( $this, 'render_intervals' ),
			self::PARENT_POST_TYPE,
			'normal',
			'high'
		);

		// Phase 4: featured-status side meta box for the directory grid.
		add_meta_box(
			'dfwc_companion_featured',
			__( 'Featured campaign', 'dfwc-companion' ),
			array( $this, 'render_featured' ),
			self::PARENT_POST_TYPE,
			'side',
			'default'
		);

		// v2.3.0 (Phase 13.E): per-campaign crypto donation routing.
		// Only registered when global crypto is on + TGB is connected, so
		// admins on sites that don't use crypto don't see an empty box.
		$global = \DFWC\Companion\Config\Config_Resolver::get_global_settings();
		if ( ! empty( $global['crypto_donations_enabled'] )
			&& ( new \DFWC\Companion\Gateways\TGB_Token_Store() )->is_configured()
		) {
			add_meta_box(
				'dfwc_companion_crypto',
				__( 'Crypto donations', 'dfwc-companion' ),
				array( $this, 'render_crypto' ),
				self::PARENT_POST_TYPE,
				'side',
				'default'
			);
		}

		// v2.4.0 (Phase 14.B): per-cause goals. Registered when the
		// global feature toggle is on. Each row populates from the
		// companion's cause-id map (Cause_Identity); the box is empty
		// (with a hint) when the campaign has no causes yet.
		if ( \DFWC\Companion\Config\Cause_Goals_Schema::feature_enabled() ) {
			add_meta_box(
				'dfwc_companion_cause_goals',
				__( 'Per-cause goals', 'dfwc-companion' ),
				array( $this, 'render_cause_goals' ),
				self::PARENT_POST_TYPE,
				'normal',
				'default'
			);
		}
	}

	public function render_cause_goals( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$ids   = \DFWC\Companion\Config\Cause_Identity::for_campaign( $post->ID );
		$names = array();
		foreach ( $ids as $position => $cause_id ) {
			$names[ $cause_id ] = (string) \DFWC\Companion\Config\Cause_Identity::name_for_id( $post->ID, $cause_id );
		}

		if ( empty( $ids ) ) {
			?>
			<p class="description">
				<?php esc_html_e( 'No causes configured on this campaign yet. Add causes via the parent plugin\'s "Donation Cause" section, then return here to set per-cause goals.', 'dfwc-companion' ); ?>
			</p>
			<?php
			return;
		}

		$saved          = \DFWC\Companion\Config\Cause_Goals_Schema::for_campaign( $post->ID );
		$global_default = \DFWC\Companion\Config\Cause_Goals_Schema::resolve_global_default_mode();
		$mode_options   = array(
			\DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_INHERIT              => sprintf(
				/* translators: %s: global default closure mode label */
				__( 'Inherit (currently: %s)', 'dfwc-companion' ),
				self::closure_mode_label( $global_default )
			),
			\DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_HIDE                 => self::closure_mode_label( \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_HIDE ),
			\DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_TO_CAUSE    => self::closure_mode_label( \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_TO_CAUSE ),
			\DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN => self::closure_mode_label( \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN ),
		);
		?>
		<p class="description" style="margin-bottom: 1em;">
			<?php esc_html_e( 'Set a goal amount per cause. When a cause hits its goal, the closure mode determines what donors see (hide, prompt to pick another cause, or redirect off campaign).', 'dfwc-companion' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width: 30%;"><?php esc_html_e( 'Cause', 'dfwc-companion' ); ?></th>
					<th style="width: 10%;"><?php esc_html_e( 'Track', 'dfwc-companion' ); ?></th>
					<th style="width: 25%;"><?php esc_html_e( 'Goal amount', 'dfwc-companion' ); ?></th>
					<th><?php esc_html_e( 'Closure mode', 'dfwc-companion' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ids as $position => $cause_id ) : ?>
					<?php
					$row     = $saved[ $cause_id ] ?? array();
					$enabled = ! empty( $row['enabled'] );
					$amount  = isset( $row['goal_amount'] ) ? (float) $row['goal_amount'] : 0.0;
					$mode    = isset( $row['closure_mode'] ) ? (string) $row['closure_mode'] : \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_INHERIT;
					if ( ! array_key_exists( $mode, $mode_options ) ) {
						$mode = \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_INHERIT;
					}
					$name_for_label  = isset( $names[ $cause_id ] ) ? $names[ $cause_id ] : $cause_id;
					$enabled_field   = sprintf( 'dfwc_cause_goals[%s][enabled]', $cause_id );
					$amount_field    = sprintf( 'dfwc_cause_goals[%s][goal_amount]', $cause_id );
					$mode_field      = sprintf( 'dfwc_cause_goals[%s][closure_mode]', $cause_id );
					?>
					<tr>
						<td><strong><?php echo esc_html( $name_for_label ); ?></strong></td>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $enabled_field ); ?>" value="1" <?php checked( $enabled ); ?>>
							</label>
						</td>
						<td>
							<input
								type="number"
								step="0.01"
								min="0"
								class="widefat"
								name="<?php echo esc_attr( $amount_field ); ?>"
								value="<?php echo esc_attr( (string) $amount ); ?>"
								placeholder="0.00"
							>
						</td>
						<td>
							<select name="<?php echo esc_attr( $mode_field ); ?>" class="widefat">
								<?php foreach ( $mode_options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $mode ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function closure_mode_label( string $mode ): string {
		switch ( $mode ) {
			case \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_HIDE:
				return __( 'Hide', 'dfwc-companion' );
			case \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_TO_CAUSE:
				return __( 'Redirect to another cause', 'dfwc-companion' );
			case \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_OFF_CAMPAIGN:
				return __( 'Redirect off campaign', 'dfwc-companion' );
			case \DFWC\Companion\Config\Cause_Goals_Schema::CLOSURE_MODE_INHERIT:
				return __( 'Inherit', 'dfwc-companion' );
			default:
				return $mode;
		}
	}

	public function render_crypto( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$override   = \DFWC\Companion\Gateways\TGB_Project_Mapper::for_campaign( $post->ID );
		$is_enabled = \DFWC\Companion\Gateways\TGB_Project_Mapper::is_enabled_for_campaign( $post->ID );
		$selected   = isset( $override['tgb_project_id'] ) ? (string) $override['tgb_project_id'] : '';

		$global         = \DFWC\Companion\Config\Config_Resolver::get_global_settings();
		$default_label  = isset( $global['tgb_default_project_id'] ) && '' !== (string) $global['tgb_default_project_id']
			? sprintf(
				/* translators: %s: TGB project id used as the org default */
				__( 'Inherit (org default: %s)', 'dfwc-companion' ),
				(string) $global['tgb_default_project_id']
			)
			: __( 'Inherit (no org default set)', 'dfwc-companion' );

		$projects = \DFWC\Companion\Gateways\TGB_Projects_Cache::get();

		// Detect whether the saved project_id is in the cached list. If
		// not (newly created in TGB after our last cache refresh, or admin
		// typed a custom value), surface a "custom" hint so the dropdown
		// state is visually accurate.
		$saved_in_list = false;
		foreach ( $projects as $project ) {
			if ( (string) $project['id'] === $selected ) {
				$saved_in_list = true;
				break;
			}
		}
		?>
		<p>
			<label>
				<input
					type="checkbox"
					name="dfwc_crypto[enabled]"
					value="1"
					<?php checked( $is_enabled ); ?>
				>
				<?php esc_html_e( 'Show "Donate Crypto" button on this campaign', 'dfwc-companion' ); ?>
			</label>
		</p>
		<p>
			<label for="dfwc_crypto_project_id">
				<strong><?php esc_html_e( 'TGB Project', 'dfwc-companion' ); ?></strong>
			</label>
			<select id="dfwc_crypto_project_id" name="dfwc_crypto[tgb_project_id]" class="widefat">
				<option value=""><?php echo esc_html( $default_label ); ?></option>
				<?php foreach ( $projects as $project ) : ?>
					<option
						value="<?php echo esc_attr( $project['id'] ); ?>"
						<?php selected( $selected, $project['id'] ); ?>
					>
						<?php echo esc_html( $project['name'] . ' (' . $project['id'] . ')' ); ?>
					</option>
				<?php endforeach; ?>
				<?php if ( '' !== $selected && ! $saved_in_list ) : ?>
					<option value="<?php echo esc_attr( $selected ); ?>" selected>
						<?php
						printf(
							/* translators: %s: project id not in cached list */
							esc_html__( 'Custom: %s', 'dfwc-companion' ),
							esc_html( $selected )
						);
						?>
					</option>
				<?php endif; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'Routes crypto donations on this campaign to a specific TGB sub-project. Inherit uses the org-wide default from the Crypto settings page.', 'dfwc-companion' ); ?>
			<?php if ( empty( $projects ) ) : ?>
				<br><em><?php esc_html_e( 'Project list is empty — refresh under WooCommerce → Donations Companion → Crypto Donations.', 'dfwc-companion' ); ?></em>
			<?php endif; ?>
		</p>
		<?php
	}

	public function render_featured( \WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$is_featured = (bool) get_post_meta( $post->ID, Campaign_Taxonomies::META_KEY_FEATURED, true );
		?>
		<p>
			<label>
				<input
					type="checkbox"
					name="dfwc_featured"
					value="1"
					<?php checked( $is_featured ); ?>
				>
				<?php esc_html_e( 'Mark this campaign as featured', 'dfwc-companion' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Featured campaigns sort first in the [dfwc_campaign_grid] directory when sorted by "Featured first".', 'dfwc-companion' ); ?>
		</p>
		<?php
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

		// v0.7.0: template selector data for the meta-box header.
		$campaign_repo  = new Campaign_Config_Repository();
		$template_repo  = new Template_Repository();
		$current_tpl_id = $campaign_repo->get_template_id( $post->ID );
		$is_detached    = $campaign_repo->is_detached( $post->ID );
		$all_templates  = $template_repo->all();

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
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
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

		// v0.7.0: handle template selector first. Admin may have applied,
		// detached, reset, or simply re-saved the existing template choice.
		$campaign_repo = new Campaign_Config_Repository();
		$this->handle_template_actions( $post_id, $campaign_repo );

		// Migrate legacy v0.6.x meta to overrides on first v0.7.0 save.
		// Idempotent — no-op when overrides already populated.
		$campaign_repo->migrate_legacy_to_overrides_if_needed( $post_id );

		// Sanitize the form-submitted intervals + display.
		$raw_intervals = isset( $_POST['dfwc_intervals'] ) && is_array( $_POST['dfwc_intervals'] )
			? wp_unslash( $_POST['dfwc_intervals'] )
			: array();

		$submitted_config = array();
		// Always sanitize all 7 interval slots (standard + advanced) so toggling
		// the global advanced-intervals setting on or off doesn't lose admin's
		// already-saved data. The renderer respects the toggle independently.
		foreach ( Config_Resolver::intervals( true ) as $key ) {
			$submitted_config[ $key ] = $this->sanitize_interval_block(
				is_array( $raw_intervals[ $key ] ?? null ) ? $raw_intervals[ $key ] : array(),
				$key
			);
		}

		// Reject misconfigured recurring tabs (enabled with no presets and high min).
		foreach ( array( Config_Resolver::INTERVAL_MONTHLY, Config_Resolver::INTERVAL_ANNUAL ) as $rk ) {
			if ( $submitted_config[ $rk ]['enabled'] && empty( $submitted_config[ $rk ]['presets'] ) && $submitted_config[ $rk ]['min'] > 1000 ) {
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

		// Display sub-tree.
		$display_raw = isset( $_POST['dfwc_display'] ) && is_array( $_POST['dfwc_display'] )
			? wp_unslash( $_POST['dfwc_display'] )
			: array();
		$submitted_config['display'] = $this->sanitize_display( $display_raw );

		// v0.7.0 layered model: compute the override delta against the campaign's
		// baseline (defaults → global → template, no campaign overrides). Only
		// fields that differ from baseline land in the overrides meta — keeps
		// inheritance honest so future template/global changes propagate.
		$baseline = $campaign_repo->compute_baseline( $post_id );
		$delta    = $campaign_repo->compute_override_delta( $baseline, $submitted_config );
		$campaign_repo->set_overrides( $post_id, $delta );

		// Resolve fresh — this is the config the rest of the save logic operates on.
		$resolved = Config_Resolver::resolve( $post_id );

		// D1: parent only processes recurring POST data when wc-donation-recurring='user'.
		// Any non-one_time interval implies recurring. Phase 7 added weekly /
		// quarterly / semiannual / custom — all recurring.
		$recurring_keys    = array_diff(
			Config_Resolver::intervals( true ),
			array( Config_Resolver::INTERVAL_ONE_TIME )
		);
		$recurring_enabled = false;
		$primary_interval  = '';
		foreach ( $recurring_keys as $rkey ) {
			if ( ! empty( $resolved[ $rkey ]['enabled'] ) ) {
				$recurring_enabled = true;
				if ( '' === $primary_interval ) {
					$primary_interval = $rkey;
				}
			}
		}

		if ( $recurring_enabled ) {
			update_post_meta( $post_id, 'wc-donation-recurring', 'user' );

			$primary_cadence    = Engine_Interval_Map::for_interval(
				$primary_interval,
				is_array( $resolved[ $primary_interval ] ?? null ) ? $resolved[ $primary_interval ] : array()
			);
			$primary_period     = is_array( $primary_cadence ) ? (string) $primary_cadence['period'] : 'month';
			$primary_multiplier = is_array( $primary_cadence ) ? (int) $primary_cadence['interval'] : 1;

			update_post_meta( $post_id, '_subscription_period', $primary_period );
			update_post_meta( $post_id, '_subscription_period_interval', (string) $primary_multiplier );
			update_post_meta( $post_id, '_subscription_length', '0' );

			$this->auto_configure_product_subscription( $post_id, $primary_period, $primary_multiplier );
		}

		// Register translatable strings with WPML for this campaign's overrides.
		// No-op when WPML inactive or delta is empty.
		if ( WPML_Strings::wpml_active() && ! empty( $delta ) ) {
			$this->register_campaign_strings_with_wpml( $post_id, $delta );
		}

		// Phase 4: featured flag (separate side meta box).
		// HTML form-checkbox semantics: missing = unchecked = false.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above
		$is_featured = ! empty( $_POST['dfwc_featured'] );
		// phpcs:enable
		if ( $is_featured ) {
			update_post_meta( $post_id, Campaign_Taxonomies::META_KEY_FEATURED, '1' );
		} else {
			delete_post_meta( $post_id, Campaign_Taxonomies::META_KEY_FEATURED );
		}

		// v2.3.0 (Phase 13.E): per-campaign crypto routing. The crypto
		// meta box only renders when global crypto is on, so its POST
		// keys are only present in that case. When the box was rendered
		// + the form submitted, write the explicit values; otherwise
		// leave existing overrides untouched (admin may have toggled
		// global crypto off after configuring per-campaign).
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above
		if ( isset( $_POST['dfwc_crypto'] ) && is_array( $_POST['dfwc_crypto'] ) ) {
			$crypto_raw = wp_unslash( $_POST['dfwc_crypto'] );
			$crypto_in  = array(
				// HTML checkbox semantics: missing = unchecked.
				'enabled'        => ! empty( $crypto_raw['enabled'] ),
				'tgb_project_id' => isset( $crypto_raw['tgb_project_id'] )
					? (string) $crypto_raw['tgb_project_id']
					: '',
			);
			\DFWC\Companion\Gateways\TGB_Project_Mapper::set( $post_id, $crypto_in );
		}

		// v2.4.0 (Phase 14.B): per-cause goals. The cause-goals meta
		// box only renders when global cause goals is on AND the
		// campaign has parent causes. When the box rendered, the form
		// always carries `dfwc_cause_goals` keyed by companion cause id
		// (one row per cause). Sanitizer drops all-default rows so the
		// stored shape stays minimal.
		if ( isset( $_POST['dfwc_cause_goals'] ) && is_array( $_POST['dfwc_cause_goals'] ) ) {
			$cause_goals_raw = wp_unslash( $_POST['dfwc_cause_goals'] );
			\DFWC\Companion\Config\Cause_Goals_Schema::set( $post_id, $cause_goals_raw );
		}
		// phpcs:enable
	}

	/**
	 * Handle template-related actions on the meta-box save: applying a new
	 * template, detaching, or resetting. Action comes from a hidden field
	 * `dfwc_template_action` populated by the meta-box JS.
	 */
	private function handle_template_actions( int $post_id, Campaign_Config_Repository $repo ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified by parent save method
		$selected_id = isset( $_POST['dfwc_template_id'] )
			? sanitize_key( (string) wp_unslash( $_POST['dfwc_template_id'] ) )
			: null;
		$action      = isset( $_POST['dfwc_template_action'] )
			? sanitize_key( (string) wp_unslash( $_POST['dfwc_template_action'] ) )
			: '';
		// phpcs:enable

		switch ( $action ) {
			case 'apply':
				if ( null !== $selected_id ) {
					if ( '' === $selected_id ) {
						$repo->set_template_id( $post_id, '' );
						$repo->set_overrides( $post_id, array() );
					} else {
						$repo->apply_template( $post_id, $selected_id );
					}
				}
				return;
			case 'detach':
				$repo->detach_from_template( $post_id );
				return;
			case 'reset':
				$repo->reset_to_template( $post_id );
				return;
			default:
				// No template action — keep existing assignment. Still allow the
				// admin to change selection without applying (selector value
				// drives `set_template_id` only on explicit apply).
				if ( null !== $selected_id && '' === $selected_id ) {
					// Empty value with no action = clear template if it was set.
					$current = $repo->get_template_id( $post_id );
					if ( '' !== $current ) {
						$repo->set_template_id( $post_id, '' );
					}
				}
		}
	}

	/**
	 * Register a campaign's override strings with WPML String Translation
	 * under the `_campaign:<id>` namespace. Mirrors Template_Repository's
	 * registration pattern.
	 *
	 * @param array<string,mixed> $delta
	 */
	private function register_campaign_strings_with_wpml( int $campaign_id, array $delta ): void {
		$namespace = '_campaign:' . $campaign_id;
		foreach ( Config_Resolver::intervals( true ) as $interval ) {
			$block = $delta[ $interval ] ?? null;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$translatable_fields = array( 'cta_template', 'subtitle', 'annual_equivalency', 'custom_amount_impact_label' );
			if ( Config_Resolver::INTERVAL_CUSTOM === $interval ) {
				$translatable_fields[] = 'custom_label';
			}
			foreach ( $translatable_fields as $field ) {
				if ( ! empty( $block[ $field ] ) ) {
					WPML_Strings::register(
						$namespace . '.' . $interval . '.' . $field,
						(string) $block[ $field ]
					);
				}
			}
			foreach ( (array) ( $block['presets'] ?? array() ) as $idx => $preset ) {
				if ( ! is_array( $preset ) ) {
					continue;
				}
				foreach ( array( 'label', 'impact_label' ) as $preset_field ) {
					if ( ! empty( $preset[ $preset_field ] ) ) {
						WPML_Strings::register(
							$namespace . '.' . $interval . '.presets.' . (int) $idx . '.' . $preset_field,
							(string) $preset[ $preset_field ]
						);
					}
				}
			}
		}
		if ( ! empty( $delta['display']['cause_heading'] ) ) {
			WPML_Strings::register(
				$namespace . '.display.cause_heading',
				(string) $delta['display']['cause_heading']
			);
		}
	}

	/**
	 * Clean up one interval block from raw POST. Re-runs the same validations
	 * Config_Resolver does, but starting from un-sanitized POST instead of
	 * already-stored array.
	 */
	private function sanitize_interval_block( array $raw, string $interval_key = '' ): array {
		$enabled               = ! empty( $raw['enabled'] );
		// HTML form-checkbox semantics: an UNCHECKED checkbox sends nothing in
		// $_POST. Use !empty() (missing = false) — the previous isset()-with-
		// default-true logic re-enabled the toggle every save when the admin
		// unchecked it. The meta-box template always emits the checkbox, so
		// missing here is a deliberate uncheck, not a legacy-data scenario.
		$custom_amount_enabled = ! empty( $raw['custom_amount_enabled'] );

		$presets_raw = isset( $raw['presets'] ) && is_array( $raw['presets'] ) ? $raw['presets'] : array();
		$presets     = array();
		foreach ( $presets_raw as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$amount_str = isset( $row['amount'] ) ? (string) $row['amount'] : '';
			$amount     = (float) wc_format_decimal( $amount_str, wc_get_price_decimals() );
			if ( $amount <= 0 ) {
				continue;
			}
			$presets[] = array(
				'amount'       => $amount,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'impact_label' => isset( $row['impact_label'] ) ? sanitize_text_field( (string) $row['impact_label'] ) : '',
				'is_featured'  => ! empty( $row['is_featured'] ),
				'sort_order'   => isset( $row['sort_order'] ) ? (int) $row['sort_order'] : ( ( $idx + 1 ) * 10 ),
			);
		}

		// Enforce single-featured per interval (last-write-wins); admins
		// often forget the radio-style semantics, so we make the data honest.
		$found_featured = false;
		foreach ( $presets as $i => $preset ) {
			if ( $preset['is_featured'] && $found_featured ) {
				$presets[ $i ]['is_featured'] = false;
			} elseif ( $preset['is_featured'] ) {
				$found_featured = true;
			}
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

		// Phase 5 — donor impact messaging fields.
		$subtitle                   = isset( $raw['subtitle'] ) ? sanitize_text_field( (string) $raw['subtitle'] ) : '';
		$annual_equivalency         = isset( $raw['annual_equivalency'] ) ? sanitize_text_field( (string) $raw['annual_equivalency'] ) : '';
		$custom_amount_impact_label = isset( $raw['custom_amount_impact_label'] ) ? sanitize_text_field( (string) $raw['custom_amount_impact_label'] ) : '';
		$impact_mode                = isset( $raw['impact_display_mode'] ) ? sanitize_key( (string) $raw['impact_display_mode'] ) : 'below_button';
		if ( ! in_array( $impact_mode, \DFWC\Companion\Config\Defaults::impact_display_modes(), true ) ) {
			$impact_mode = 'below_button';
		}

		$currency_overrides = Currency_Preset_Resolver::sanitize_currency_overrides(
			$raw['currency_overrides'] ?? null,
			count( $presets )
		);

		$out = array(
			'enabled'                    => $enabled,
			'presets'                    => $presets,
			'min'                        => $min,
			'max'                        => $max,
			'default_index'              => $default_index,
			'cta_template'               => $cta,
			'custom_amount_enabled'      => $custom_amount_enabled,
			'subtitle'                   => $subtitle,
			'annual_equivalency'         => $annual_equivalency,
			'impact_display_mode'        => $impact_mode,
			'custom_amount_impact_label' => $custom_amount_impact_label,
			'currency_overrides'         => $currency_overrides,
		);

		// Phase 7 — `custom` interval persists its own cadence fields.
		if ( Config_Resolver::INTERVAL_CUSTOM === $interval_key ) {
			$out['custom_period']   = Engine_Interval_Map::sanitize_period(
				(string) ( $raw['custom_period'] ?? 'month' )
			);
			$out['custom_interval'] = Engine_Interval_Map::sanitize_multiplier(
				(int) ( $raw['custom_interval'] ?? 1 )
			);
			$out['custom_label']    = isset( $raw['custom_label'] )
				? sanitize_text_field( (string) $raw['custom_label'] )
				: '';
		}

		return $out;
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
		return array(
			'show_title'    => ! empty( $raw['show_title'] ),
			'show_image'    => ! empty( $raw['show_image'] ),
			'cause_heading' => isset( $raw['cause_heading'] )
				? sanitize_text_field( (string) $raw['cause_heading'] )
				: $defaults['cause_heading'],
		);
	}

	/**
	 * Auto-configure the linked WC product as a subscription product for the
	 * active engine. Called from save() when monthly/annual is enabled.
	 *
	 * As of v0.6.5 this method is no longer load-bearing for the WPS SFW
	 * marker meta — parent's own save handler writes _wps_sfw_product /
	 * _wps_sfw_users from the posted wc-donation-recurring value (which our
	 * admin tab injector auto-sets to 'user'). We still call this for the
	 * cadence meta (wps_sfw_subscription_*) and the WCS product_type taxonomy
	 * term, both of which parent does NOT manage on its own.
	 *
	 * Engine semantics:
	 * - WPS SFW: subscription products marked by `_wps_sfw_product='yes'`.
	 *   Cadence meta (wps_sfw_subscription_*) drives the engine's renewal
	 *   schedule. Empty expiry = open-ended (forever).
	 * - WCS: product type taxonomy term must be `subscription`. Plus the
	 *   _subscription_* product meta drives WCS's renewal logic.
	 *
	 * No-op when engine='none'. Defensive against missing/non-subscription-
	 * compatible products.
	 */
	private function auto_configure_product_subscription( int $campaign_id, string $primary_period, int $primary_multiplier = 1 ): void {
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
			update_post_meta( $product_id, '_subscription_period_interval', (string) $primary_multiplier );
			update_post_meta( $product_id, '_subscription_length', '0' );
			// _subscription_price intentionally not set — donor's amount is the price.
		} elseif ( Engine_Detector::ENGINE_WPS === $engine ) {
			// WPS SFW recognizes subscription products via _wps_sfw_product='yes'.
			// As of 0.6.5 the canonical writer is parent's own save handler at
			// class-wcdonationcampaignsetting.php:1740 — it derives the marker
			// from $_POST['wc-donation-recurring'], which our admin tab injector
			// auto-sets to 'user' whenever monthly/annual is enabled. We still
			// seed the cadence meta here so WPS SFW knows the period without
			// requiring the admin to fill out parent's now-redundant cadence
			// inputs. Parent's _wps_sfw_product / _wps_sfw_users writes will
			// run after this method (we are inside save() at hook
			// `wc_donation_after_save_campaign_meta`, which fires from line
			// 1834 — after parent's lines 1740-1744 have already run with the
			// posted 'user' value). Re-asserting the marker here is harmless
			// belt-and-suspenders.
			$writes = array(
				'_wps_sfw_product'                     => 'yes',
				'_wps_sfw_users'                       => 'user',
				'wps_sfw_subscription_number'          => (string) $primary_multiplier,
				'wps_sfw_subscription_interval'        => $primary_period,
				'wps_sfw_subscription_expiry_number'   => '',
				'wps_sfw_subscription_expiry_interval' => '',
			);
			foreach ( $writes as $key => $value ) {
				if ( function_exists( 'wps_sfw_update_meta_data' ) ) {
					wps_sfw_update_meta_data( $product_id, $key, $value );
				} else {
					update_post_meta( $product_id, $key, $value );
				}
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
				? implode( ',', wp_get_post_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) ) )
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
		return array(
			Config_Resolver::INTERVAL_ONE_TIME   => __( 'One-time', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_MONTHLY    => __( 'Monthly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL     => __( 'Annually', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_WEEKLY     => __( 'Weekly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_QUARTERLY  => __( 'Quarterly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_SEMIANNUAL => __( 'Semi-annually', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_CUSTOM     => __( 'Custom cadence', 'dfwc-companion' ),
		);
	}
}
