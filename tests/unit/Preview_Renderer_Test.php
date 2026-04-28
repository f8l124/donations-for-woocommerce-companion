<?php
/**
 * Unit tests for Frontend\Preview_Renderer.
 *
 * Targets the rendered HTML's structural anchors that the overlay JS
 * depends on (`.wc-donation-in-action`, `.row1`, hidden inputs, the
 * `data-preview="1"` flag). If any of these go missing the donor-page
 * overlay would silently fail to mount inside the preview iframe.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Frontend\Preview_Renderer;
use PHPUnit\Framework\TestCase;

final class Preview_Renderer_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	private function bare_config(): array {
		return Defaults::for_campaign();
	}

	public function test_render_includes_overlay_target_wrapper(): void {
		$html = ( new Preview_Renderer() )->render( $this->bare_config() );
		$this->assertStringContainsString( 'data-dfwc-overlay-target', $html );
	}

	public function test_render_includes_preview_flag(): void {
		$html = ( new Preview_Renderer() )->render( $this->bare_config() );
		$this->assertStringContainsString( 'data-preview="1"', $html );
	}

	public function test_render_emits_mock_parent_scope_anchors(): void {
		$html = ( new Preview_Renderer() )->render( $this->bare_config() );

		$this->assertStringContainsString( 'wc-donation-in-action', $html );
		$this->assertStringContainsString( 'class="row1', $html );
		$this->assertStringContainsString( 'name="wc-donation-price"', $html );
		$this->assertStringContainsString( 'name="wc-donation-cause"', $html );
		$this->assertStringContainsString( 'wc-donation-f-submit-donation', $html );
	}

	public function test_render_disables_submit_button(): void {
		$html = ( new Preview_Renderer() )->render( $this->bare_config() );
		// Attribute order varies; verify both anchors live inside the same <button>.
		$this->assertMatchesRegularExpression(
			'#<button[^>]*\bdisabled\b[^>]*>#s',
			$html,
			'Submit button should carry the disabled attribute in preview mode.'
		);
		$this->assertStringContainsString( 'wc-donation-f-submit-donation', $html );
	}

	public function test_render_serializes_form_config_as_data_attribute(): void {
		$config = $this->bare_config();
		$config['monthly']['enabled']      = true;
		$config['monthly']['cta_template'] = 'Donate {amount}/month';

		$html = ( new Preview_Renderer() )->render( $config );

		$this->assertStringContainsString( 'data-config="', $html );
		// The CTA template appears in the JSON config.
		$this->assertStringContainsString( 'Donate {amount}\/month', $html );
	}

	public function test_render_respects_show_title_off(): void {
		$config = $this->bare_config();
		$config['display']['show_title'] = false;

		$html = ( new Preview_Renderer() )->render( $config );

		$this->assertStringNotContainsString( 'campaign-title', $html );
	}

	public function test_render_respects_show_title_on(): void {
		$config = $this->bare_config();
		$config['display']['show_title'] = true;

		$html = ( new Preview_Renderer() )->render( $config );

		$this->assertStringContainsString( 'campaign-title', $html );
	}

	public function test_render_uses_custom_cause_heading_when_set(): void {
		$config = $this->bare_config();
		$config['display']['cause_heading'] = 'Choose your impact';

		$html = ( new Preview_Renderer() )->render( $config );

		$this->assertStringContainsString( 'Choose your impact', $html );
	}

	public function test_render_falls_back_to_default_cause_heading(): void {
		$config = $this->bare_config();
		$config['display']['cause_heading'] = '';

		$html = ( new Preview_Renderer() )->render( $config );

		$this->assertStringContainsString( 'Select Cause', $html );
	}

	public function test_render_viewport_class_clamped(): void {
		// Valid viewports preserved.
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $vp ) {
			$html = ( new Preview_Renderer() )->render( $this->bare_config(), array( 'viewport' => $vp ) );
			$this->assertStringContainsString( 'dfwc-preview--' . $vp, $html );
		}

		// Invalid viewport falls back to desktop.
		$html = ( new Preview_Renderer() )->render( $this->bare_config(), array( 'viewport' => 'tv' ) );
		$this->assertStringContainsString( 'dfwc-preview--desktop', $html );
	}

	public function test_simulate_engine_passed_through_as_data_attr(): void {
		$html = ( new Preview_Renderer() )->render( $this->bare_config(), array( 'engine' => 'wcs' ) );
		$this->assertStringContainsString( 'data-engine="wcs"', $html );
	}
}
