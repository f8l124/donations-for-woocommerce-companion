<?php
/**
 * QuickBooks_Page — admin UI for the QBO sync feature.
 *
 * Renders three subviews:
 *   - **Status panel** — Connect / Disconnect / connected company name +
 *     realm + last-sync stats. Drives the OAuth flow via redirect to
 *     `OAuth_Client::authorize_url()`.
 *   - **Account mapping table** — campaign → income account dropdowns,
 *     plus a `_default` row for the global fallback. Populates dropdowns
 *     by calling `API_Client::list_income_accounts()` (per-request cache).
 *   - **Sync log** — last 50 sync attempts (from `Sync_Queue::get_log()`).
 *
 * Three admin-post.php actions:
 *   - dfwc_qbo_connect       — kicks the OAuth flow
 *   - dfwc_qbo_disconnect    — calls OAuth_Client::disconnect, redirects with flash
 *   - dfwc_qbo_save_mapping  — persists the campaign → account map
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\QuickBooks\Account_Mapper;
use DFWC\Companion\QuickBooks\API_Client;
use DFWC\Companion\QuickBooks\OAuth_Client;
use DFWC\Companion\QuickBooks\Sync_Queue;
use DFWC\Companion\QuickBooks\Token_Store;

final class QuickBooks_Page {

	public const CONNECT_ACTION    = 'dfwc_qbo_connect';
	public const DISCONNECT_ACTION = 'dfwc_qbo_disconnect';
	public const MAPPING_ACTION    = 'dfwc_qbo_save_mapping';
	public const NONCE             = '_dfwc_qbo_nonce';

	public function __construct() {
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::MAPPING_ACTION, array( $this, 'handle_save_mapping' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}

		$connected   = Token_Store::has_tokens();
		$company     = Token_Store::company_name();
		$realm_id    = Token_Store::realm_id();
		$connected_at = Token_Store::connected_at();

		// Income accounts dropdown — only attempt the API call when connected.
		$accounts = array();
		if ( $connected ) {
			$result = API_Client::list_income_accounts();
			if ( ! is_wp_error( $result ) ) {
				$accounts = $result;
			}
		}

		$mapping  = Account_Mapper::get_all();
		$campaigns = self::list_campaigns();
		$log      = Sync_Queue::get_log();
		$flash    = self::flash_message();

		include DFWC_COMPANION_PATH . 'templates/quickbooks-page.php';
	}

	public function handle_connect(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::CONNECT_ACTION, self::NONCE );

		$url = OAuth_Client::authorize_url();
		if ( '' === $url ) {
			$this->redirect_with_flash(
				'error',
				__( 'Set the QuickBooks client ID + secret in Settings → QuickBooks Sync first.', 'dfwc-companion' )
			);
			return;
		}
		wp_redirect( $url );
		exit;
	}

	public function handle_disconnect(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::DISCONNECT_ACTION, self::NONCE );

		OAuth_Client::disconnect();
		Sync_Queue::reset();

		$this->redirect_with_flash(
			'updated',
			__( 'Disconnected from QuickBooks. Tokens cleared and sync queue reset.', 'dfwc-companion' )
		);
	}

	public function handle_save_mapping(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::MAPPING_ACTION, self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above
		$raw = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] )
			? wp_unslash( $_POST['mapping'] )
			: array();
		// phpcs:enable

		$clean = array();
		foreach ( $raw as $key => $value ) {
			$clean[ (string) $key ] = (string) $value;
		}
		Account_Mapper::save( $clean );

		$this->redirect_with_flash(
			'updated',
			__( 'Account mapping saved.', 'dfwc-companion' )
		);
	}

	private function redirect_with_flash( string $type, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array( $type => rawurlencode( $message ) ),
				admin_url( 'admin.php?page=' . Admin_Menu::QBO_PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * @return array<int,\WP_Post>
	 */
	private static function list_campaigns(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'wc-donation',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * @return array{type:?string,message:string,oauth_status:string}
	 */
	private static function flash_message(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash
		$out = array(
			'type'         => null,
			'message'      => '',
			'oauth_status' => '',
		);
		if ( isset( $_GET['updated'] ) ) {
			$out['type']    = 'updated';
			$out['message'] = sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['updated'] ) ) );
		} elseif ( isset( $_GET['error'] ) ) {
			$out['type']    = 'error';
			$out['message'] = sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['error'] ) ) );
		} elseif ( isset( $_GET['qbo_status'], $_GET['qbo_msg'] ) ) {
			$status = sanitize_key( (string) wp_unslash( $_GET['qbo_status'] ) );
			$out['oauth_status'] = $status;
			$out['type']         = 'connected' === $status ? 'updated' : 'error';
			$out['message']      = sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['qbo_msg'] ) ) );
		}
		// phpcs:enable
		return $out;
	}
}
