<?php
/**
 * TGB_Payment_Gateway — registers a custom `dfwc_crypto` WC payment method.
 *
 * The gateway is intentionally **disabled in checkout** (`enabled = no`)
 * so it never appears in the donor's payment-method dropdown. Its only
 * purpose is to give the orders that companion creates programmatically
 * for crypto donations a semantically correct `payment_method` slug — so
 * WC reports can filter by "crypto via TGB", QBO sync (sibling plugin)
 * can route to the right account, and admins see a meaningful method
 * label in the order list table.
 *
 * Registration via the standard `woocommerce_payment_gateways` filter so
 * WC discovers it on init.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

final class TGB_Payment_Gateway {

	public const METHOD_ID = 'dfwc_crypto';

	public function __construct() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
	}

	/**
	 * @param array<int,string> $gateways List of payment gateway class names.
	 * @return array<int,string>
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = TGB_Payment_Gateway_Class::class;
		return $gateways;
	}
}
