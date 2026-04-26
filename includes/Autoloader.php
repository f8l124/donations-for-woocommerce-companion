<?php
/**
 * PSR-4-ish autoloader for the DFWC\Companion namespace.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'DFWC\\Companion\\';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = DFWC_COMPANION_PATH . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
}
