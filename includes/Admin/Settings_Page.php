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
		$preserve = ! empty( $raw['preserve_data_on_uninstall'] );

		return array_merge(
			$existing,
			array(
				'version'                    => 1,
				'default_template_id'        => $default_template_id,
				'preserve_data_on_uninstall' => $preserve,
			)
		);
	}
}
