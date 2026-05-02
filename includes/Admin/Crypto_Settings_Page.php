<?php
/**
 * Crypto_Settings_Page — admin UI for The Giving Block (TGB) integration.
 *
 * Lives at WooCommerce → Donations Companion → Crypto Donations. Two
 * sections:
 *
 *   - Connection: environment toggle, organization id, api key, webhook
 *     secret, default project id. Includes a "Test connection" button that
 *     pings TGB's /organization endpoint and surfaces the response.
 *   - Donor Experience: master toggle (`crypto_donations_enabled`).
 *
 * Data layout:
 *   - Public fields (env, org_id, default_project_id, master toggle) live
 *     in `dfwc_companion_global_settings` and flow through Config_Resolver
 *     identically to other companion settings. The save handler merges
 *     into the existing option so it never stomps fields owned by other
 *     settings pages.
 *   - Secret fields (api_key, webhook_secret) live in the separate
 *     TGB_Token_Store option, AES-256-CBC encrypted at rest. Settings page
 *     never re-displays plaintext after save — admins rotate by re-entering.
 *
 * Phase: v2.3.0 sub-phase 13.A.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Gateways\TGB_Client;
use DFWC\Companion\Gateways\TGB_Projects_Cache;
use DFWC\Companion\Gateways\TGB_Token_Store;

final class Crypto_Settings_Page {

	public const OPTION_GROUP   = 'dfwc_companion_crypto';
	public const PAGE_SLUG      = 'dfwc-companion-crypto';
	public const TEST_ACTION    = 'dfwc_tgb_test_connection';
	public const DISCONNECT_ACTION = 'dfwc_tgb_disconnect';
	public const REFRESH_PROJECTS_ACTION = 'dfwc_tgb_refresh_projects';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::REFRESH_PROJECTS_ACTION, array( $this, 'handle_refresh_projects' ) );
	}

	public function register_settings(): void {
		// Re-register the global option under our group so saving from THIS
		// page goes through our sanitize callback. The existing
		// Admin\Settings_Page registers it under its own group; both groups
		// merge into the same option without stomping each other.
		register_setting(
			self::OPTION_GROUP,
			Config_Resolver::OPTION_KEY_GLOBAL,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Defaults::for_global(),
			)
		);

		add_settings_section(
			'dfwc_section_crypto_connection',
			__( 'TGB connection', 'dfwc-companion' ),
			array( $this, 'render_connection_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'tgb_environment',
			__( 'Environment', 'dfwc-companion' ),
			array( $this, 'render_environment_field' ),
			self::PAGE_SLUG,
			'dfwc_section_crypto_connection'
		);

		add_settings_field(
			'tgb_organization_id',
			__( 'Organization ID', 'dfwc-companion' ),
			array( $this, 'render_organization_id_field' ),
			self::PAGE_SLUG,
			'dfwc_section_crypto_connection'
		);

		add_settings_field(
			'tgb_api_key',
			__( 'API key', 'dfwc-companion' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			'dfwc_section_crypto_connection'
		);

		add_settings_field(
			'tgb_webhook_secret',
			__( 'Webhook secret', 'dfwc-companion' ),
			array( $this, 'render_webhook_secret_field' ),
			self::PAGE_SLUG,
			'dfwc_section_crypto_connection'
		);

		add_settings_field(
			'tgb_default_project_id',
			__( 'Default project ID', 'dfwc-companion' ),
			array( $this, 'render_default_project_field' ),
			self::PAGE_SLUG,
			'dfwc_section_crypto_connection'
		);

		add_settings_section(
			'dfwc_section_crypto_donor',
			__( 'Donor experience', 'dfwc-companion' ),
			static function () {
				echo '<p class="description">' . esc_html__(
					'When the master toggle is on AND credentials are configured, a "Donate Crypto" button appears below the cash form on every campaign that opted in via its meta box. Donors complete the flow inside The Giving Block widget; The Giving Block handles wallet UX and KYC.',
					'dfwc-companion'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'crypto_donations_enabled',
			__( 'Enable crypto donations', 'dfwc-companion' ),
			array( $this, 'render_master_toggle_field' ),
			self::PAGE_SLUG,
			'dfwc_section_crypto_donor'
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}
		include DFWC_COMPANION_PATH . 'templates/crypto-settings-page.php';
	}

	public function render_connection_intro(): void {
		echo '<p class="description">' . esc_html__(
			'Paste credentials from your The Giving Block dashboard. Sandbox lets you dry-run the integration before going live; production processes real donations.',
			'dfwc-companion'
		) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'Webhook URL', 'dfwc-companion' ) . ':</strong> ';
		echo '<code>' . esc_url( rest_url( 'dfwc-companion/v1/tgb-webhook' ) ) . '</code>';
		echo '<br>' . esc_html__( 'Paste this into your TGB dashboard\'s webhook configuration. Donations are recorded immediately as on-hold orders and flipped to completed when the webhook fires.', 'dfwc-companion' );
		echo '</p>';
	}

	public function render_environment_field(): void {
		$global  = Config_Resolver::get_global_settings();
		$current = isset( $global['tgb_environment'] ) ? (string) $global['tgb_environment'] : 'sandbox';
		$choices = array(
			'sandbox'    => __( 'Sandbox (recommended for first install)', 'dfwc-companion' ),
			'production' => __( 'Production (real donations)', 'dfwc-companion' ),
		);
		echo '<select name="' . esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ) . '[tgb_environment]">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Switching environments does not erase stored credentials, but you must use the credentials matching the active environment.', 'dfwc-companion' ) . '</p>';
	}

	public function render_organization_id_field(): void {
		$global  = Config_Resolver::get_global_settings();
		$current = isset( $global['tgb_organization_id'] ) ? (string) $global['tgb_organization_id'] : '';
		printf(
			'<input type="text" class="regular-text" name="%1$s[tgb_organization_id]" value="%2$s">',
			esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ),
			esc_attr( $current )
		);
		echo '<p class="description">' . esc_html__( 'Public-facing organization identifier from your TGB dashboard.', 'dfwc-companion' ) . '</p>';
	}

	public function render_api_key_field(): void {
		$store     = new TGB_Token_Store();
		$has_value = null !== $store->get_api_key();
		printf(
			'<input type="password" class="regular-text" name="%1$s[tgb_api_key]" value="" autocomplete="new-password" placeholder="%2$s">',
			esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ),
			$has_value ? esc_attr__( '••••••••  (re-enter to rotate)', 'dfwc-companion' ) : esc_attr__( 'Paste API key', 'dfwc-companion' )
		);
		echo '<p class="description">' . esc_html__( 'Stored encrypted (AES-256-CBC) at rest. Leave blank to keep the existing key. Re-enter the full key to rotate.', 'dfwc-companion' ) . '</p>';
	}

	public function render_webhook_secret_field(): void {
		$store     = new TGB_Token_Store();
		$has_value = null !== $store->get_webhook_secret();
		printf(
			'<input type="password" class="regular-text" name="%1$s[tgb_webhook_secret]" value="" autocomplete="new-password" placeholder="%2$s">',
			esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ),
			$has_value ? esc_attr__( '••••••••  (re-enter to rotate)', 'dfwc-companion' ) : esc_attr__( 'Paste webhook secret', 'dfwc-companion' )
		);
		echo '<p class="description">' . esc_html__( 'Used to verify the HMAC signature on incoming webhook payloads. Stored encrypted at rest.', 'dfwc-companion' ) . '</p>';
	}

	public function render_default_project_field(): void {
		$global   = Config_Resolver::get_global_settings();
		$current  = isset( $global['tgb_default_project_id'] ) ? (string) $global['tgb_default_project_id'] : '';
		$projects = TGB_Projects_Cache::get();

		// Match-detection so admins who entered a project_id pre-cache (or
		// after deleting a project in TGB) see a clear "custom" indicator
		// rather than a stale-looking blank dropdown.
		$saved_in_list = false;
		foreach ( $projects as $project ) {
			if ( (string) $project['id'] === $current ) {
				$saved_in_list = true;
				break;
			}
		}
		?>
		<select name="<?php echo esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ); ?>[tgb_default_project_id]" class="regular-text">
			<option value=""><?php esc_html_e( '— None (use TGB org default) —', 'dfwc-companion' ); ?></option>
			<?php foreach ( $projects as $project ) : ?>
				<option
					value="<?php echo esc_attr( $project['id'] ); ?>"
					<?php selected( $current, $project['id'] ); ?>
				>
					<?php echo esc_html( $project['name'] . ' (' . $project['id'] . ')' ); ?>
				</option>
			<?php endforeach; ?>
			<?php if ( '' !== $current && ! $saved_in_list ) : ?>
				<option value="<?php echo esc_attr( $current ); ?>" selected>
					<?php
					printf(
						/* translators: %s: project id not in cached list */
						esc_html__( 'Custom: %s', 'dfwc-companion' ),
						esc_html( $current )
					);
					?>
				</option>
			<?php endif; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Falls back to this project when a campaign has no per-campaign override. Leave blank to use TGB\'s organization-default.', 'dfwc-companion' ); ?>
			<?php if ( empty( $projects ) ) : ?>
				<br>
				<em><?php esc_html_e( 'Project list is empty. Save credentials, then click "Refresh project list" below.', 'dfwc-companion' ); ?></em>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * admin-post handler for "Refresh project list" — discards cached
	 * project list (both environments) and re-fetches the active env.
	 */
	public function handle_refresh_projects(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::REFRESH_PROJECTS_ACTION );

		$projects = TGB_Projects_Cache::refresh();

		set_transient(
			'dfwc_tgb_test_result',
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %d: number of projects fetched */
					_n(
						'Refreshed: %d TGB project loaded.',
						'Refreshed: %d TGB projects loaded.',
						count( $projects ),
						'dfwc-companion'
					),
					count( $projects )
				),
			),
			60
		);

		$this->redirect_back();
	}

	public function render_master_toggle_field(): void {
		$global  = Config_Resolver::get_global_settings();
		$current = ! empty( $global['crypto_donations_enabled'] );
		printf(
			'<label><input type="checkbox" name="%1$s[crypto_donations_enabled]" value="1"%2$s> %3$s</label>',
			esc_attr( Config_Resolver::OPTION_KEY_GLOBAL ),
			checked( $current, true, false ),
			esc_html__( 'Show the "Donate Crypto" button to donors', 'dfwc-companion' )
		);
	}

	/**
	 * Sanitize + persist. Public fields merge into the existing global
	 * option (so other settings pages' values aren't stomped). Secret
	 * fields encrypt-on-write via Token_Store and are NEVER stored in the
	 * global option.
	 *
	 * @param array<string,mixed>|null $raw
	 * @return array<string,mixed>
	 */
	public function sanitize( $raw ): array {
		$existing = (array) get_option( Config_Resolver::OPTION_KEY_GLOBAL, array() );
		$raw      = is_array( $raw ) ? $raw : array();

		// Public fields.
		$environment = isset( $raw['tgb_environment'] ) ? sanitize_key( (string) $raw['tgb_environment'] ) : 'sandbox';
		if ( ! in_array( $environment, array( TGB_Client::ENV_SANDBOX, TGB_Client::ENV_PRODUCTION ), true ) ) {
			$environment = TGB_Client::ENV_SANDBOX;
		}
		$org_id            = isset( $raw['tgb_organization_id'] ) ? sanitize_text_field( (string) $raw['tgb_organization_id'] ) : '';
		$default_project   = isset( $raw['tgb_default_project_id'] ) ? sanitize_text_field( (string) $raw['tgb_default_project_id'] ) : '';
		$master_toggle     = ! empty( $raw['crypto_donations_enabled'] );

		// Secrets — encrypt-on-write via Token_Store. Empty input means
		// "keep the existing value"; non-empty rotates.
		$store = new TGB_Token_Store();
		if ( isset( $raw['tgb_api_key'] ) && '' !== trim( (string) $raw['tgb_api_key'] ) ) {
			$store->set_api_key( sanitize_text_field( (string) $raw['tgb_api_key'] ) );
		}
		if ( isset( $raw['tgb_webhook_secret'] ) && '' !== trim( (string) $raw['tgb_webhook_secret'] ) ) {
			$store->set_webhook_secret( sanitize_text_field( (string) $raw['tgb_webhook_secret'] ) );
		}

		return array_merge(
			$existing,
			array(
				'tgb_environment'          => $environment,
				'tgb_organization_id'      => $org_id,
				'tgb_default_project_id'   => $default_project,
				'crypto_donations_enabled' => $master_toggle,
			)
		);
	}

	/**
	 * admin-post handler for the "Test connection" button. Calls TGB's
	 * /organization endpoint with currently-stored credentials and stores
	 * the result in a transient that the page reads on next render.
	 */
	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::TEST_ACTION );

		$store   = new TGB_Token_Store();
		$api_key = $store->get_api_key();

		if ( null === $api_key ) {
			set_transient(
				'dfwc_tgb_test_result',
				array(
					'ok' => false,
					'message' => __( 'No API key stored. Save credentials first, then test.', 'dfwc-companion' ),
				),
				60
			);
			$this->redirect_back();
		}

		$global      = Config_Resolver::get_global_settings();
		$environment = isset( $global['tgb_environment'] ) ? (string) $global['tgb_environment'] : TGB_Client::ENV_SANDBOX;

		$client = new TGB_Client( (string) $api_key, $environment );
		$result = $client->ping();

		if ( $result['ok'] ) {
			$org_name = isset( $result['data']['name'] ) ? (string) $result['data']['name'] : __( 'connected', 'dfwc-companion' );
			set_transient(
				'dfwc_tgb_test_result',
				array(
					'ok'      => true,
					'message' => sprintf(
						/* translators: 1: organization name from TGB */
						__( 'Connected as %s.', 'dfwc-companion' ),
						$org_name
					),
				),
				60
			);
		} else {
			set_transient(
				'dfwc_tgb_test_result',
				array(
					'ok' => false,
					'message' => $result['error'],
				),
				60
			);
		}

		$this->redirect_back();
	}

	/**
	 * admin-post handler for the "Disconnect" button. Wipes Token_Store and
	 * resets the master toggle off.
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::DISCONNECT_ACTION );

		( new TGB_Token_Store() )->clear();

		$global = (array) get_option( Config_Resolver::OPTION_KEY_GLOBAL, array() );
		$global['crypto_donations_enabled'] = false;
		update_option( Config_Resolver::OPTION_KEY_GLOBAL, $global, false );

		set_transient(
			'dfwc_tgb_test_result',
			array(
				'ok' => true,
				'message' => __( 'TGB credentials cleared. Re-enter to reconnect.', 'dfwc-companion' ),
			),
			60
		);

		$this->redirect_back();
	}

	private function redirect_back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
