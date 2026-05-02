<?php
/**
 * Template — Crypto Donations admin settings page.
 *
 * Rendered by DFWC\Companion\Admin\Crypto_Settings_Page::render(). Variables
 * available in scope: none (template fetches state directly via the
 * settings classes for self-containment).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

$store        = new \DFWC\Companion\Gateways\TGB_Token_Store();
$is_connected = $store->is_configured();
$updated_at   = $store->updated_at();
$test_result  = get_transient( 'dfwc_tgb_test_result' );
if ( $test_result ) {
	delete_transient( 'dfwc_tgb_test_result' );
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Crypto Donations', 'dfwc-companion' ); ?></h1>
	<p>
		<?php esc_html_e( 'Accept cryptocurrency donations through The Giving Block. Donors complete the donation flow inside The Giving Block widget; The Giving Block handles wallet UX, KYC/AML, and on-chain confirmation. Companion records the order and fires the dfwc_companion_donation_submitted hook on confirmation.', 'dfwc-companion' ); ?>
	</p>

	<?php if ( is_array( $test_result ) ) : ?>
		<div class="notice notice-<?php echo $test_result['ok'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( (string) $test_result['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $is_connected && $updated_at > 0 ) : ?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Status:', 'dfwc-companion' ); ?></strong>
				<?php
				printf(
					/* translators: %s: human-readable time-ago string (e.g., "2 hours ago") */
					esc_html__( 'Credentials stored %s.', 'dfwc-companion' ),
					esc_html( human_time_diff( $updated_at, time() ) . ' ' . __( 'ago', 'dfwc-companion' ) )
				);
				?>
				<?php esc_html_e( 'Click "Test connection" to verify.', 'dfwc-companion' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<form action="options.php" method="post">
		<?php
		settings_fields( \DFWC\Companion\Admin\Crypto_Settings_Page::OPTION_GROUP );
		do_settings_sections( \DFWC\Companion\Admin\Crypto_Settings_Page::PAGE_SLUG );
		submit_button();
		?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Connection actions', 'dfwc-companion' ); ?></h2>
	<p>
		<?php esc_html_e( 'After saving credentials above, use these actions to verify the connection or disconnect.', 'dfwc-companion' ); ?>
	</p>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block; margin-right:10px;">
		<?php wp_nonce_field( \DFWC\Companion\Admin\Crypto_Settings_Page::TEST_ACTION ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( \DFWC\Companion\Admin\Crypto_Settings_Page::TEST_ACTION ); ?>">
		<button type="submit" class="button button-secondary" <?php disabled( ! $is_connected ); ?>>
			<?php esc_html_e( 'Test connection', 'dfwc-companion' ); ?>
		</button>
	</form>

	<?php if ( $is_connected ) : ?>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect TGB and clear stored credentials? Existing pending crypto orders will not be affected.', 'dfwc-companion' ) ); ?>');">
		<?php wp_nonce_field( \DFWC\Companion\Admin\Crypto_Settings_Page::DISCONNECT_ACTION ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( \DFWC\Companion\Admin\Crypto_Settings_Page::DISCONNECT_ACTION ); ?>">
		<button type="submit" class="button button-link-delete">
			<?php esc_html_e( 'Disconnect', 'dfwc-companion' ); ?>
		</button>
	</form>
	<?php endif; ?>
</div>
