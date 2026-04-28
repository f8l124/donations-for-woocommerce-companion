<?php
/**
 * Diagnostics_Page — admin page rendering the contract report.
 *
 * Lives at WooCommerce → Donations Companion → Diagnostics. Surfaces:
 * - Overall status (healthy / warning / broken) with appropriate notice color
 * - Full table of every check with status pill, message, remediation
 * - Re-check button (clears transient, runs fresh probes)
 * - Support-report textarea: copy-paste-ready Markdown for issue/support tickets
 *
 * Tight integration: WP-native admin classes (`wrap`, `wp-list-table`,
 * `notice notice-*`). No bespoke admin CSS framework. Submit button via
 * `submit_button()`. Status pill colors via the small CSS additions in
 * `assets/css/dfwc-admin.css`.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Contracts\Parent_Form_Contract_Checker;
use DFWC\Companion\Contracts\Parent_Form_Contract_Report;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;

final class Diagnostics_Page {

	public const RECHECK_ACTION = 'dfwc_recheck_diagnostics';
	public const RECHECK_NONCE  = '_dfwc_recheck_nonce';

	public function __construct() {
		add_action( 'admin_post_' . self::RECHECK_ACTION, array( $this, 'handle_recheck' ) );
	}

	/**
	 * Static render entry point — registered as the menu page callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}

		$checker = new Parent_Form_Contract_Checker();
		$report  = $checker->get_report();
		$labels  = $checker->contract_labels();
		$descs   = array();
		foreach ( $checker->contracts_by_id() as $id => $contract ) {
			$descs[ $id ] = $contract->description;
		}

		$support_md = $report->to_markdown( $labels );

		include DFWC_COMPANION_PATH . 'templates/diagnostics-page.php';
	}

	/**
	 * Handle the re-check button POST: nonce + cap, clear cache, redirect.
	 */
	public function handle_recheck(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::RECHECK_ACTION, self::RECHECK_NONCE );

		( new Parent_Form_Contract_Checker() )->clear_cache();

		$redirect = add_query_arg(
			array( 'rechecked' => 1 ),
			admin_url( 'admin.php?page=' . Admin_Menu::DIAGNOSTICS_SLUG )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Map status → notice CSS class (for the overall-status banner).
	 */
	public static function notice_class_for_status( string $status ): string {
		switch ( $status ) {
			case Parent_Form_Contract_Report::STATUS_BROKEN:
				return 'notice-error';
			case Parent_Form_Contract_Report::STATUS_WARNING:
				return 'notice-warning';
			default:
				return 'notice-success';
		}
	}

	/**
	 * Map status → translatable human label (for the overall banner).
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Parent_Form_Contract_Report::STATUS_HEALTHY:
				return __( 'Healthy', 'dfwc-companion' );
			case Parent_Form_Contract_Report::STATUS_WARNING:
				return __( 'Warning', 'dfwc-companion' );
			case Parent_Form_Contract_Report::STATUS_BROKEN:
				return __( 'Broken', 'dfwc-companion' );
		}
		return $status;
	}

	/**
	 * Map result status → translatable label (for per-row status pill).
	 */
	public static function result_status_label( string $status ): string {
		switch ( $status ) {
			case Parent_Form_Contract_Result::STATUS_PASS:
				return __( 'Pass', 'dfwc-companion' );
			case Parent_Form_Contract_Result::STATUS_WARNING:
				return __( 'Warning', 'dfwc-companion' );
			case Parent_Form_Contract_Result::STATUS_FAIL:
				return __( 'Fail', 'dfwc-companion' );
		}
		return $status;
	}
}
