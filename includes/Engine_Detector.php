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
	 * Reset the static cache. Intended for test fixtures that toggle engines
	 * mid-request; not used in production.
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}
}
