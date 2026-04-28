<?php
/**
 * Unit tests for Config\Currency_Preset_Resolver.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class Currency_Preset_Resolver_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	private function build_block(): array {
		$block                       = Defaults::interval_block( true, 'Donate {amount}/month' );
		$block['currency_overrides'] = array(
			'GBP' => array(
				'presets' => array(
					array( 'amount' => 20.0 ),
					array( 'amount' => 40.0 ),
					array( 'amount' => 80.0 ),
				),
				'min'     => 5.0,
				'max'     => 8000.0,
			),
			'EUR' => array(
				'presets' => array(
					array( 'amount' => 22.0 ),
					array( 'amount' => 45.0 ),
					array( 'amount' => 90.0 ),
				),
			),
		);
		return $block;
	}

	public function test_base_currency_returns_block_unchanged_with_resolved_currency_marker(): void {
		add_filter( 'dfwc_companion_active_currency', static function () {
			return 'USD';
		} );

		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block );

		$this->assertSame( 'USD', $resolved['_resolved_currency'] );
		$this->assertSame( 25.0, $resolved['presets'][0]['amount'] );
		$this->assertSame( 50.0, $resolved['presets'][1]['amount'] );
		$this->assertSame( 100.0, $resolved['presets'][2]['amount'] );
		$this->assertSame( 5.0, $resolved['min'] );
		$this->assertSame( 10000.0, $resolved['max'] );
	}

	public function test_overridden_currency_substitutes_amounts_only(): void {
		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		$this->assertSame( 'GBP', $resolved['_resolved_currency'] );
		$this->assertSame( 20.0, $resolved['presets'][0]['amount'] );
		$this->assertSame( 40.0, $resolved['presets'][1]['amount'] );
		$this->assertSame( 80.0, $resolved['presets'][2]['amount'] );

		// Min / max overridden.
		$this->assertSame( 5.0, $resolved['min'] );
		$this->assertSame( 8000.0, $resolved['max'] );

		// Labels / impact_label / is_featured / sort_order from base preserved.
		$this->assertSame( '', $resolved['presets'][0]['label'] );
		$this->assertSame( '', $resolved['presets'][0]['impact_label'] );
		$this->assertSame( false, $resolved['presets'][0]['is_featured'] );
		$this->assertSame( 10, $resolved['presets'][0]['sort_order'] );
	}

	public function test_partial_override_uses_base_min_max(): void {
		// EUR override sets only presets, not min/max.
		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block, 'EUR' );

		$this->assertSame( 'EUR', $resolved['_resolved_currency'] );
		$this->assertSame( 22.0, $resolved['presets'][0]['amount'] );
		$this->assertSame( 45.0, $resolved['presets'][1]['amount'] );

		// Base min/max preserved when override is silent.
		$this->assertSame( 5.0, $resolved['min'] );
		$this->assertSame( 10000.0, $resolved['max'] );
	}

	public function test_currency_without_override_returns_base_unchanged(): void {
		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block, 'CAD' );

		$this->assertSame( 'CAD', $resolved['_resolved_currency'] );
		$this->assertSame( 25.0, $resolved['presets'][0]['amount'] );
		$this->assertSame( 50.0, $resolved['presets'][1]['amount'] );
		$this->assertSame( 100.0, $resolved['presets'][2]['amount'] );
	}

	public function test_block_without_currency_overrides_key_returns_base_unchanged(): void {
		$block    = Defaults::interval_block( true, 'Donate {amount}' );
		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		$this->assertSame( 'GBP', $resolved['_resolved_currency'] );
		$this->assertSame( 25.0, $resolved['presets'][0]['amount'] );
	}

	public function test_override_preserves_base_amount_in_underscore_marker(): void {
		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		$this->assertSame( 25.0, $resolved['presets'][0]['_base_amount'] );
		$this->assertSame( 50.0, $resolved['presets'][1]['_base_amount'] );
		$this->assertSame( 100.0, $resolved['presets'][2]['_base_amount'] );
	}

	public function test_override_preset_with_zero_amount_falls_back_to_base(): void {
		$block                                          = $this->build_block();
		$block['currency_overrides']['GBP']['presets'][0]['amount'] = 0;

		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		// Index 0 fell back to base amount.
		$this->assertSame( 25.0, $resolved['presets'][0]['amount'] );
		// Index 1 + 2 still overridden.
		$this->assertSame( 40.0, $resolved['presets'][1]['amount'] );
		$this->assertSame( 80.0, $resolved['presets'][2]['amount'] );
	}

	public function test_override_with_more_presets_than_base_drops_extras(): void {
		$block                                       = $this->build_block();
		$block['currency_overrides']['GBP']['presets'][] = array( 'amount' => 999.0 );
		$block['currency_overrides']['GBP']['presets'][] = array( 'amount' => 1234.0 );

		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		// Resolver caps at base preset count (3).
		$this->assertCount( 3, $resolved['presets'] );
	}

	public function test_max_below_min_clamps_to_min(): void {
		$block                                  = $this->build_block();
		$block['currency_overrides']['GBP']['min'] = 100.0;
		$block['currency_overrides']['GBP']['max'] = 50.0;

		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		$this->assertSame( 100.0, $resolved['min'] );
		$this->assertSame( 100.0, $resolved['max'] );
	}

	public function test_active_currency_filter_overrides_default(): void {
		add_filter( 'dfwc_companion_active_currency', static function () {
			return 'EUR';
		} );

		$this->assertSame( 'EUR', Currency_Preset_Resolver::active_currency() );

		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block );
		$this->assertSame( 'EUR', $resolved['_resolved_currency'] );
		$this->assertSame( 22.0, $resolved['presets'][0]['amount'] );
	}

	public function test_resolved_currency_block_filter_runs(): void {
		add_filter( 'dfwc_companion_resolved_currency_block', static function ( $resolved, $base, $currency ) {
			$resolved['_filter_ran'] = true;
			$resolved['_filter_currency'] = $currency;
			return $resolved;
		}, 10, 4 );

		$block    = $this->build_block();
		$resolved = Currency_Preset_Resolver::resolve( $block, 'GBP' );

		$this->assertTrue( $resolved['_filter_ran'] );
		$this->assertSame( 'GBP', $resolved['_filter_currency'] );
	}

	public function test_supported_currencies_returns_at_least_base(): void {
		$out = Currency_Preset_Resolver::supported_currencies();
		$this->assertContains( Currency_Preset_Resolver::base_currency(), $out );
	}

	public function test_supported_currencies_filter_overrides(): void {
		add_filter( 'dfwc_companion_supported_currencies', static function () {
			return array( 'USD', 'GBP', 'EUR' );
		} );

		$out = Currency_Preset_Resolver::supported_currencies();
		$this->assertSame( array( 'USD', 'GBP', 'EUR' ), $out );
	}

	public function test_supported_currencies_filter_normalizes_codes(): void {
		add_filter( 'dfwc_companion_supported_currencies', static function () {
			return array( 'usd', '  gbp  ', 'EUR', 'eur', '' );
		} );

		$out = Currency_Preset_Resolver::supported_currencies();
		$this->assertSame( array( 'USD', 'GBP', 'EUR' ), $out );
	}

	public function test_extra_currencies_excludes_base(): void {
		add_filter( 'dfwc_companion_supported_currencies', static function () {
			return array( 'USD', 'GBP', 'EUR' );
		} );

		$extra = Currency_Preset_Resolver::extra_currencies();
		$this->assertSame( array( 'GBP', 'EUR' ), $extra );
	}

	public function test_multi_currency_active_false_when_only_base(): void {
		add_filter( 'dfwc_companion_supported_currencies', static function () {
			return array( 'USD' );
		} );

		$this->assertFalse( Currency_Preset_Resolver::multi_currency_active() );
	}

	public function test_multi_currency_active_true_when_extras_present(): void {
		add_filter( 'dfwc_companion_supported_currencies', static function () {
			return array( 'USD', 'GBP' );
		} );

		$this->assertTrue( Currency_Preset_Resolver::multi_currency_active() );
	}
}
