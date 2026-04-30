<?php
/**
 * Stock_Pledges_Page — admin reconciliation UI for `dfwc_stock_pledge` posts.
 *
 * Two modes:
 * - List view (default): table of all pledges with status, donor, ticker,
 *   shares, estimated value, campaign, created-at. Status filter dropdown.
 * - Edit view (`?action=edit&id=N`): form to update pledge metadata,
 *   transition status (pledged → in_transit → received | cancelled),
 *   record actual transfer value + timestamp + admin notes.
 *
 * Save handlers attach to admin-post.php actions:
 * - dfwc_stock_pledge_save     — generic update (status to in_transit / cancelled)
 * - dfwc_stock_pledge_receive  — mark received (separate action so the
 *                                 Phase 9 hook fires correctly)
 *
 * Capability + nonce checked on every handler. Stale ids redirect with
 * an error flash.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Stock\Stock_Pledge_Handler;
use DFWC\Companion\Stock\Stock_Pledge_Post_Type;

final class Stock_Pledges_Page {

	public const SAVE_ACTION    = 'dfwc_stock_pledge_save';
	public const RECEIVE_ACTION = 'dfwc_stock_pledge_receive';
	public const SAVE_NONCE     = '_dfwc_stock_pledge_nonce';

	public function __construct() {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::RECEIVE_ACTION, array( $this, 'handle_receive' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : 'list';
		// phpcs:enable

		if ( 'edit' === $action ) {
			self::render_edit();
			return;
		}

		self::render_list();
	}

	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable

		$query_args = array(
			'post_type'      => Stock_Pledge_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== $status_filter && in_array( $status_filter, Stock_Pledge_Post_Type::statuses(), true ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => Stock_Pledge_Post_Type::META_STATUS,
					'value' => $status_filter,
				),
			);
		}

		$pledges = function_exists( 'get_posts' ) ? get_posts( $query_args ) : array();
		$flash   = self::flash_message();

		include DFWC_COMPANION_PATH . 'templates/stock-pledges-list.php';
	}

	private static function render_edit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing
		$pledge_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable

		$pledge = $pledge_id > 0 ? Stock_Pledge_Handler::get_pledge( $pledge_id ) : null;
		if ( null === $pledge ) {
			wp_safe_redirect(
				add_query_arg(
					array( 'error' => rawurlencode( __( 'Stock pledge not found.', 'dfwc-companion' ) ) ),
					admin_url( 'admin.php?page=' . Admin_Menu::STOCK_PLEDGES_SLUG )
				)
			);
			exit;
		}

		$flash = self::flash_message();

		include DFWC_COMPANION_PATH . 'templates/stock-pledges-edit.php';
	}

	public function handle_save(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::SAVE_ACTION, self::SAVE_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above
		$pledge_id   = isset( $_POST['pledge_id'] ) ? absint( wp_unslash( $_POST['pledge_id'] ) ) : 0;
		$new_status  = isset( $_POST['status'] ) ? sanitize_key( (string) wp_unslash( $_POST['status'] ) ) : '';
		$admin_notes = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['admin_notes'] ) ) : '';
		// phpcs:enable

		if ( $pledge_id < 1 ) {
			$this->redirect_with_flash( 0, 'error', __( 'Invalid pledge id.', 'dfwc-companion' ) );
			return;
		}

		// Always store admin notes regardless of status transition.
		if ( '' !== $admin_notes ) {
			update_post_meta( $pledge_id, Stock_Pledge_Post_Type::META_ADMIN_NOTES, $admin_notes );
		}

		if ( '' !== $new_status && Stock_Pledge_Post_Type::STATUS_RECEIVED !== $new_status ) {
			$result = Stock_Pledge_Handler::transition_status( $pledge_id, $new_status );
			if ( $result instanceof \WP_Error ) {
				$this->redirect_with_flash( $pledge_id, 'error', $result->get_error_message() );
				return;
			}
		}

		$this->redirect_with_flash( $pledge_id, 'updated', __( 'Pledge updated.', 'dfwc-companion' ) );
	}

	public function handle_receive(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::RECEIVE_ACTION, self::SAVE_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above
		$pledge_id    = isset( $_POST['pledge_id'] ) ? absint( wp_unslash( $_POST['pledge_id'] ) ) : 0;
		$actual_value = isset( $_POST['actual_value'] ) ? (float) wp_unslash( (string) $_POST['actual_value'] ) : 0.0;
		$received_str = isset( $_POST['received_at'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['received_at'] ) ) : '';
		$admin_notes  = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['admin_notes'] ) ) : '';
		// phpcs:enable

		$received_at = '' !== $received_str ? (int) strtotime( $received_str ) : time();
		if ( $received_at < 1 ) {
			$received_at = time();
		}

		$result = Stock_Pledge_Handler::mark_received( $pledge_id, $actual_value, $received_at, $admin_notes );
		if ( $result instanceof \WP_Error ) {
			$this->redirect_with_flash( $pledge_id, 'error', $result->get_error_message() );
			return;
		}

		$this->redirect_with_flash( $pledge_id, 'updated', __( 'Pledge marked received. Donation hook fired.', 'dfwc-companion' ) );
	}

	private function redirect_with_flash( int $pledge_id, string $type, string $message ): void {
		$args = array( $type => rawurlencode( $message ) );
		if ( $pledge_id > 0 ) {
			$args['action'] = 'edit';
			$args['id']     = $pledge_id;
		}
		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url( 'admin.php?page=' . Admin_Menu::STOCK_PLEDGES_SLUG )
			)
		);
		exit;
	}

	/**
	 * @return array{type:?string,message:string}
	 */
	private static function flash_message(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash
		if ( isset( $_GET['updated'] ) ) {
			return array(
				'type'    => 'updated',
				'message' => sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['updated'] ) ) ),
			);
		}
		if ( isset( $_GET['error'] ) ) {
			return array(
				'type'    => 'error',
				'message' => sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['error'] ) ) ),
			);
		}
		// phpcs:enable
		return array(
			'type'    => null,
			'message' => '',
		);
	}
}
