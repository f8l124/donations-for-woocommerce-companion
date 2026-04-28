<?php
/**
 * Preview_Controller — wires the live preview pane onto admin screens.
 *
 * The pane appears on:
 * - The wc-donation campaign edit screen (alongside Meta_Box)
 * - The Templates page (edit mode)
 * - The Settings page (shows defaults a new campaign would inherit)
 *
 * Pane is just an iframe + toolbar (viewport / engine / currency /
 * language selectors) plus a status label. Live updates flow via the
 * Preview REST endpoint.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\I18n\WPML_Strings;

final class Preview_Controller {

	public const HANDLE_CSS = 'dfwc-admin-preview';
	public const HANDLE_JS  = 'dfwc-admin-preview';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 10 );

		// Render the pane after our meta box on the campaign edit screen.
		add_action( 'edit_form_after_editor', array( $this, 'maybe_render_pane_on_campaign_edit' ) );

		// Render after the form on Templates edit + Settings pages.
		add_action( 'dfwc_companion_after_templates_edit_form', array( $this, 'render_pane_for_templates_edit' ) );
		add_action( 'dfwc_companion_after_settings_form', array( $this, 'render_pane_for_settings' ) );
	}

	public function register_assets(): void {
		wp_register_style(
			self::HANDLE_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-admin-preview.css',
			array(),
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-admin-preview.js',
			array(),
			DFWC_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			self::HANDLE_JS,
			'dfwcAdminPreview',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'dfwc-companion/v1/preview' ) ),
				'overlayCssUrl' => esc_url_raw( DFWC_COMPANION_URL . 'assets/css/dfwc-overlay.css' ),
				'overlayJsUrl'  => esc_url_raw( DFWC_COMPANION_URL . 'assets/js/dfwc-overlay.js' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'languages'     => $this->wpml_languages(),
				'i18n'          => array(
					'loading'      => __( 'Updating preview…', 'dfwc-companion' ),
					'upToDate'     => __( 'Up to date', 'dfwc-companion' ),
					'error'        => __( 'Preview failed to update. Save the form and try again.', 'dfwc-companion' ),
					'noChanges'    => __( 'Make a change to see the preview update.', 'dfwc-companion' ),
				),
			)
		);
	}

	public function maybe_enqueue( string $hook ): void {
		if ( $this->is_relevant_screen( $hook ) ) {
			wp_enqueue_style( self::HANDLE_CSS );
			wp_enqueue_script( self::HANDLE_JS );
		}
	}

	public function maybe_render_pane_on_campaign_edit( \WP_Post $post ): void {
		if ( 'wc-donation' !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			return;
		}

		$context = array(
			'source'      => 'campaign',
			'campaign_id' => (int) $post->ID,
		);
		include DFWC_COMPANION_PATH . 'templates/admin-preview-pane.php';
	}

	public function render_pane_for_templates_edit(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			return;
		}
		$context = array( 'source' => 'template' );
		include DFWC_COMPANION_PATH . 'templates/admin-preview-pane.php';
	}

	public function render_pane_for_settings(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			return;
		}
		$context = array( 'source' => 'settings' );
		include DFWC_COMPANION_PATH . 'templates/admin-preview-pane.php';
	}

	/**
	 * @param string $hook WP admin hook (e.g. post.php, woocommerce_page_dfwc-companion-templates).
	 */
	private function is_relevant_screen( string $hook ): bool {
		// Campaign edit screens.
		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'wc-donation' === $screen->post_type ) {
				return true;
			}
		}

		// Companion sub-pages — Templates + Settings.
		if (
			0 === strpos( $hook, 'donations-companion_page_dfwc-companion-' )
			|| 0 === strpos( $hook, 'woocommerce_page_dfwc-companion' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Build the WPML active-languages list for the simulate-language toggle.
	 * Returns empty array on monolingual sites — JS hides the toggle when empty.
	 *
	 * @return array<int,array{code:string,label:string}>
	 */
	private function wpml_languages(): array {
		if ( ! WPML_Strings::wpml_active() ) {
			return array();
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook
		$langs = apply_filters( 'wpml_active_languages', array() );
		if ( ! is_array( $langs ) || empty( $langs ) ) {
			return array();
		}
		$out = array();
		foreach ( $langs as $code => $info ) {
			$out[] = array(
				'code'  => is_string( $code ) ? $code : (string) ( $info['code'] ?? '' ),
				'label' => is_array( $info ) ? (string) ( $info['native_name'] ?? $info['display_name'] ?? $code ) : (string) $info,
			);
		}
		return $out;
	}
}
