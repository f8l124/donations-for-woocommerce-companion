<?php
/**
 * Parent_Form_Contract_Checker — runs every contract's check, returns Report.
 *
 * Single source of truth for "is the companion's environment healthy?"
 * Cached per request via static; cached across requests via 12h transient.
 *
 * Other plugin code consumes the report:
 * - `Frontend\Context_Augmenter` skips augmentation when broken
 * - `Admin\Diagnostics_Page` displays the full result grid
 * - `Admin\Self_Check` (refactored) surfaces admin notices for fail/warn states
 *
 * Adding a new check:
 *   1. Add a check method (`check_my_thing()`) returning Parent_Form_Contract_Result.
 *   2. Add a Parent_Form_Contract entry in `build_contracts()`.
 *   3. Update `docs/architecture/parent-contract.md` if it covers a parent surface.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Contracts;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Engine_Detector;

final class Parent_Form_Contract_Checker {

	private const TRANSIENT_KEY = 'dfwc_contract_report';
	private const CACHE_TTL     = 12 * HOUR_IN_SECONDS;

	/** @var Parent_Form_Contract[] */
	private array $contracts;

	/** @var Parent_Form_Contract_Report|null Per-request cache. */
	private static ?Parent_Form_Contract_Report $request_cache = null;

	public function __construct() {
		$this->contracts = $this->build_contracts();
	}

	/**
	 * Return the current contract report, optionally bypassing transient cache.
	 *
	 * @param bool $force_refresh When true, re-run all checks and refresh the cache.
	 */
	public function get_report( bool $force_refresh = false ): Parent_Form_Contract_Report {
		if ( ! $force_refresh && null !== self::$request_cache ) {
			return self::$request_cache;
		}

		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				$report               = self::report_from_array( $cached );
				self::$request_cache  = $report;
				return $report;
			}
		}

		$results = array();
		foreach ( $this->contracts as $contract ) {
			$results[] = $this->run_check_safely( $contract );
		}

		$report = new Parent_Form_Contract_Report( $results, time() );

		/**
		 * Fires after a fresh contract report is built but before it caches.
		 * Lets third-party monitors (Sentry, etc.) ingest the report.
		 *
		 * @param Parent_Form_Contract_Report $report
		 */
		do_action( 'dfwc_companion_contract_report_built', $report );

		set_transient( self::TRANSIENT_KEY, $report->to_array(), self::CACHE_TTL );
		self::$request_cache = $report;
		return $report;
	}

	/**
	 * Clear the cached report. Called from admin "Re-check" handler and on
	 * plugin activation.
	 */
	public function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
		self::$request_cache = null;

		/**
		 * Fires after the contract report cache is cleared.
		 */
		do_action( 'dfwc_companion_contract_report_cleared' );
	}

	/**
	 * Map of contract IDs → translated labels. Used by the support-report
	 * markdown exporter and the diagnostics page renderer.
	 *
	 * @return array<string,string>
	 */
	public function contract_labels(): array {
		$out = array();
		foreach ( $this->contracts as $c ) {
			$out[ $c->id ] = $c->label;
		}
		return $out;
	}

	/**
	 * Map of contract IDs → contract objects (for renderers that need
	 * description, severity, etc.).
	 *
	 * @return array<string,Parent_Form_Contract>
	 */
	public function contracts_by_id(): array {
		$out = array();
		foreach ( $this->contracts as $c ) {
			$out[ $c->id ] = $c;
		}
		return $out;
	}

	private function run_check_safely( Parent_Form_Contract $contract ): Parent_Form_Contract_Result {
		try {
			$result = call_user_func( $contract->check );
			if ( ! $result instanceof Parent_Form_Contract_Result ) {
				return Parent_Form_Contract_Result::warn(
					$contract->id,
					/* translators: contract diagnostic — fallback when a check returns the wrong type */
					__( 'Contract check did not return a valid result.', 'dfwc-companion' )
				);
			}
			return $result;
		} catch ( \Throwable $e ) {
			return Parent_Form_Contract_Result::fail(
				$contract->id,
				/* translators: contract diagnostic — when a check throws */
				__( 'Contract check threw an exception.', 'dfwc-companion' ),
				/* translators: remediation for an exception in a check */
				__( 'Verify the parent plugin and required dependencies are active. If the problem persists, file an issue with the support report from this page.', 'dfwc-companion' ),
				array( 'exception_class' => get_class( $e ) )
			);
		}
	}

	/**
	 * Build the registry of contract entries.
	 *
	 * @return Parent_Form_Contract[]
	 */
	private function build_contracts(): array {
		$contracts = array(
			new Parent_Form_Contract(
				'wc_active',
				__( 'WooCommerce active', 'dfwc-companion' ),
				__( 'WooCommerce must be installed and active.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_ERROR,
				array( $this, 'check_wc_active' )
			),
			new Parent_Form_Contract(
				'parent_active',
				__( 'Parent plugin active', 'dfwc-companion' ),
				__( 'Donation for WooCommerce must be installed and active.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_ERROR,
				array( $this, 'check_parent_active' )
			),
			new Parent_Form_Contract(
				'parent_version',
				__( 'Parent plugin version compatible', 'dfwc-companion' ),
				__( 'Companion is tested against parent plugin v3.9.x. Newer major versions may break compatibility.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_WARNING,
				array( $this, 'check_parent_version' )
			),
			new Parent_Form_Contract(
				'campaign_post_type',
				__( 'Campaign post type registered', 'dfwc-companion' ),
				__( 'The wc-donation post type must exist for campaigns.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_ERROR,
				array( $this, 'check_campaign_post_type' )
			),
			new Parent_Form_Contract(
				'subscription_engine',
				__( 'Subscription engine detected', 'dfwc-companion' ),
				__( 'A subscription engine (WC Subscriptions or Subscriptions for WooCommerce) is required for monthly/annual recurring donations.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_WARNING,
				array( $this, 'check_subscription_engine' )
			),
			new Parent_Form_Contract(
				'parent_ajax_action',
				__( 'Parent AJAX handler bound', 'dfwc-companion' ),
				__( 'Parent plugin must register the donation_to_order AJAX action for donor submissions to work.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_ERROR,
				array( $this, 'check_parent_ajax_action' )
			),
			new Parent_Form_Contract(
				'parent_hook_files',
				__( 'Parent hook files intact', 'dfwc-companion' ),
				__( 'Parent template files we render alongside should be present at expected paths.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_WARNING,
				array( $this, 'check_parent_hook_files' )
			),
			new Parent_Form_Contract(
				'overlay_assets',
				__( 'Overlay assets registered', 'dfwc-companion' ),
				__( 'Companion overlay JS and CSS handles must be registered with WordPress.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_ERROR,
				array( $this, 'check_overlay_assets' )
			),
			new Parent_Form_Contract(
				'php_version',
				__( 'PHP version supported', 'dfwc-companion' ),
				__( 'PHP 7.4 or newer is required.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_ERROR,
				array( $this, 'check_php_version' )
			),
			new Parent_Form_Contract(
				'wp_version',
				__( 'WordPress version supported', 'dfwc-companion' ),
				__( 'WordPress 6.2 or newer is required.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_WARNING,
				array( $this, 'check_wp_version' )
			),
			// === WPML / WCML (informational; per AA-wpml-integration.md §4 Phase 2) ===
			new Parent_Form_Contract(
				'wpml_present',
				__( 'WPML detected (optional)', 'dfwc-companion' ),
				__( 'WPML enables multilingual templates, taxonomies, and donor-facing strings. Optional but recommended for multilingual sites.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_INFO,
				array( $this, 'check_wpml_present' )
			),
			new Parent_Form_Contract(
				'wcml_present',
				__( 'WPML WooCommerce Multilingual detected (optional)', 'dfwc-companion' ),
				__( 'WCML enables per-currency preset amounts (Phase 6, v1.0.0+). Optional.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_INFO,
				array( $this, 'check_wcml_present' )
			),
			// Phase 7 — diagnostic when advanced intervals are enabled but no
			// subscription engine is available to serve them. Surfaces silently
			// only when both conditions hold; otherwise passes.
			new Parent_Form_Contract(
				'advanced_intervals_engine',
				__( 'Advanced intervals supported by engine', 'dfwc-companion' ),
				__( 'Weekly / quarterly / semi-annual / custom cadences require a subscription engine.', 'dfwc-companion' ),
				Parent_Form_Contract::SEVERITY_WARNING,
				array( $this, 'check_advanced_intervals_engine' )
			),
		);

		/**
		 * Allow third parties to add custom contract entries.
		 *
		 * @param Parent_Form_Contract[] $contracts
		 */
		$filtered = apply_filters( 'dfwc_companion_contracts', $contracts );

		// Defensive: if the filter returns garbage, fall back to our own list.
		if ( ! is_array( $filtered ) ) {
			return $contracts;
		}
		return array_values(
			array_filter(
				$filtered,
				static function ( $c ): bool {
					return $c instanceof Parent_Form_Contract;
				}
			)
		);
	}

	// === Individual check implementations ===

	public function check_wc_active(): Parent_Form_Contract_Result {
		if ( ! class_exists( '\WooCommerce' ) ) {
			return Parent_Form_Contract_Result::fail(
				'wc_active',
				__( 'WooCommerce is not active.', 'dfwc-companion' ),
				__( 'Install and activate WooCommerce.', 'dfwc-companion' )
			);
		}
		$version = $this->wc_version();
		return Parent_Form_Contract_Result::pass(
			'wc_active',
			sprintf( /* translators: %s: WooCommerce version */ __( 'WooCommerce %s active.', 'dfwc-companion' ), $version ),
			array( 'wc_version' => $version )
		);
	}

	public function check_parent_active(): Parent_Form_Contract_Result {
		if ( ! defined( 'WC_DONATION_VERSION' ) ) {
			return Parent_Form_Contract_Result::fail(
				'parent_active',
				__( 'Donation for WooCommerce is not active.', 'dfwc-companion' ),
				__( 'Install and activate Donation for WooCommerce.', 'dfwc-companion' )
			);
		}
		return Parent_Form_Contract_Result::pass(
			'parent_active',
			sprintf( /* translators: %s: parent plugin version */ __( 'Donation for WooCommerce %s active.', 'dfwc-companion' ), WC_DONATION_VERSION ),
			array( 'parent_version' => WC_DONATION_VERSION )
		);
	}

	public function check_parent_version(): Parent_Form_Contract_Result {
		if ( ! defined( 'WC_DONATION_VERSION' ) ) {
			return Parent_Form_Contract_Result::warn(
				'parent_version',
				__( 'Parent version not detectable.', 'dfwc-companion' )
			);
		}
		$current = WC_DONATION_VERSION;
		$min     = defined( 'DFWC_COMPANION_MIN_PARENT_VERSION' ) ? DFWC_COMPANION_MIN_PARENT_VERSION : '3.9.8';

		if ( version_compare( $current, $min, '<' ) ) {
			return Parent_Form_Contract_Result::warn(
				'parent_version',
				sprintf(
					/* translators: 1: current parent version, 2: minimum tested */
					__( 'Parent v%1$s is older than tested minimum v%2$s. Some features may not work correctly.', 'dfwc-companion' ),
					$current,
					$min
				),
				__( 'Upgrade Donation for WooCommerce to the latest version.', 'dfwc-companion' )
			);
		}
		if ( version_compare( $current, '4.0.0', '>=' ) ) {
			return Parent_Form_Contract_Result::warn(
				'parent_version',
				sprintf( /* translators: %s: parent plugin version */ __( 'Parent v%s is a major version newer than tested. Verify donor flow before relying on companion.', 'dfwc-companion' ), $current ),
				__( 'Test the donor flow on a staging site, then update the companion if a newer version is available.', 'dfwc-companion' )
			);
		}
		return Parent_Form_Contract_Result::pass(
			'parent_version',
			sprintf( /* translators: %s: parent plugin version */ __( 'Parent v%s within tested range.', 'dfwc-companion' ), $current ),
			array( 'parent_version' => $current )
		);
	}

	public function check_campaign_post_type(): Parent_Form_Contract_Result {
		if ( ! post_type_exists( 'wc-donation' ) ) {
			return Parent_Form_Contract_Result::fail(
				'campaign_post_type',
				__( 'wc-donation post type not registered.', 'dfwc-companion' ),
				__( 'Reactivate Donation for WooCommerce.', 'dfwc-companion' )
			);
		}
		return Parent_Form_Contract_Result::pass( 'campaign_post_type' );
	}

	public function check_subscription_engine(): Parent_Form_Contract_Result {
		$engine = Engine_Detector::detect();
		if ( Engine_Detector::ENGINE_NONE === $engine ) {
			return Parent_Form_Contract_Result::warn(
				'subscription_engine',
				__( 'No subscription engine detected. Recurring donation tabs are disabled.', 'dfwc-companion' ),
				__( 'Install Subscriptions For WooCommerce (free) or WooCommerce Subscriptions (paid).', 'dfwc-companion' ),
				array( 'engine' => 'none' )
			);
		}
		$label = Engine_Detector::ENGINE_WCS === $engine
			? __( 'WooCommerce Subscriptions detected.', 'dfwc-companion' )
			: __( 'Subscriptions For WooCommerce detected.', 'dfwc-companion' );
		return Parent_Form_Contract_Result::pass(
			'subscription_engine',
			$label,
			array( 'engine' => $engine )
		);
	}

	public function check_parent_ajax_action(): Parent_Form_Contract_Result {
		if ( ! has_action( 'wp_ajax_donation_to_order' ) && ! has_action( 'wp_ajax_nopriv_donation_to_order' ) ) {
			return Parent_Form_Contract_Result::fail(
				'parent_ajax_action',
				__( 'Parent AJAX action donation_to_order is not registered.', 'dfwc-companion' ),
				__( 'Reactivate Donation for WooCommerce. If the issue persists, check the parent plugin version is compatible.', 'dfwc-companion' )
			);
		}
		return Parent_Form_Contract_Result::pass( 'parent_ajax_action' );
	}

	public function check_parent_hook_files(): Parent_Form_Contract_Result {
		$expected = array(
			'class-wcdonationorder.php',
			'class-wcdonationcampaignsetting.php',
			'class-wcdonationproces.php',
		);
		$missing  = array();
		$base     = defined( 'WC_DONATION_PATH' ) ? WC_DONATION_PATH : null;
		if ( ! $base ) {
			return Parent_Form_Contract_Result::warn(
				'parent_hook_files',
				__( 'Parent plugin path not detectable.', 'dfwc-companion' )
			);
		}
		foreach ( $expected as $rel ) {
			if ( ! file_exists( $base . 'includes/classes/' . $rel ) ) {
				$missing[] = $rel;
			}
		}
		if ( ! empty( $missing ) ) {
			return Parent_Form_Contract_Result::warn(
				'parent_hook_files',
				__( 'Some parent files we hook into are missing.', 'dfwc-companion' ),
				__( 'Reinstall Donation for WooCommerce. Companion may fall back to the vanilla parent form.', 'dfwc-companion' ),
				array( 'missing' => implode( ',', $missing ) )
			);
		}
		return Parent_Form_Contract_Result::pass( 'parent_hook_files' );
	}

	public function check_overlay_assets(): Parent_Form_Contract_Result {
		$handle = '\\DFWC\\Companion\\Frontend\\Assets::HANDLE_JS';
		if ( ! class_exists( '\\DFWC\\Companion\\Frontend\\Assets' ) ) {
			return Parent_Form_Contract_Result::fail(
				'overlay_assets',
				__( 'Companion frontend assets class missing.', 'dfwc-companion' ),
				__( 'Reactivate the companion plugin.', 'dfwc-companion' )
			);
		}
		// At admin_init time, scripts are not yet registered (registration happens
		// on wp_enqueue_scripts which doesn't fire on admin pages). The check
		// instead verifies the asset class loaded; runtime registration is
		// implicitly verified by donor flow. Avoids a false-positive on admin.
		return Parent_Form_Contract_Result::pass( 'overlay_assets' );
	}

	public function check_php_version(): Parent_Form_Contract_Result {
		if ( PHP_VERSION_ID < 70400 ) {
			return Parent_Form_Contract_Result::fail(
				'php_version',
				sprintf( /* translators: %s: PHP version */ __( 'PHP %s is below the required 7.4.', 'dfwc-companion' ), PHP_VERSION ),
				__( 'Ask your host to upgrade PHP to 7.4 or newer.', 'dfwc-companion' ),
				array( 'php_version' => PHP_VERSION )
			);
		}
		return Parent_Form_Contract_Result::pass(
			'php_version',
			sprintf( /* translators: %s: PHP version */ __( 'PHP %s active.', 'dfwc-companion' ), PHP_VERSION ),
			array( 'php_version' => PHP_VERSION )
		);
	}

	public function check_wp_version(): Parent_Form_Contract_Result {
		$wp_version = $GLOBALS['wp_version'] ?? '';
		if ( '' === $wp_version || version_compare( $wp_version, '6.2', '<' ) ) {
			return Parent_Form_Contract_Result::warn(
				'wp_version',
				sprintf( /* translators: %s: WordPress version */ __( 'WordPress %s is older than tested minimum 6.2.', 'dfwc-companion' ), $wp_version ),
				__( 'Update WordPress to 6.2 or newer.', 'dfwc-companion' ),
				array( 'wp_version' => $wp_version )
			);
		}
		return Parent_Form_Contract_Result::pass(
			'wp_version',
			sprintf( /* translators: %s: WordPress version */ __( 'WordPress %s active.', 'dfwc-companion' ), $wp_version ),
			array( 'wp_version' => $wp_version )
		);
	}

	public function check_wpml_present(): Parent_Form_Contract_Result {
		if ( defined( 'ICL_LANGUAGE_CODE' ) || function_exists( 'icl_object_id' ) ) {
			$lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'unknown';
			return Parent_Form_Contract_Result::pass(
				'wpml_present',
				/* translators: %s: current WPML language code */
				sprintf( __( 'WPML detected (active language: %s).', 'dfwc-companion' ), $lang ),
				array( 'language' => $lang )
			);
		}
		// INFO severity: not a problem; just informational.
		return Parent_Form_Contract_Result::pass(
			'wpml_present',
			__( 'WPML not detected. Single-language site assumed; that is fine.', 'dfwc-companion' )
		);
	}

	public function check_advanced_intervals_engine(): Parent_Form_Contract_Result {
		if ( ! Config_Resolver::advanced_enabled() ) {
			return Parent_Form_Contract_Result::pass(
				'advanced_intervals_engine',
				__( 'Advanced intervals toggle is off. Standard intervals only.', 'dfwc-companion' )
			);
		}

		$engine = Engine_Detector::detect();
		if ( Engine_Detector::ENGINE_NONE === $engine ) {
			return Parent_Form_Contract_Result::warn(
				'advanced_intervals_engine',
				__( 'Advanced intervals are enabled, but no subscription engine is active. Donors will not see weekly / quarterly / semi-annual / custom tabs even when an admin enables them per campaign.', 'dfwc-companion' ),
				__( 'Install Subscriptions For WooCommerce (free) or WooCommerce Subscriptions (paid), or turn off the global "Enable advanced giving intervals" setting to suppress this warning.', 'dfwc-companion' ),
				array( 'engine' => 'none' )
			);
		}

		return Parent_Form_Contract_Result::pass(
			'advanced_intervals_engine',
			/* translators: %s: engine slug (wcs / wps_sfw) */
			sprintf( __( 'Advanced intervals supported by active engine (%s).', 'dfwc-companion' ), $engine ),
			array( 'engine' => $engine )
		);
	}

	public function check_wcml_present(): Parent_Form_Contract_Result {
		if ( class_exists( '\\WCML_Multi_Currency' ) || function_exists( 'wcml_get_user_currency' ) ) {
			return Parent_Form_Contract_Result::pass(
				'wcml_present',
				__( 'WPML WooCommerce Multilingual detected.', 'dfwc-companion' )
			);
		}
		return Parent_Form_Contract_Result::pass(
			'wcml_present',
			__( 'WCML not detected. Per-currency presets unavailable; base currency used.', 'dfwc-companion' )
		);
	}

	private function wc_version(): string {
		if ( function_exists( 'WC' ) ) {
			$wc = call_user_func( 'WC' );
			if ( is_object( $wc ) && property_exists( $wc, 'version' ) ) {
				return (string) $wc->version;
			}
		}
		return defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
	}

	/**
	 * Reconstitute a Report from its array form (used after transient read).
	 *
	 * @param array<string,mixed> $data
	 */
	public static function report_from_array( array $data ): Parent_Form_Contract_Report {
		$results     = array();
		$raw_results = $data['results'] ?? array();
		if ( is_array( $raw_results ) ) {
			foreach ( $raw_results as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$results[] = new Parent_Form_Contract_Result(
					(string) ( $r['contract_id'] ?? '' ),
					(string) ( $r['status'] ?? Parent_Form_Contract_Result::STATUS_FAIL ),
					(string) ( $r['message'] ?? '' ),
					(string) ( $r['remediation'] ?? '' ),
					(array) ( $r['context'] ?? array() )
				);
			}
		}
		return new Parent_Form_Contract_Report( $results, (int) ( $data['checked_at'] ?? 0 ) );
	}
}
