<?php
/**
 * Engine_Interval_Map — translate companion interval keys (weekly,
 * quarterly, semiannual, custom, etc.) into the cadence shape each
 * subscription engine expects on the donate AJAX submit.
 *
 * Both supported engines (WCS, WPS SFW) accept week/month/year period
 * with integer multipliers, so all our intervals work with either.
 * The map is config, not code — pure static lookup, no engine-specific
 * branching needed beyond the period/multiplier combo.
 *
 * Used by:
 * - `Frontend\Renderer::build_form_config` to ship cadence to overlay JS
 * - `Frontend\Submit_Guard` to validate posted cadence against config
 * - `Admin\Diagnostics_Page` to flag intervals enabled with no engine
 *
 * Filter: `dfwc_companion_engine_supported_intervals` lets power users
 * narrow the allow-list per-engine when a particular install can't
 * actually serve a cadence (rare; included for completeness).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Engine_Detector;

final class Engine_Interval_Map {

	/**
	 * Resolve cadence (period + interval) for a given companion interval
	 * key. Returns `null` for `one_time` (not recurring) and for unknown
	 * keys.
	 *
	 * @param string              $interval Companion interval key.
	 * @param array<string,mixed> $block    Config block for the interval.
	 *                                      Required for `custom` so we can
	 *                                      read `custom_period`/`custom_interval`.
	 * @return array{period:string,interval:int}|null
	 */
	public static function for_interval( string $interval, array $block = array() ): ?array {
		switch ( $interval ) {
			case 'one_time':
				return null;
			case 'monthly':
				return array(
					'period'   => 'month',
					'interval' => 1,
				);
			case 'annual':
				return array(
					'period'   => 'year',
					'interval' => 1,
				);
			case 'weekly':
				return array(
					'period'   => 'week',
					'interval' => 1,
				);
			case 'quarterly':
				return array(
					'period'   => 'month',
					'interval' => 3,
				);
			case 'semiannual':
				return array(
					'period'   => 'month',
					'interval' => 6,
				);
			case 'custom':
				return array(
					'period'   => self::sanitize_period( (string) ( $block['custom_period'] ?? 'month' ) ),
					'interval' => self::sanitize_multiplier( (int) ( $block['custom_interval'] ?? 1 ) ),
				);
			default:
				return null;
		}
	}

	/**
	 * Allow-listed cadence periods. Used by sanitizers + the admin UI's
	 * cadence dropdown. Both subscription engines accept all four; we
	 * simply pass through.
	 *
	 * @return array<int,string>
	 */
	public static function periods(): array {
		return array( 'day', 'week', 'month', 'year' );
	}

	/**
	 * Coerce a raw period value to the allow-list, falling back to 'month'
	 * for anything unrecognized.
	 */
	public static function sanitize_period( string $period ): string {
		$period = strtolower( trim( $period ) );
		return in_array( $period, self::periods(), true ) ? $period : 'month';
	}

	/**
	 * Coerce a custom-interval multiplier to a positive integer in the
	 * allowed range. Engines accept arbitrary integers, but we cap at 99 to
	 * defend against typos / abuse (every 999 months is meaningless donor
	 * UX).
	 */
	public static function sanitize_multiplier( int $multiplier ): int {
		if ( $multiplier < 1 ) {
			return 1;
		}
		if ( $multiplier > 99 ) {
			return 99;
		}
		return $multiplier;
	}

	/**
	 * Intervals the active engine can actually serve. `none` engine means
	 * one_time only — neither WCS nor WPS SFW is loaded so recurring is
	 * out. Both engines accept all four periods + integer multipliers, so
	 * the allow-list is the same regardless of which one is active.
	 *
	 * @param string|null $engine Engine slug; null = autodetect.
	 * @return array<int,string>
	 */
	public static function supported_by_engine( ?string $engine = null ): array {
		$engine = null !== $engine ? $engine : Engine_Detector::detect();

		if ( Engine_Detector::ENGINE_NONE === $engine ) {
			return array( 'one_time' );
		}

		$intervals = array(
			'one_time',
			'monthly',
			'annual',
			'weekly',
			'quarterly',
			'semiannual',
			'custom',
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed hook
		$filtered = apply_filters( 'dfwc_companion_engine_supported_intervals', $intervals, $engine );

		if ( ! is_array( $filtered ) || empty( $filtered ) ) {
			return array( 'one_time' );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $filtered ) ) ) );
	}

	/**
	 * Convenience: is `$interval` supported by `$engine` (or the active
	 * engine when null)?
	 */
	public static function is_supported( string $interval, ?string $engine = null ): bool {
		return in_array( $interval, self::supported_by_engine( $engine ), true );
	}

	/**
	 * Build the AJAX cadence POST keys for a given interval. Returns BOTH
	 * WCS and WPS SFW key sets (donor form ships both regardless of which
	 * engine is active — parent reads only the relevant set based on
	 * `class_exists()`. Master plan deviation D2.)
	 *
	 * Returns empty array for `one_time` (no recurring fields needed).
	 *
	 * @param string              $interval
	 * @param array<string,mixed> $block
	 * @return array<string,string>
	 */
	public static function ajax_keys( string $interval, array $block = array() ): array {
		$cadence = self::for_interval( $interval, $block );
		if ( null === $cadence ) {
			return array();
		}

		return array(
			// WCS keys.
			'is_recurring'                          => 'yes',
			'new_period'                            => (string) $cadence['period'],
			'new_interval'                          => (string) (int) $cadence['interval'],
			'new_length'                            => '0',
			// WPS SFW keys.
			'wps_sfw_subscription_number'           => (string) (int) $cadence['interval'],
			'wps_sfw_subscription_interval'         => (string) $cadence['period'],
			'wps_sfw_subscription_expiry_number'    => '',
			'wps_sfw_subscription_expiry_interval'  => '',
		);
	}
}
