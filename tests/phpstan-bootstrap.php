<?php
/**
 * PHPStan / PHPUnit bootstrap — minimal stubs for parent-plugin classes and
 * third-party compatibility surfaces (WPML, WCS, WPS SFW, Elementor) that the
 * companion references but whose source isn't part of our repo.
 *
 * Stubs only declare the methods/constants we actually call. They don't try to
 * accurately reflect the upstream signatures; PHPStan just needs the names to
 * exist. Real behavior is verified in Playwright E2E and manual QA.
 *
 * When upstream changes a signature in a way that breaks the companion, this
 * file is the first thing to update — keep stubs minimal so updating is cheap.
 *
 * Bracketed namespace syntax is used because the file declares stubs in
 * multiple namespaces (root + \Elementor). Mid-file `namespace Foo;` is a
 * parse error; `namespace Foo { ... }` is the only valid form when mixing.
 *
 * @package DFWC\Companion
 */

namespace {

    defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
    defined( 'WPINC' )   || define( 'WPINC', 'wp-includes' );

    // === Plugin constants (defined at runtime by the main plugin file) ===
    defined( 'DFWC_COMPANION_VERSION' )            || define( 'DFWC_COMPANION_VERSION', '0.6.6' );
    defined( 'DFWC_COMPANION_FILE' )               || define( 'DFWC_COMPANION_FILE', '' );
    defined( 'DFWC_COMPANION_PATH' )               || define( 'DFWC_COMPANION_PATH', '' );
    defined( 'DFWC_COMPANION_URL' )                || define( 'DFWC_COMPANION_URL', '' );
    defined( 'DFWC_COMPANION_BASENAME' )           || define( 'DFWC_COMPANION_BASENAME', '' );
    defined( 'DFWC_COMPANION_MIN_PARENT_VERSION' ) || define( 'DFWC_COMPANION_MIN_PARENT_VERSION', '3.9.8' );
    defined( 'DFWC_COMPANION_MIN_PHP' )            || define( 'DFWC_COMPANION_MIN_PHP', '7.4' );

    // === Parent plugin constants ===
    // Path stub uses a non-empty string so PHPStan doesn't infer "always falsy".
    defined( 'WC_DONATION_VERSION' ) || define( 'WC_DONATION_VERSION', '3.9.8' );
    defined( 'WC_DONATION_PATH' )    || define( 'WC_DONATION_PATH', '/wp-content/plugins/donation-for-woocommerce/' );

    // === Parent plugin: minimal class stubs ===

    if ( ! class_exists( 'WcdonationCampaignSetting' ) ) {
        /**
         * Parent plugin class — stub only; real source at
         * includes/classes/class-wcdonationcampaignsetting.php in the parent plugin.
         */
        class WcdonationCampaignSetting {
            /** @var array<string,mixed>|object|null */
            public $product;

            /**
             * @param int $campaign_id
             * @return self|null
             */
            public static function get_product_by_campaign( $campaign_id ) {
                return null;
            }
        }
    }

    if ( ! class_exists( 'WcdonationSetting' ) ) {
        class WcdonationSetting {
            /**
             * @param int $product_id
             * @return int
             */
            public static function get_campaign_id_by_product_id( $product_id ) {
                return 0;
            }
        }
    }

    if ( ! class_exists( 'WcDonation' ) ) {
        class WcDonation {
            /**
             * @return array<string,string>
             */
            public static function DISPLAY_RECURRING_TYPE() {
                return [];
            }
        }
    }

    // === Subscription engine stubs ===

    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        class WC_Subscriptions {}
    }

    if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
        class WC_Subscriptions_Product {
            /**
             * @param int $product_id
             * @return bool
             */
            public static function is_subscription( $product_id ) {
                return false;
            }
        }
    }

    if ( ! class_exists( 'Subscriptions_For_Woocommerce' ) ) {
        class Subscriptions_For_Woocommerce {}
    }

    if ( ! function_exists( 'wps_sfw_get_meta_data' ) ) {
        /**
         * @param int|string $object_id
         * @param string     $meta_key
         * @param bool       $single
         * @return mixed
         */
        function wps_sfw_get_meta_data( $object_id, $meta_key, $single = false ) {
            return '';
        }
    }

    if ( ! function_exists( 'wps_sfw_update_meta_data' ) ) {
        /**
         * @param int|string $object_id
         * @param string     $meta_key
         * @param mixed      $value
         * @return bool
         */
        function wps_sfw_update_meta_data( $object_id, $meta_key, $value ) {
            return true;
        }
    }

    // === WPML / WCML stubs (Phase 6+ integration) ===

    if ( ! function_exists( 'icl_object_id' ) ) {
        /**
         * @param int    $element_id
         * @param string $element_type
         * @param bool   $return_original_if_missing
         * @param string|null $language_code
         * @return int
         */
        function icl_object_id( $element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null ) {
            return $element_id;
        }
    }

    if ( ! function_exists( 'wcml_get_user_currency' ) ) {
        /**
         * @return string
         */
        function wcml_get_user_currency() {
            return 'USD';
        }
    }

    if ( ! function_exists( 'wcml_multi_currency' ) ) {
        /**
         * @return object|null
         */
        function wcml_multi_currency() {
            return null;
        }
    }

    // === WP-CLI stubs (Phase 11) ===

    if ( ! defined( 'WP_CLI' ) ) {
        define( 'WP_CLI', false );
    }

    if ( ! class_exists( 'WP_CLI' ) ) {
        /**
         * Stub for the WP-CLI runtime class. Real definition is loaded by
         * WP-CLI itself when running under `wp` — never present in tests
         * or under regular HTTP requests.
         */
        class WP_CLI {
            /** @param string $name @param string|callable $callable */
            public static function add_command( $name, $callable, $args = array() ): void {}
            /** @param string $msg */
            public static function line( $msg = '' ): void {}
            /** @param string $msg */
            public static function success( $msg ): void {}
            /** @param string $msg */
            public static function warning( $msg ): void {}
            /** @param string $msg @param bool $exit */
            public static function error( $msg, $exit = true ): void {}
            /** @param int $code */
            public static function halt( $code ): void {}
        }
    }
}

namespace WP_CLI\Utils {
    if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
        /**
         * @param string                          $format
         * @param array<int,array<string,mixed>>  $items
         * @param array<int,string>|string        $fields
         */
        function format_items( $format, $items, $fields ): void {}
    }
}

// === Elementor stubs in their own namespace ===
namespace Elementor {

    if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
        class Widget_Base {
            /**
             * @return array<string,mixed>
             */
            public function get_settings_for_display() {
                return [];
            }

            /** @return string */
            public function get_name() {
                return '';
            }

            /** @return string */
            public function get_title() {
                return '';
            }

            /** @return string */
            public function get_icon() {
                return '';
            }

            /** @return array<int,string> */
            public function get_categories() {
                return [];
            }

            /** @return array<int,string> */
            public function get_keywords() {
                return [];
            }

            protected function start_controls_section( $id, array $args = [] ) {}

            protected function add_control( $id, array $args = [] ) {}

            protected function end_controls_section() {}
        }
    }

    if ( ! class_exists( '\Elementor\Controls_Manager' ) ) {
        class Controls_Manager {
            const TAB_CONTENT = 'content';
            const SELECT2     = 'select2';
            const SELECT      = 'select';
            const TEXT        = 'text';
            const NUMBER      = 'number';
            const SWITCHER    = 'switcher';
        }
    }
}
