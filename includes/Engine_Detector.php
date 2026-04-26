<?php
/**
 * Recurring engine detection.
 *
 * Returns one of three values: 'wcs' (WooCommerce Subscriptions), 'wps_sfw'
 * (Subscriptions For WooCommerce by WPS), or 'none'. The parent plugin's own
 * branch logic prefers WCS when both are present (class-wcdonation.php:171),
 * so we mirror that ordering.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion;

defined( 'ABSPATH' ) || exit;

final class Engine_Detector {

	public const ENGINE_WCS  = 'wcs';
	public const ENGINE_WPS  = 'wps_sfw';
	public const ENGINE_NONE = 'none';

	/**
	 * Cached detection result. Reset to null forces re-detection (used by tests).
	 */
	private static ?string $cache = null;

	public static function detect(): string {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		if ( class_exists( 'WC_Subscriptions' ) ) {
			self::$cache = self::ENGINE_WCS;
		} elseif ( class_exists( 'Subscriptions_For_Woocommerce' ) ) {
			self::$cache = self::ENGINE_WPS;
		} else {
			self::$cache = self::ENGINE_NONE;
		}

		return self::$cache;
	}

	public static function supports_recurring(): bool {
		return self::ENGINE_NONE !== self::detect();
	}

	/**
	 * Both supported engines accept open-ended subscription length:
	 * - WCS via `new_length=0`
	 * - WPS SFW via empty `wps_sfw_subscription_expiry_number`
	 *
	 * Verified at parent's class-wcdonationsubscriptionfree.php:78-110.
	 */
	public static function supports_open_ended_length(): bool {
		return true;
	}

	/**
	 * Human-readable engine name for admin notices and debug output.
	 */
	public static function engine_label( ?string $engine = null ): string {
		$engine = $engine ?? self::detect();
		switch ( $engine ) {
			case self::ENGINE_WCS:
				return __( 'WooCommerce Subscriptions', 'dfwc-companion' );
			case self::ENGINE_WPS:
				return __( 'Subscriptions For WooCommerce', 'dfwc-companion' );
			default:
				return __( 'no recurring engine', 'dfwc-companion' );
		}
	}

	/**
	 * URL of the recommended free engine to install when none is active.
	 * Returns the wordpress.org plugin install page so admins can one-click.
	 */
	public static function recommended_install_url(): string {
		return self_admin_url( 'plugin-install.php?s=Subscriptions+For+WooCommerce&tab=search&type=term' );
	}

	/**
	 * Reset the static cache. Intended for test fixtures that toggle engines
	 * mid-request; not used in production.
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}
}
