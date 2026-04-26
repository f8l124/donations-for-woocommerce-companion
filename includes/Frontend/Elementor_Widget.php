<?php
/**
 * Elementor widget that delegates to Frontend\Renderer.
 *
 * Class definition guarded by `class_exists('\Elementor\Widget_Base')` so the
 * autoloader can resolve this file safely even when Elementor isn't installed.
 * Production wiring (Elementor_Adapter) only references Elementor_Widget from
 * inside the elementor/widgets/register hook, so the guard is defense-in-depth.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

if ( class_exists( '\Elementor\Widget_Base' ) ) {

	final class Elementor_Widget extends \Elementor\Widget_Base {

		public function get_name() {
			return 'dfwc-recurring-donation';
		}

		public function get_title() {
			return __( 'Recurring Donation', 'dfwc-companion' );
		}

		public function get_icon() {
			return 'eicon-heart-o';
		}

		public function get_categories() {
			return [ 'general' ];
		}

		public function get_keywords() {
			return [ 'donation', 'recurring', 'subscription', 'fundraising' ];
		}

		protected function register_controls() {
			$this->start_controls_section(
				'section_main',
				[
					'label' => __( 'Campaign', 'dfwc-companion' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				]
			);

			$this->add_control(
				'campaign_id',
				[
					'label'       => __( 'Donation campaign', 'dfwc-companion' ),
					'type'        => \Elementor\Controls_Manager::SELECT2,
					'options'     => $this->get_campaign_options(),
					'default'     => '',
					'description' => __( 'Pick the campaign whose interval-first form should appear here.', 'dfwc-companion' ),
				]
			);

			$this->end_controls_section();
		}

		protected function render() {
			$settings    = $this->get_settings_for_display();
			$campaign_id = isset( $settings['campaign_id'] ) ? absint( $settings['campaign_id'] ) : 0;

			if ( $campaign_id < 1 ) {
				echo '<div class="dfwc-overlay-preview">'
					. esc_html__( 'Select a donation campaign in the widget settings.', 'dfwc-companion' )
					. '</div>';
				return;
			}

			// Pull in the form assets (registered globally by Frontend\Assets).
			Assets::enqueue();

			// Renderer output is already escaped (template uses esc_*; CTA via wp_kses).
			echo Renderer::render( $campaign_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Build the SELECT2 options from published wc-donation campaigns.
		 *
		 * @return array<string,string>
		 */
		private function get_campaign_options(): array {
			$opts = [ '' => __( '— Select a campaign —', 'dfwc-companion' ) ];

			$campaigns = get_posts(
				[
					'post_type'      => 'wc-donation',
					'post_status'    => 'publish',
					'numberposts'    => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'suppress_filters' => false,
				]
			);

			foreach ( $campaigns as $post ) {
				$opts[ (string) $post->ID ] = $post->post_title;
			}

			return $opts;
		}
	}
}
