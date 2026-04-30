<?php
/**
 * Unit tests for the augmentation gating decision.
 *
 * v2.2.0 flipped the historical "per-campaign opt-in" default to
 * "augment all". These tests lock both branches + the filter escape hatch.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Frontend\Context_Augmenter;
use PHPUnit\Framework\TestCase;

final class Context_Augmenter_Test extends TestCase {

	private const CAMPAIGN_ID = 42;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_augment_all_on_returns_true_for_any_campaign(): void {
		// Default install: augment_all_campaigns defaults to true.
		// Even a campaign with NO companion config should be augmented.
		$this->assertTrue( Context_Augmenter::should_augment( self::CAMPAIGN_ID, 'single' ) );
	}

	public function test_augment_all_off_falls_back_to_is_configured(): void {
		update_option(
			Config_Resolver::OPTION_KEY_GLOBAL,
			array( 'augment_all_campaigns' => false )
		);

		// No companion config saved → is_configured returns false →
		// fall back to vanilla parent form.
		$this->assertFalse( Context_Augmenter::should_augment( self::CAMPAIGN_ID, 'single' ) );
	}

	public function test_augment_all_off_with_configured_campaign_augments(): void {
		update_option(
			Config_Resolver::OPTION_KEY_GLOBAL,
			array( 'augment_all_campaigns' => false )
		);
		// Campaign has a template assigned — that's "configured".
		update_post_meta( self::CAMPAIGN_ID, '_dfwc_companion_template_id', 'school-sponsorship' );

		$this->assertTrue( Context_Augmenter::should_augment( self::CAMPAIGN_ID, 'single' ) );
	}

	public function test_filter_can_force_off_even_when_augment_all_is_on(): void {
		// Site has both companion-augmented donation campaigns AND
		// legacy parent-form campaigns; the filter is how admins keep
		// some campaigns vanilla without flipping the global default.
		add_filter(
			'dfwc_should_augment_parent_form',
			static function ( $should, $campaign_id ) {
				return self::CAMPAIGN_ID === $campaign_id ? false : $should;
			},
			10,
			2
		);

		$this->assertFalse( Context_Augmenter::should_augment( self::CAMPAIGN_ID, 'single' ) );
		// Other campaigns still augment.
		$this->assertTrue( Context_Augmenter::should_augment( 99, 'single' ) );
	}

	public function test_filter_can_force_on_even_when_augment_all_is_off_and_campaign_unconfigured(): void {
		update_option(
			Config_Resolver::OPTION_KEY_GLOBAL,
			array( 'augment_all_campaigns' => false )
		);
		add_filter(
			'dfwc_should_augment_parent_form',
			static function () {
				return true;
			}
		);

		$this->assertTrue( Context_Augmenter::should_augment( self::CAMPAIGN_ID, 'single' ) );
	}
}
