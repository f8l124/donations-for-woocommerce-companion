<?php
/**
 * Unit tests for Frontend\Crypto_Donation_Renderer.
 *
 * Verifies the gating decision (should_render) under each combination of
 * global toggle + Token_Store state + per-campaign override + render
 * context, and the public attribute payload (get_data_attributes).
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Frontend\Crypto_Donation_Renderer;
use DFWC\Companion\Gateways\TGB_Token_Store;
use PHPUnit\Framework\TestCase;

final class Crypto_Donation_Renderer_Test extends TestCase {

	private const CAMPAIGN_ID = 4242;

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	private function configure_credentials(): void {
		$store = new TGB_Token_Store();
		$store->set_api_key( 'test-api-key' );
		$store->set_webhook_secret( 'test-webhook-secret' );
	}

	private function enable_globally( array $extra = array() ): void {
		update_option(
			'dfwc_companion_global_settings',
			array_merge(
				array(
					'crypto_donations_enabled' => true,
					'tgb_environment'          => 'sandbox',
					'tgb_organization_id'      => 'org_123',
					'tgb_default_project_id'   => 'proj_default',
				),
				$extra
			),
			false
		);
	}

	public function test_should_render_false_when_globally_disabled(): void {
		$this->configure_credentials();

		$this->assertFalse( Crypto_Donation_Renderer::should_render( self::CAMPAIGN_ID ) );
	}

	public function test_should_render_false_when_credentials_missing(): void {
		$this->enable_globally();

		// No Token_Store credentials configured.
		$this->assertFalse( Crypto_Donation_Renderer::should_render( self::CAMPAIGN_ID ) );
	}

	public function test_should_render_true_when_global_on_and_credentials_present(): void {
		$this->configure_credentials();
		$this->enable_globally();

		$this->assertTrue( Crypto_Donation_Renderer::should_render( self::CAMPAIGN_ID ) );
	}

	public function test_should_render_false_for_preview_context(): void {
		$this->configure_credentials();
		$this->enable_globally();

		$this->assertFalse( Crypto_Donation_Renderer::should_render( self::CAMPAIGN_ID, 'preview' ) );
	}

	public function test_per_campaign_override_disables(): void {
		$this->configure_credentials();
		$this->enable_globally();

		update_post_meta(
			self::CAMPAIGN_ID,
			'_dfwc_companion_overrides',
			array( 'crypto' => array( 'enabled' => false ) )
		);

		$this->assertFalse( Crypto_Donation_Renderer::should_render( self::CAMPAIGN_ID ) );
	}

	public function test_per_campaign_override_enabled_does_not_break_default_on(): void {
		// When the override has 'enabled' => true explicitly, behavior is
		// the same as the default — global toggle still has to be on.
		$this->configure_credentials();
		$this->enable_globally();

		update_post_meta(
			self::CAMPAIGN_ID,
			'_dfwc_companion_overrides',
			array( 'crypto' => array( 'enabled' => true ) )
		);

		$this->assertTrue( Crypto_Donation_Renderer::should_render( self::CAMPAIGN_ID ) );
	}

	public function test_get_data_attributes_returns_public_fields_only(): void {
		$this->configure_credentials();
		$this->enable_globally();

		$attrs = Crypto_Donation_Renderer::get_data_attributes( self::CAMPAIGN_ID );

		$this->assertSame( '1', $attrs['data-crypto-enabled'] );
		$this->assertSame( 'sandbox', $attrs['data-tgb-environment'] );
		$this->assertSame( 'org_123', $attrs['data-tgb-org-id'] );
		$this->assertSame( 'proj_default', $attrs['data-tgb-project-id'] );

		// No secret fields leak.
		$this->assertArrayNotHasKey( 'data-tgb-api-key', $attrs );
		$this->assertArrayNotHasKey( 'data-tgb-webhook-secret', $attrs );
	}

	public function test_per_campaign_project_id_overrides_default(): void {
		$this->configure_credentials();
		$this->enable_globally();

		update_post_meta(
			self::CAMPAIGN_ID,
			'_dfwc_companion_overrides',
			array( 'crypto' => array( 'tgb_project_id' => 'proj_campaign_specific' ) )
		);

		$attrs = Crypto_Donation_Renderer::get_data_attributes( self::CAMPAIGN_ID );
		$this->assertSame( 'proj_campaign_specific', $attrs['data-tgb-project-id'] );
	}

	public function test_environment_falls_back_to_sandbox_when_invalid(): void {
		$this->configure_credentials();
		$this->enable_globally( array( 'tgb_environment' => 'mainnet' ) );

		$attrs = Crypto_Donation_Renderer::get_data_attributes( self::CAMPAIGN_ID );
		$this->assertSame( 'sandbox', $attrs['data-tgb-environment'] );
	}

	public function test_disabled_attributes_carry_zero_enabled_flag(): void {
		$attrs = Crypto_Donation_Renderer::get_disabled_attributes();

		$this->assertSame( '0', $attrs['data-crypto-enabled'] );
		$this->assertSame( '', $attrs['data-tgb-org-id'] );
		$this->assertSame( '', $attrs['data-tgb-project-id'] );
	}
}
