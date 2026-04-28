<?php
/**
 * Elementor Campaign Grid widget. Mirrors the shortcode + block surface;
 * delegates to Campaign_Directory_Renderer.
 *
 * Class definition guarded by `class_exists('\Elementor\Widget_Base')` so
 * the autoloader can resolve this file safely even when Elementor isn't
 * installed.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

if ( class_exists( '\Elementor\Widget_Base' ) ) {

	final class Elementor_Campaign_Grid_Widget extends \Elementor\Widget_Base {

		public function get_name() {
			return 'dfwc-campaign-grid';
		}

		public function get_title() {
			return __( 'Donation Campaign Grid', 'dfwc-companion' );
		}

		public function get_icon() {
			return 'eicon-posts-grid';
		}

		public function get_categories() {
			return array( 'general' );
		}

		public function get_keywords() {
			return array( 'donation', 'campaign', 'grid', 'directory', 'fundraising', 'nonprofit' );
		}

		protected function register_controls() {
			$this->start_controls_section(
				'section_main',
				array(
					'label' => __( 'Layout', 'dfwc-companion' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			$this->add_control(
				'layout',
				array(
					'label'   => __( 'Layout', 'dfwc-companion' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => array(
						'grid' => __( 'Grid', 'dfwc-companion' ),
						'list' => __( 'List', 'dfwc-companion' ),
					),
					'default' => 'grid',
				)
			);

			$this->add_control(
				'show_filters',
				array(
					'label'        => __( 'Show filters', 'dfwc-companion' ),
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);

			$this->add_control(
				'featured',
				array(
					'label'        => __( 'Featured campaigns only', 'dfwc-companion' ),
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => '',
				)
			);

			$this->add_control(
				'per_page',
				array(
					'label'   => __( 'Campaigns per page', 'dfwc-companion' ),
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'min'     => 1,
					'max'     => 50,
					'default' => 12,
				)
			);

			$this->end_controls_section();

			$this->start_controls_section(
				'section_filters',
				array(
					'label' => __( 'Filters (optional)', 'dfwc-companion' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			foreach ( array(
				'cause'             => __( 'Cause slug', 'dfwc-companion' ),
				'region'            => __( 'Region slug', 'dfwc-companion' ),
				'country'           => __( 'Country slug', 'dfwc-companion' ),
				'program'           => __( 'Program slug', 'dfwc-companion' ),
				'sponsorship_type'  => __( 'Sponsorship type slug', 'dfwc-companion' ),
				'urgency'           => __( 'Urgency slug', 'dfwc-companion' ),
			) as $key => $label ) {
				$this->add_control(
					$key,
					array(
						'label' => $label,
						'type'  => \Elementor\Controls_Manager::TEXT,
					)
				);
			}

			$this->end_controls_section();

			$this->start_controls_section(
				'section_sort',
				array(
					'label' => __( 'Sort', 'dfwc-companion' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				)
			);

			$this->add_control(
				'orderby',
				array(
					'label'   => __( 'Sort by', 'dfwc-companion' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => array(
						'menu_order' => __( 'Manual order', 'dfwc-companion' ),
						'featured'   => __( 'Featured first', 'dfwc-companion' ),
						'title'      => __( 'Title', 'dfwc-companion' ),
						'date'       => __( 'Date', 'dfwc-companion' ),
						'rand'       => __( 'Random', 'dfwc-companion' ),
					),
					'default' => 'menu_order',
				)
			);

			$this->add_control(
				'order',
				array(
					'label'   => __( 'Direction', 'dfwc-companion' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => array(
						'ASC'  => __( 'Ascending', 'dfwc-companion' ),
						'DESC' => __( 'Descending', 'dfwc-companion' ),
					),
					'default' => 'ASC',
				)
			);

			$this->end_controls_section();
		}

		protected function render() {
			$settings = $this->get_settings_for_display();

			Directory_Assets::enqueue();

			$args = array(
				'cause'             => isset( $settings['cause'] ) ? (string) $settings['cause'] : '',
				'region'            => isset( $settings['region'] ) ? (string) $settings['region'] : '',
				'country'           => isset( $settings['country'] ) ? (string) $settings['country'] : '',
				'program'           => isset( $settings['program'] ) ? (string) $settings['program'] : '',
				'sponsorship_type'  => isset( $settings['sponsorship_type'] ) ? (string) $settings['sponsorship_type'] : '',
				'urgency'           => isset( $settings['urgency'] ) ? (string) $settings['urgency'] : '',
				'featured'          => 'yes' === ( $settings['featured'] ?? '' ),
				'orderby'           => isset( $settings['orderby'] ) ? (string) $settings['orderby'] : 'menu_order',
				'order'             => isset( $settings['order'] ) ? (string) $settings['order'] : 'ASC',
				'per_page'          => isset( $settings['per_page'] ) ? (int) $settings['per_page'] : 12,
				'layout'             => isset( $settings['layout'] ) ? (string) $settings['layout'] : 'grid',
				'show_filters'      => 'yes' === ( $settings['show_filters'] ?? 'yes' ),
			);

			// Renderer output already escapes per-field; safe to echo.
			echo ( new Campaign_Directory_Renderer() )->render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
