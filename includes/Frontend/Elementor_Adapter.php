<?php
/**
 * Conditional Elementor integration. Registers the recurring-donation widget
 * with Elementor's widget manager when (and only when) Elementor is active.
 *
 * The widget class definition is guarded inside Elementor_Widget.php so the
 * autoloader can safely load that file even when \Elementor\Widget_Base is
 * absent — but we never reference Elementor_Widget except from inside the
 * `elementor/widgets/register` callback, which only fires when Elementor is
 * loaded.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Elementor_Adapter {

	public function __construct() {
		// Bail early if Elementor will never load on this request.
		if ( ! did_action( 'elementor/loaded' ) && ! defined( 'ELEMENTOR_VERSION' ) ) {
			// Elementor may still load after us; defer to its own register hook.
		}
		add_action( 'elementor/widgets/register', [ $this, 'register' ] );
	}

	/**
	 * Elementor passes the Widgets_Manager instance.
	 *
	 * @param object $widgets_manager Elementor\Widgets_Manager
	 */
	public function register( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		// Loading the widget file is gated on Elementor being present, so the
		// conditional class definition inside it always succeeds when reached.
		require_once DFWC_COMPANION_PATH . 'includes/Frontend/Elementor_Widget.php';

		if ( ! class_exists( __NAMESPACE__ . '\\Elementor_Widget' ) ) {
			return;
		}

		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new Elementor_Widget() );
		} elseif ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
			// Elementor < 3.5 fallback.
			$widgets_manager->register_widget_type( new Elementor_Widget() );
		}
	}
}
