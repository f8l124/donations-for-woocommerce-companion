<?php
/**
 * TGB_Payment_Gateway_Class — the actual WC_Payment_Gateway subclass.
 *
 * Lives in its own file so the class extends WC_Payment_Gateway (a WC
 * runtime class) without forcing the rest of the Gateways namespace to
 * depend on WC at autoload time. The companion's bootstrap already
 * gates on WC being active; this class is only autoloaded when the
 * `woocommerce_payment_gateways` filter fires (post-WC-init).
 *
 * Configuration: hard-coded `enabled => no`. Donors never see this in
 * the checkout payment-method picker. Crypto orders are created
 * programmatically by Crypto_Pending_Order_REST_Controller with
 * `payment_method = 'dfwc_crypto'` set explicitly on the order.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WC_Payment_Gateway' ) ) {
	return;
}

final class TGB_Payment_Gateway_Class extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = TGB_Payment_Gateway::METHOD_ID;
		$this->method_title       = __( 'Crypto via The Giving Block', 'dfwc-companion' );
		$this->method_description = __( 'Used internally by Donations for WooCommerce Companion to record crypto donations submitted through The Giving Block widget. Not selectable by donors at checkout — donations are created programmatically when the donor commits in the widget.', 'dfwc-companion' );
		$this->title              = __( 'Crypto donation (TGB)', 'dfwc-companion' );
		$this->has_fields         = false;
		$this->enabled            = 'no';
	}

	/**
	 * Hide from the donor-facing checkout payment-method selector.
	 * Defense in depth: even if an admin enables the gateway in WC
	 * settings, this method blocks it from rendering on the checkout
	 * page. Programmatic order creation via assign-on-create still works.
	 */
	public function is_available(): bool {
		return false;
	}
}
