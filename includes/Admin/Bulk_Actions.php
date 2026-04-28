<?php
/**
 * Bulk_Actions — campaign-list bulk operations.
 *
 * Adds three new bulk actions to the wc-donation campaign list:
 * - "Apply template: <name>" (one entry per existing template)
 * - "Reset Companion settings" (clears all companion meta on selected campaigns)
 * - "Detach from template" (snapshots resolved config into overrides)
 *
 * Per-campaign capability check: even with manage_woocommerce, the bulk
 * applier skips campaigns the user can't edit_post. Surfaces an admin
 * notice with the count applied vs skipped.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Campaign_Config_Repository;
use DFWC\Companion\Config\Template_Repository;
use DFWC\Companion\I18n\WPML_Strings;

final class Bulk_Actions {

	private const ACTION_PREFIX_APPLY = 'dfwc_apply_template_';
	private const ACTION_RESET        = 'dfwc_reset_companion';
	private const ACTION_DETACH       = 'dfwc_detach_template';
	private const QUERY_FLAG          = 'dfwc_bulk_done';

	public function __construct() {
		add_filter( 'bulk_actions-edit-wc-donation', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-wc-donation', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'admin_notice_after_bulk' ) );
	}

	/**
	 * @param array<string,string> $bulk_actions
	 * @return array<string,string>
	 */
	public function register_bulk_actions( array $bulk_actions ): array {
		// "Apply template: <name>" entries — one per existing template.
		$repo = new Template_Repository();
		foreach ( $repo->all() as $template ) {
			$bulk_actions[ self::ACTION_PREFIX_APPLY . $template->id ] = sprintf(
				/* translators: %s: template name */
				__( 'Apply template: %s', 'dfwc-companion' ),
				WPML_Strings::translate( $template->name, $template->id . '.name' )
			);
		}

		$bulk_actions[ self::ACTION_RESET ]  = __( 'Reset Companion settings', 'dfwc-companion' );
		$bulk_actions[ self::ACTION_DETACH ] = __( 'Detach from template', 'dfwc-companion' );

		return $bulk_actions;
	}

	/**
	 * Handle a bulk action submission. WP wraps this with its own
	 * bulk-action nonce check, so we just verify capability per-campaign.
	 *
	 * @param string       $redirect_to
	 * @param string       $action
	 * @param array<int,int> $post_ids
	 */
	public function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
		if ( ! self::is_companion_action( $action ) ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$campaign_repo = new Campaign_Config_Repository();
		$applied       = 0;
		$skipped       = 0;

		// Apply-template branch: parse template_id from action.
		if ( 0 === strpos( $action, self::ACTION_PREFIX_APPLY ) ) {
			$template_id = substr( $action, strlen( self::ACTION_PREFIX_APPLY ) );
			$template_id = sanitize_key( $template_id );

			$template_repo = new Template_Repository();
			if ( ! $template_repo->exists( $template_id ) ) {
				// Stale action (template deleted between page render and submit).
				return $redirect_to;
			}

			foreach ( $post_ids as $pid ) {
				$pid = absint( $pid );
				if ( ! current_user_can( 'edit_post', $pid ) ) {
					++$skipped;
					continue;
				}
				if ( $campaign_repo->apply_template( $pid, $template_id ) ) {
					++$applied;
				} else {
					++$skipped;
				}
			}
		} elseif ( self::ACTION_RESET === $action ) {
			foreach ( $post_ids as $pid ) {
				$pid = absint( $pid );
				if ( ! current_user_can( 'edit_post', $pid ) ) {
					++$skipped;
					continue;
				}
				$campaign_repo->reset_to_defaults( $pid );
				++$applied;
			}
		} elseif ( self::ACTION_DETACH === $action ) {
			foreach ( $post_ids as $pid ) {
				$pid = absint( $pid );
				if ( ! current_user_can( 'edit_post', $pid ) ) {
					++$skipped;
					continue;
				}
				$campaign_repo->detach_from_template( $pid );
				++$applied;
			}
		}

		return add_query_arg(
			array(
				self::QUERY_FLAG => 1,
				'dfwc_action'    => rawurlencode( $action ),
				'dfwc_applied'   => $applied,
				'dfwc_skipped'   => $skipped,
			),
			$redirect_to
		);
	}

	/**
	 * Admin notice surfaced after a bulk operation completes.
	 */
	public function admin_notice_after_bulk(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash from server-side redirect
		if ( empty( $_GET[ self::QUERY_FLAG ] ) ) {
			return;
		}
		$applied = isset( $_GET['dfwc_applied'] ) ? (int) $_GET['dfwc_applied'] : 0;
		$skipped = isset( $_GET['dfwc_skipped'] ) ? (int) $_GET['dfwc_skipped'] : 0;
		// phpcs:enable

		if ( $applied < 1 && $skipped < 1 ) {
			return;
		}

		$class = $skipped > 0 ? 'notice-warning' : 'notice-success';
		$msg   = sprintf(
			/* translators: 1: count of campaigns updated, 2: count skipped */
			_n(
				'Companion bulk action: %1$d campaign updated.',
				'Companion bulk action: %1$d campaigns updated.',
				$applied,
				'dfwc-companion'
			),
			$applied,
			$skipped
		);

		if ( $skipped > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: count of campaigns skipped due to missing edit capability */
				_n(
					'%d campaign skipped (missing edit permission).',
					'%d campaigns skipped (missing edit permission).',
					$skipped,
					'dfwc-companion'
				),
				$skipped
			);
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $msg )
		);
	}

	private static function is_companion_action( string $action ): bool {
		if ( 0 === strpos( $action, self::ACTION_PREFIX_APPLY ) ) {
			return true;
		}
		return self::ACTION_RESET === $action || self::ACTION_DETACH === $action;
	}
}
