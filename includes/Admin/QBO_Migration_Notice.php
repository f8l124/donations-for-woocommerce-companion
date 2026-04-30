<?php
/**
 * QBO_Migration_Notice — one-time admin notice for the v2.0.x → v2.1.0
 * QBO sync extraction.
 *
 * Companion v2.0.x bundled QuickBooks Online sync. v2.1.0 moved that
 * functionality into a sibling plugin (`donations-for-woocommerce-qbo-sync`).
 * Sites upgrading without installing the sibling will silently stop syncing
 * — this notice surfaces the situation so admins can install the sibling
 * and resume sync.
 *
 * Surfaces ONLY when:
 *   1. The companion-era QBO option `dfwc_qbo_oauth_tokens` exists (means
 *      the admin had QBO connected on v2.0.x), AND
 *   2. The new sibling plugin isn't loaded (`DFWC\\QboSync\\Plugin` class missing).
 *
 * Dismissible. Auto-disappears once the sibling plugin is detected.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

final class QBO_Migration_Notice {

	private const DISMISS_OPTION = 'dfwc_qbo_migration_notice_dismissed';
	private const DISMISS_ACTION = 'dfwc_qbo_migration_notice_dismiss';

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	public function maybe_render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			return;
		}
		if ( get_option( self::DISMISS_OPTION, '' ) ) {
			return;
		}
		// Sibling plugin already active → nothing to surface.
		if ( class_exists( '\\DFWC\\QboSync\\Plugin' ) ) {
			return;
		}
		// Companion-era QBO tokens present → admin had QBO connected before the upgrade.
		if ( ! get_option( 'dfwc_qbo_oauth_tokens', false ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => self::DISMISS_ACTION ),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'QuickBooks sync moved to a separate plugin', 'dfwc-companion' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'In v2.1.0, QuickBooks Online sync was extracted to a sibling plugin so the companion stays focused on donor-form UX. Your existing tokens, account mapping, and sync log are preserved — install the sibling plugin to keep syncing without reconnecting.', 'dfwc-companion' ); ?>
			</p>
			<p>
				<a href="https://github.com/f8l124/donations-for-woocommerce-qbo-sync/releases" target="_blank" rel="noopener" class="button button-primary">
					<?php esc_html_e( 'Download QuickBooks Sync sibling plugin', 'dfwc-companion' ); ?>
				</a>
				&nbsp;
				<a href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss this notice', 'dfwc-companion' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function handle_dismiss(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		update_option( self::DISMISS_OPTION, time(), false );
		$referer = wp_get_referer();
		wp_safe_redirect( false === $referer ? admin_url() : $referer );
		exit;
	}
}
