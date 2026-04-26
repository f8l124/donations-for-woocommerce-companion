<?php
/**
 * Plugin Name:          Donations for WooCommerce Companion
 * Plugin URI:           https://github.com/f8l124/donations-for-woocommerce-companion
 * Description:          Adds an interval-first donation form (one-time / monthly / annually) on top of the Donation for WooCommerce plugin, without modifying it.
 * Version:              0.3.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * WC requires at least: 5.0
 * WC tested up to:      10.2
 * Requires Plugins:     woocommerce, donation-for-woocommerce
 * Author:               David Stells
 * Author URI:           https://github.com/f8l124
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          dfwc-companion
 * Domain Path:          /languages
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// PHP version pre-flight before loading namespaced code so PHP 7.0/7.3 hosts get a
// clean admin notice instead of a fatal error on parsing typed properties / arrow fns.
if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Donations for WooCommerce Companion requires PHP 7.4 or higher. Please upgrade PHP to use this plugin.',
					'dfwc-companion'
				)
			);
		}
	);
	return;
}

define( 'DFWC_COMPANION_VERSION',            '0.3.0' );
define( 'DFWC_COMPANION_FILE',               __FILE__ );
define( 'DFWC_COMPANION_PATH',               plugin_dir_path( __FILE__ ) );
define( 'DFWC_COMPANION_URL',                plugin_dir_url( __FILE__ ) );
define( 'DFWC_COMPANION_BASENAME',           plugin_basename( __FILE__ ) );
define( 'DFWC_COMPANION_MIN_PARENT_VERSION', '3.9.8' );
define( 'DFWC_COMPANION_MIN_PHP',            '7.4' );

require_once DFWC_COMPANION_PATH . 'includes/Autoloader.php';
\DFWC\Companion\Autoloader::register();

add_action( 'plugins_loaded', [ \DFWC\Companion\Plugin::class, 'instance' ], 20 );
