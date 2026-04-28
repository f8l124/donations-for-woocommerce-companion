<?php
/**
 * Unit tests for Config\Engine_Interval_Map.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Engine_Interval_Map;
use PHPUnit\Framework\TestCase;

final class Engine_Interval_Map_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_one_time_returns_null(): void {
		$this->assertNull( Engine_Interval_Map::for_interval( 'one_time' ) );
	}

	public function test_monthly_returns_month_one(): void {
		$cadence = Engine_Interval_Map::for_interval( 'monthly' );
		$this->assertSame( 'month', $cadence['period'] );
		$this->assertSame( 1, $cadence['interval'] );
	}

	public function test_annual_returns_year_one(): void {
		$cadence = Engine_Interval_Map::for_interval( 'annual' );
		$this->assertSame( 'year', $cadence['period'] );
		$this->assertSame( 1, $cadence['interval'] );
	}

	public function test_weekly_returns_week_one(): void {
		$cadence = Engine_Interval_Map::for_interval( 'weekly' );
		$this->assertSame( 'week', $cadence['period'] );
		$this->assertSame( 1, $cadence['interval'] );
	}

	public function test_quarterly_returns_month_three(): void {
		$cadence = Engine_Interval_Map::for_interval( 'quarterly' );
		$this->assertSame( 'month', $cadence['period'] );
		$this->assertSame( 3, $cadence['interval'] );
	}

	public function test_semiannual_returns_month_six(): void {
		$cadence = Engine_Interval_Map::for_interval( 'semiannual' );
		$this->assertSame( 'month', $cadence['period'] );
		$this->assertSame( 6, $cadence['interval'] );
	}

	public function test_custom_reads_block_period_and_interval(): void {
		$cadence = Engine_Interval_Map::for_interval( 'custom', array(
			'custom_period'   => 'week',
			'custom_interval' => 6,
		) );
		$this->assertSame( 'week', $cadence['period'] );
		$this->assertSame( 6, $cadence['interval'] );
	}

	public function test_custom_with_invalid_period_falls_back_to_month(): void {
		$cadence = Engine_Interval_Map::for_interval( 'custom', array(
			'custom_period'   => 'fortnight',
			'custom_interval' => 1,
		) );
		$this->assertSame( 'month', $cadence['period'] );
	}

	public function test_custom_clamps_negative_multiplier_to_one(): void {
		$cadence = Engine_Interval_Map::for_interval( 'custom', array(
			'custom_period'   => 'month',
			'custom_interval' => -5,
		) );
		$this->assertSame( 1, $cadence['interval'] );
	}

	public function test_custom_clamps_high_multiplier_to_99(): void {
		$cadence = Engine_Interval_Map::for_interval( 'custom', array(
			'custom_period'   => 'month',
			'custom_interval' => 1000,
		) );
		$this->assertSame( 99, $cadence['interval'] );
	}

	public function test_unknown_interval_returns_null(): void {
		$this->assertNull( Engine_Interval_Map::for_interval( 'fortnightly' ) );
	}

	public function test_periods_returns_canonical_set(): void {
		$this->assertSame( array( 'day', 'week', 'month', 'year' ), Engine_Interval_Map::periods() );
	}

	public function test_sanitize_period_allow_lists(): void {
		$this->assertSame( 'week', Engine_Interval_Map::sanitize_period( 'week' ) );
		$this->assertSame( 'month', Engine_Interval_Map::sanitize_period( 'fortnight' ) );
		$this->assertSame( 'year', Engine_Interval_Map::sanitize_period( ' YEAR ' ) );
	}

	public function test_sanitize_multiplier_clamps_range(): void {
		$this->assertSame( 1, Engine_Interval_Map::sanitize_multiplier( 0 ) );
		$this->assertSame( 1, Engine_Interval_Map::sanitize_multiplier( -3 ) );
		$this->assertSame( 50, Engine_Interval_Map::sanitize_multiplier( 50 ) );
		$this->assertSame( 99, Engine_Interval_Map::sanitize_multiplier( 1000 ) );
	}

	public function test_supported_by_engine_none_returns_only_one_time(): void {
		$this->assertSame( array( 'one_time' ), Engine_Interval_Map::supported_by_engine( 'none' ) );
	}

	public function test_supported_by_engine_wcs_returns_full_set(): void {
		$out = Engine_Interval_Map::supported_by_engine( 'wcs' );
		$this->assertContains( 'one_time', $out );
		$this->assertContains( 'monthly', $out );
		$this->assertContains( 'annual', $out );
		$this->assertContains( 'weekly', $out );
		$this->assertContains( 'quarterly', $out );
		$this->assertContains( 'semiannual', $out );
		$this->assertContains( 'custom', $out );
	}

	public function test_supported_filter_can_narrow_set(): void {
		add_filter( 'dfwc_companion_engine_supported_intervals', static function () {
			return array( 'one_time', 'monthly' );
		} );

		$out = Engine_Interval_Map::supported_by_engine( 'wps_sfw' );
		$this->assertSame( array( 'one_time', 'monthly' ), $out );
	}

	public function test_is_supported_respects_engine(): void {
		$this->assertTrue( Engine_Interval_Map::is_supported( 'monthly', 'wcs' ) );
		$this->assertFalse( Engine_Interval_Map::is_supported( 'weekly', 'none' ) );
	}

	public function test_ajax_keys_one_time_returns_empty(): void {
		$this->assertSame( array(), Engine_Interval_Map::ajax_keys( 'one_time' ) );
	}

	public function test_ajax_keys_monthly_emits_both_engine_key_sets(): void {
		$keys = Engine_Interval_Map::ajax_keys( 'monthly' );

		$this->assertSame( 'yes', $keys['is_recurring'] );
		$this->assertSame( 'month', $keys['new_period'] );
		$this->assertSame( '1', $keys['new_interval'] );
		$this->assertSame( '0', $keys['new_length'] );

		$this->assertSame( '1', $keys['wps_sfw_subscription_number'] );
		$this->assertSame( 'month', $keys['wps_sfw_subscription_interval'] );
		$this->assertSame( '', $keys['wps_sfw_subscription_expiry_number'] );
		$this->assertSame( '', $keys['wps_sfw_subscription_expiry_interval'] );
	}

	public function test_ajax_keys_quarterly_uses_three_multiplier(): void {
		$keys = Engine_Interval_Map::ajax_keys( 'quarterly' );
		$this->assertSame( 'month', $keys['new_period'] );
		$this->assertSame( '3', $keys['new_interval'] );
		$this->assertSame( '3', $keys['wps_sfw_subscription_number'] );
		$this->assertSame( 'month', $keys['wps_sfw_subscription_interval'] );
	}

	public function test_ajax_keys_semiannual_uses_six_multiplier(): void {
		$keys = Engine_Interval_Map::ajax_keys( 'semiannual' );
		$this->assertSame( 'month', $keys['new_period'] );
		$this->assertSame( '6', $keys['new_interval'] );
	}

	public function test_ajax_keys_custom_reads_block(): void {
		$keys = Engine_Interval_Map::ajax_keys( 'custom', array(
			'custom_period'   => 'week',
			'custom_interval' => 2,
		) );
		$this->assertSame( 'week', $keys['new_period'] );
		$this->assertSame( '2', $keys['new_interval'] );
		$this->assertSame( '2', $keys['wps_sfw_subscription_number'] );
		$this->assertSame( 'week', $keys['wps_sfw_subscription_interval'] );
	}
}
