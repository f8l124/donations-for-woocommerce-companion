<?php
/**
 * Self_Check — admin-notice surface for the Parent_Form_Contract_Checker.
 *
 * As of Phase 2 (v0.7.0), Self_Check no longer runs its own probes. It
 * delegates to Parent_Form_Contract_Checker and renders any non-pass
 * results as dismissible admin notices. The full grid lives at the
 * Diagnostics page; Self_Check is the "did you notice?" layer.
 *
 * Backward-compat: the old `dfwc_self_check` transient is cleared once on
 * plugin activation (Plugin::on_activation) so legacy data doesn't linger.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Contracts\Parent_Form_Contract_Checker;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;

final class Self_Check {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_warm_cache' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Trigger a contract check on first admin page load each cache cycle.
	 * No-op when the report transient is fresh.
	 */
	public function maybe_warm_cache(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		( new Parent_Form_Contract_Checker() )->get_report();
	}

	/**
	 * Render dismissible admin notices for any non-pass contract result.
	 * Warnings + failures only; passes don't notify (they're status quo).
	 *
	 * Suppressed on certain screens (update, install) where notices would be
	 * inappropriate. Diagnostics page itself shows the same data inline; we
	 * suppress notices there too to avoid duplication.
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! $this->should_render_on_current_screen() ) {
			return;
		}

		$report = ( new Parent_Form_Contract_Checker() )->get_report();
		if ( $report->is_healthy() ) {
			return;
		}

		foreach ( $report->results as $result ) {
			if ( $result->passed() ) {
				continue;
			}
			$notice_class = Parent_Form_Contract_Result::STATUS_FAIL === $result->status
				? 'notice-error'
				: 'notice-warning';

			printf(
				'<div class="notice %1$s is-dismissible"><p><strong>%2$s</strong> %3$s%4$s</p><p><a href="%5$s">%6$s</a></p></div>',
				esc_attr( $notice_class ),
				esc_html__( 'Donations for WooCommerce Companion:', 'dfwc-companion' ),
				esc_html( $result->message ),
				'' !== $result->remediation ? ' &nbsp; ' . esc_html( $result->remediation ) : '',
				esc_url( admin_url( 'admin.php?page=' . Admin_Menu::DIAGNOSTICS_SLUG ) ),
				esc_html__( 'Open Diagnostics', 'dfwc-companion' )
			);
		}
	}

	private function should_render_on_current_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return true;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return true;
		}
		// Suppress on update / customize / installer screens.
		$blocked = array( 'update-core', 'update', 'customize', 'install-plugins', 'plugin-install' );
		if ( in_array( $screen->id, $blocked, true ) ) {
			return false;
		}
		// Suppress on the Diagnostics page itself — it shows the same data inline.
		if ( false !== strpos( (string) $screen->id, Admin_Menu::DIAGNOSTICS_SLUG ) ) {
			return false;
		}
		return true;
	}
}
