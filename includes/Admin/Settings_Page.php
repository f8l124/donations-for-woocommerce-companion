<?php
/**
 * Settings_Page — global settings UI.
 *
 * Lives at WooCommerce → Donations Companion (default landing). Uses the
 * WordPress Settings API for native admin look — `<form action="options.php">`,
 * `register_setting`, `add_settings_section`, `submit_button`, `settings_errors`.
 *
 * Scope in v0.7.0:
 * - Default template for new campaigns
 * - Preserve data on uninstall toggle (Phase 11 wires it into uninstall.php)
 * - Future global default interval baseline (Phase 5/6/7 expand)
 *
 * Tight integration: WP-native Settings API patterns; admins recognize
 * the form-table layout. No bespoke admin framework.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Template_Repository;
use DFWC\Companion\I18n\WPML_Strings;

final class Settings_Page {

	public const OPTION_GROUP = 'dfwc_companion_global';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Config_Resolver::OPTION_KEY_GLOBAL,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Defaults::for_global(),
			)
		);

		add_settings_section(
			'dfwc_section_general',
			__( 'General', 'dfwc-companion' ),
			static function () {
				echo '<p class="description">' . esc_html__(
					'Plugin-wide defaults applied to every campaign. Per-campaign settings override these values; templates sit between (Defaults → Global → Template → Campaign).',
					'dfwc-companion'
				) . '</p>';
			},
			'dfwc-companion'
		);

		add_settings_field(
			'default_template_id',
			__( 'Default template for new campaigns', 'dfwc-companion' ),
			array( $this, 'render_default_template_field' ),
			'dfwc-companion',
			'dfwc_section_general'
		);

		add_settings_field(
			'preserve_data_on_uninstall',
			__( 'Preserve data on uninstall', 'dfwc-companion' ),
			array( $this, 'render_preserve_uninstall_field' ),
			'dfwc-companion',
			'dfwc_section_general'
		);

		add_settings_field(
			'enable_advanced_intervals',
			__( 'Advanced giving intervals', 'dfwc-companion' ),
			array( $this, 'render_advanced_intervals_field' ),
			'dfwc-companion',
			'dfwc_section_general'
		);

		// Phase 13 (v1.2.0) — goal-aware giving fields.
		add_settings_section(
			'dfwc_section_goal_aware',
			__( 'Goal-aware giving', 'dfwc-companion' ),
			static function () {
				echo '<p class="description">' . esc_html__(
					'When the parent plugin\'s campaign goal is configured (Goal Type = Fixed Amount), let the companion clamp donor amounts to remaining-goal and offer a general-fund alternative once the campaign is fully funded.',
					'dfwc-companion'
				) . '</p>';
			},
			'dfwc-companion'
		);

		add_settings_field(
			'enable_goal_based_max',
			__( 'Clamp max to remaining goal', 'dfwc-companion' ),
			array( $this, 'render_goal_based_max_field' ),
			'dfwc-companion',
			'dfwc_section_goal_aware'
		);

		add_settings_field(
			'enable_fully_funded_redirect',
			__( 'Block donations to fully-funded campaigns', 'dfwc-companion' ),
			array( $this, 'render_fully_funded_redirect_field' ),
			'dfwc-companion',
			'dfwc_section_goal_aware'
		);

		add_settings_field(
			'general_fund_campaign_id',
			__( 'General fund campaign', 'dfwc-companion' ),
			array( $this, 'render_general_fund_field' ),
			'dfwc-companion',
			'dfwc_section_goal_aware'
		);

		// Phase 14A — stock donation settings.
		add_settings_section(
			'dfwc_section_stock',
			__( 'Stock donations', 'dfwc-companion' ),
			static function () {
				echo '<p class="description">' . esc_html__(
					'Accept gifts of appreciated stock. Choose between the built-in pledge form (donor fills our form; we email DTC instructions; you reconcile when shares clear) or Overflow (overflow.co — routes donors to your Overflow-hosted donation page).',
					'dfwc-companion'
				) . '</p>';
			},
			'dfwc-companion'
		);

		add_settings_field(
			'stock_donations_enabled',
			__( 'Enable stock donations', 'dfwc-companion' ),
			array( $this, 'render_stock_enabled_field' ),
			'dfwc-companion',
			'dfwc_section_stock'
		);

		add_settings_field(
			'stock_giving_mode',
			__( 'Mode', 'dfwc-companion' ),
			array( $this, 'render_stock_mode_field' ),
			'dfwc-companion',
			'dfwc_section_stock'
		);

		add_settings_field(
			'stock_pledge_form_settings',
			__( 'Pledge-form mode settings', 'dfwc-companion' ),
			array( $this, 'render_stock_pledge_form_fields' ),
			'dfwc-companion',
			'dfwc_section_stock'
		);

		add_settings_field(
			'stock_overflow_settings',
			__( 'Overflow mode settings', 'dfwc-companion' ),
			array( $this, 'render_stock_overflow_fields' ),
			'dfwc-companion',
			'dfwc_section_stock'
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}
		include DFWC_COMPANION_PATH . 'templates/settings-page.php';
	}

	public function render_default_template_field(): void {
		$current   = (string) ( Config_Resolver::get_global_settings()['default_template_id'] ?? '' );
		$templates = ( new Template_Repository() )->all();
		?>
		<select name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[default_template_id]" id="dfwc-default-template-id">
			<option value="" <?php selected( '', $current ); ?>>
				<?php esc_html_e( '— None (use plugin defaults) —', 'dfwc-companion' ); ?>
			</option>
			<?php foreach ( $templates as $tpl ) : ?>
				<option value="<?php echo esc_attr( $tpl->id ); ?>" <?php selected( $tpl->id, $current ); ?>>
					<?php echo esc_html( WPML_Strings::translate( $tpl->name, $tpl->id . '.name' ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'New campaigns inherit from this template by default. Existing campaigns are unaffected by this setting.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_advanced_intervals_field(): void {
		$current = (bool) ( Config_Resolver::get_global_settings()['enable_advanced_intervals'] ?? false );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[enable_advanced_intervals]"
				value="1"
				<?php checked( $current ); ?>
			>
			<?php esc_html_e( 'Enable advanced giving intervals (weekly, quarterly, semi-annually, custom cadence).', 'dfwc-companion' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Off by default — donors see the standard One-time / Monthly / Annually tabs. Turn on to expose extra intervals in the campaign meta box and Templates page; each interval is then per-campaign / per-template opt-in via its own enable checkbox.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_preserve_uninstall_field(): void {
		$current = (bool) ( Config_Resolver::get_global_settings()['preserve_data_on_uninstall'] ?? true );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[preserve_data_on_uninstall]"
				value="1"
				<?php checked( $current ); ?>
			>
			<?php esc_html_e( 'Keep templates, settings, and campaign config when the plugin is deleted.', 'dfwc-companion' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Off = a clean uninstall removes all companion data (post meta, options). Default: on.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_goal_based_max_field(): void {
		$current = (bool) ( Config_Resolver::get_global_settings()['enable_goal_based_max'] ?? false );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[enable_goal_based_max]"
				value="1"
				<?php checked( $current ); ?>
			>
			<?php esc_html_e( 'Cap the donor\'s max custom amount at the campaign\'s remaining goal.', 'dfwc-companion' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When the parent plugin\'s goal type is "Fixed Amount", a donor on the One-time tab cannot exceed (goal − raised). Recurring intervals are not clamped (the first charge fits, but renewals would exceed). Per-currency presets resolve in base currency.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_fully_funded_redirect_field(): void {
		$current = (bool) ( Config_Resolver::get_global_settings()['enable_fully_funded_redirect'] ?? false );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[enable_fully_funded_redirect]"
				value="1"
				<?php checked( $current ); ?>
			>
			<?php esc_html_e( 'Reject donor submissions when the campaign is fully funded.', 'dfwc-companion' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Off (default): the donor sees a "Goal met! Support the General Fund instead?" card above the form, but can still donate to the funded campaign. On: the companion blocks the submit and redirects donors to the General Fund campaign (configured below).', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_general_fund_field(): void {
		$current      = (int) ( Config_Resolver::get_global_settings()['general_fund_campaign_id'] ?? 0 );
		$campaigns    = function_exists( 'get_posts' )
			? get_posts(
				array(
					'post_type'      => 'wc-donation',
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			)
			: array();
		?>
		<select name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[general_fund_campaign_id]" id="dfwc-general-fund-campaign-id">
			<option value="0" <?php selected( 0, $current ); ?>>
				<?php esc_html_e( '— None (no general-fund affordance) —', 'dfwc-companion' ); ?>
			</option>
			<?php foreach ( $campaigns as $campaign_post ) : ?>
				<option value="<?php echo (int) $campaign_post->ID; ?>" <?php selected( (int) $campaign_post->ID, $current ); ?>>
					<?php echo esc_html( get_the_title( $campaign_post ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'The campaign donors are offered when another campaign reaches its goal. Typically named "General Fund", "Where Most Needed", or similar — a never-ending campaign that absorbs overflow giving.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_stock_enabled_field(): void {
		$current = (bool) ( Config_Resolver::get_global_settings()['stock_donations_enabled'] ?? false );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_donations_enabled]"
				value="1"
				<?php checked( $current ); ?>
			>
			<?php esc_html_e( 'Show a "Donate stock" affordance on the donor form.', 'dfwc-companion' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Off by default. When on, the affordance appears below the cash-donation form using whichever mode is selected below.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_stock_mode_field(): void {
		$current = (string) ( Config_Resolver::get_global_settings()['stock_giving_mode'] ?? 'pledge_form' );
		?>
		<select name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_giving_mode]" id="dfwc-stock-giving-mode">
			<option value="pledge_form" <?php selected( 'pledge_form', $current ); ?>>
				<?php esc_html_e( 'Built-in pledge form (DIY)', 'dfwc-companion' ); ?>
			</option>
			<option value="overflow" <?php selected( 'overflow', $current ); ?>>
				<?php esc_html_e( 'Overflow (overflow.co hosted page)', 'dfwc-companion' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( '"Pledge form" captures donor + stock details, emails DTC instructions, and you reconcile when shares clear. "Overflow" routes donors to your overflow.co donation page; their team handles broker connection + transfer.', 'dfwc-companion' ); ?>
		</p>
		<?php
	}

	public function render_stock_pledge_form_fields(): void {
		$global = Config_Resolver::get_global_settings();
		?>
		<fieldset>
			<p>
				<label for="dfwc-stock-broker-name"><strong><?php esc_html_e( 'Receiving broker name', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="text"
					id="dfwc-stock-broker-name"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_broker_name]"
					value="<?php echo esc_attr( (string) ( $global['stock_broker_name'] ?? '' ) ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. Charles Schwab', 'dfwc-companion' ); ?>"
				>
			</p>
			<p>
				<label for="dfwc-stock-dtc-account"><strong><?php esc_html_e( 'DTC account number', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="text"
					id="dfwc-stock-dtc-account"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_dtc_account_number]"
					value="<?php echo esc_attr( (string) ( $global['stock_dtc_account_number'] ?? '' ) ); ?>"
					class="regular-text"
				>
			</p>
			<p>
				<label for="dfwc-stock-dtc-clearing"><strong><?php esc_html_e( 'DTC clearing-house number', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="text"
					id="dfwc-stock-dtc-clearing"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_dtc_clearing_house_number]"
					value="<?php echo esc_attr( (string) ( $global['stock_dtc_clearing_house_number'] ?? '' ) ); ?>"
					class="regular-text"
				>
			</p>
			<p>
				<label for="dfwc-stock-admin-email"><strong><?php esc_html_e( 'Admin notification email', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="email"
					id="dfwc-stock-admin-email"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_admin_email]"
					value="<?php echo esc_attr( (string) ( $global['stock_admin_email'] ?? '' ) ); ?>"
					class="regular-text"
					placeholder="<?php echo esc_attr( function_exists( 'get_option' ) ? (string) get_option( 'admin_email' ) : '' ); ?>"
				>
			</p>
			<p>
				<label for="dfwc-stock-tax-id"><strong><?php esc_html_e( 'Organization tax ID (EIN)', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="text"
					id="dfwc-stock-tax-id"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_tax_id]"
					value="<?php echo esc_attr( (string) ( $global['stock_tax_id'] ?? '' ) ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. 12-3456789', 'dfwc-companion' ); ?>"
				>
			</p>
			<p class="description">
				<?php esc_html_e( 'These fields are emailed to the donor as DTC transfer instructions for their broker. Required for Pledge-form mode.', 'dfwc-companion' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function render_stock_overflow_fields(): void {
		$global = Config_Resolver::get_global_settings();
		?>
		<fieldset>
			<p>
				<label for="dfwc-stock-overflow-url"><strong><?php esc_html_e( 'Your Overflow donation URL', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="url"
					id="dfwc-stock-overflow-url"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_overflow_url]"
					value="<?php echo esc_attr( (string) ( $global['stock_overflow_url'] ?? '' ) ); ?>"
					class="regular-text code"
					placeholder="https://overflow.co/donate/your-org"
				>
			</p>
			<p>
				<label for="dfwc-stock-overflow-secret"><strong><?php esc_html_e( 'Webhook secret (optional)', 'dfwc-companion' ); ?></strong></label><br>
				<input
					type="text"
					id="dfwc-stock-overflow-secret"
					name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[stock_overflow_webhook_secret]"
					value="<?php echo esc_attr( (string) ( $global['stock_overflow_webhook_secret'] ?? '' ) ); ?>"
					class="regular-text code"
				>
			</p>
			<p class="description">
				<?php
				$webhook_url = function_exists( 'rest_url' )
					? (string) rest_url( 'dfwc-companion/v1/overflow-webhook' )
					: '/wp-json/dfwc-companion/v1/overflow-webhook';
				printf(
					/* translators: %s: webhook URL */
					esc_html__(
						'When set, configure the same secret in Overflow\'s webhook settings pointing to: %s. The companion verifies HMAC-SHA256 signatures on incoming webhooks and creates pledge records automatically. Without a secret, donors complete on Overflow and you reconcile in Overflow\'s dashboard.',
						'dfwc-companion'
					),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				);
				?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Sanitize callback for the global-settings option. Receives the raw
	 * POSTed array and returns a sanitized array suitable for storage.
	 *
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	public function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$existing = Config_Resolver::get_global_settings();

		$default_template_id = isset( $raw['default_template_id'] ) ? sanitize_key( (string) $raw['default_template_id'] ) : '';

		// Validate the template exists; admins should never see a stale value
		// after they delete a template, so default_template_id resets when
		// the named template no longer exists.
		if ( '' !== $default_template_id ) {
			$repo = new Template_Repository();
			if ( ! $repo->exists( $default_template_id ) ) {
				$default_template_id = '';
			}
		}

		// HTML form-checkbox semantics: missing = unchecked = false.
		$preserve          = ! empty( $raw['preserve_data_on_uninstall'] );
		$advanced_enabled  = ! empty( $raw['enable_advanced_intervals'] );

		// Phase 13 — goal-aware giving fields.
		$goal_based_max          = ! empty( $raw['enable_goal_based_max'] );
		$fully_funded_redirect   = ! empty( $raw['enable_fully_funded_redirect'] );
		$general_fund_campaign_id = isset( $raw['general_fund_campaign_id'] )
			? absint( $raw['general_fund_campaign_id'] )
			: 0;

		// Validate the general-fund campaign actually exists + is the right
		// post type. Stale ids reset to 0 so the donor-facing affordance
		// silently disappears rather than linking to a 404.
		if ( $general_fund_campaign_id > 0 ) {
			$post = function_exists( 'get_post' ) ? get_post( $general_fund_campaign_id ) : null;
			if ( ! $post || 'wc-donation' !== $post->post_type ) {
				$general_fund_campaign_id = 0;
			}
		}

		// Phase 14A — stock donation fields.
		$stock_enabled = ! empty( $raw['stock_donations_enabled'] );
		$stock_mode    = isset( $raw['stock_giving_mode'] ) ? sanitize_key( (string) $raw['stock_giving_mode'] ) : 'pledge_form';
		if ( ! in_array( $stock_mode, array( 'pledge_form', 'overflow' ), true ) ) {
			$stock_mode = 'pledge_form';
		}
		$stock_broker_name      = isset( $raw['stock_broker_name'] ) ? sanitize_text_field( (string) $raw['stock_broker_name'] ) : '';
		$stock_dtc_account      = isset( $raw['stock_dtc_account_number'] ) ? sanitize_text_field( (string) $raw['stock_dtc_account_number'] ) : '';
		$stock_dtc_clearing     = isset( $raw['stock_dtc_clearing_house_number'] ) ? sanitize_text_field( (string) $raw['stock_dtc_clearing_house_number'] ) : '';
		$stock_admin_email      = isset( $raw['stock_admin_email'] ) ? sanitize_email( (string) $raw['stock_admin_email'] ) : '';
		$stock_tax_id           = isset( $raw['stock_tax_id'] ) ? sanitize_text_field( (string) $raw['stock_tax_id'] ) : '';
		$stock_overflow_url     = isset( $raw['stock_overflow_url'] ) ? esc_url_raw( (string) $raw['stock_overflow_url'] ) : '';
		$stock_overflow_secret  = isset( $raw['stock_overflow_webhook_secret'] ) ? sanitize_text_field( (string) $raw['stock_overflow_webhook_secret'] ) : '';

		return array_merge(
			$existing,
			array(
				'version'                       => 1,
				'default_template_id'           => $default_template_id,
				'preserve_data_on_uninstall'    => $preserve,
				'enable_advanced_intervals'     => $advanced_enabled,
				'enable_goal_based_max'         => $goal_based_max,
				'enable_fully_funded_redirect'  => $fully_funded_redirect,
				'general_fund_campaign_id'      => $general_fund_campaign_id,
				'stock_donations_enabled'       => $stock_enabled,
				'stock_giving_mode'             => $stock_mode,
				'stock_broker_name'             => $stock_broker_name,
				'stock_dtc_account_number'      => $stock_dtc_account,
				'stock_dtc_clearing_house_number' => $stock_dtc_clearing,
				'stock_admin_email'             => $stock_admin_email,
				'stock_tax_id'                  => $stock_tax_id,
				'stock_overflow_url'            => $stock_overflow_url,
				'stock_overflow_webhook_secret' => $stock_overflow_secret,
			)
		);
	}
}
