<?php
/**
 * Stock pledges — edit / reconciliation view.
 *
 * Variables in scope (from Stock_Pledges_Page::render_edit):
 *   array $pledge  Full pledge data from Stock_Pledge_Handler::get_pledge
 *   array $flash   ['type' => ..., 'message' => ...]
 *
 * Two forms on this page:
 * 1. Update / status transition (pledged → in_transit | cancelled, plus admin notes)
 * 2. Mark received (separate form to record actual transfer value + date; fires Phase 9 hook)
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Admin\Stock_Pledges_Page;
use DFWC\Companion\Stock\Stock_Pledge_Post_Type;

$campaign_title = function_exists( 'get_the_title' )
	? (string) get_the_title( $pledge['campaign_id'] )
	: '';
$shares_formatted = rtrim( rtrim( number_format( (float) $pledge['shares'], 4, '.', '' ), '0' ), '.' );
$is_received      = Stock_Pledge_Post_Type::STATUS_RECEIVED === $pledge['status'];
$is_cancelled     = Stock_Pledge_Post_Type::STATUS_CANCELLED === $pledge['status'];
?>
<div class="wrap dfwc-stock-pledge-edit">
	<h1 class="wp-heading-inline">
		<?php
		printf(
			/* translators: %d: pledge ID */
			esc_html__( 'Stock Pledge #%d', 'dfwc-companion' ),
			(int) $pledge['id']
		);
		?>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::STOCK_PLEDGES_SLUG ) ); ?>" class="page-title-action">
		<?php esc_html_e( '← All pledges', 'dfwc-companion' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( $flash['type'] ) : ?>
		<div class="notice notice-<?php echo 'updated' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-top:16px;">
		<div>
			<h2><?php esc_html_e( 'Pledge details', 'dfwc-companion' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Status', 'dfwc-companion' ); ?></th>
					<td>
						<span class="dfwc-pledge-status dfwc-pledge-status--<?php echo esc_attr( $pledge['status'] ); ?>">
							<?php echo esc_html( Stock_Pledge_Post_Type::status_label( $pledge['status'] ) ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Campaign', 'dfwc-companion' ); ?></th>
					<td>
						<?php
						$edit_campaign_url = function_exists( 'get_edit_post_link' )
							? (string) get_edit_post_link( $pledge['campaign_id'] )
							: '';
						if ( '' !== $edit_campaign_url ) :
							?>
							<a href="<?php echo esc_url( $edit_campaign_url ); ?>"><?php echo esc_html( $campaign_title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $campaign_title ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Donor', 'dfwc-companion' ); ?></th>
					<td>
						<strong><?php echo esc_html( $pledge['donor_name'] ); ?></strong><br>
						<a href="mailto:<?php echo esc_attr( $pledge['donor_email'] ); ?>"><?php echo esc_html( $pledge['donor_email'] ); ?></a>
						<?php if ( '' !== $pledge['donor_phone'] ) : ?>
							<br><?php echo esc_html( $pledge['donor_phone'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Stock', 'dfwc-companion' ); ?></th>
					<td>
						<strong><?php echo esc_html( $pledge['ticker'] ); ?></strong>
						× <?php echo esc_html( $shares_formatted ); ?>
						<?php esc_html_e( 'shares', 'dfwc-companion' ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Donor\'s broker', 'dfwc-companion' ); ?></th>
					<td><?php echo esc_html( $pledge['broker_name'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Estimated value', 'dfwc-companion' ); ?></th>
					<td>
						<?php
						$ev = function_exists( 'wc_price' )
							? wp_kses_post( wc_price( (float) $pledge['estimated_value'] ) )
							: '$' . number_format( (float) $pledge['estimated_value'], 2 );
						echo $ev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price already escaped
						?>
					</td>
				</tr>
				<?php if ( $pledge['actual_value'] > 0 ) : ?>
				<tr>
					<th><?php esc_html_e( 'Actual value', 'dfwc-companion' ); ?></th>
					<td>
						<?php
						$av = function_exists( 'wc_price' )
							? wp_kses_post( wc_price( (float) $pledge['actual_value'] ) )
							: '$' . number_format( (float) $pledge['actual_value'], 2 );
						echo $av; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price already escaped
						?>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( $pledge['received_at'] > 0 ) : ?>
				<tr>
					<th><?php esc_html_e( 'Received on', 'dfwc-companion' ); ?></th>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $pledge['received_at'] ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( '' !== $pledge['donor_notes'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'Donor notes', 'dfwc-companion' ); ?></th>
					<td><?php echo nl2br( esc_html( $pledge['donor_notes'] ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
		</div>

		<div>
			<?php if ( ! $is_received && ! $is_cancelled ) : ?>
				<div class="postbox" style="padding:16px;">
					<h3><?php esc_html_e( 'Mark received', 'dfwc-companion' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Once shares clear in your brokerage account, record the actual transfer value to fire the donation hook.', 'dfwc-companion' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Stock_Pledges_Page::RECEIVE_ACTION ); ?>">
						<input type="hidden" name="pledge_id" value="<?php echo (int) $pledge['id']; ?>">
						<?php wp_nonce_field( Stock_Pledges_Page::RECEIVE_ACTION, Stock_Pledges_Page::SAVE_NONCE ); ?>
						<p>
							<label for="dfwc-actual-value"><?php esc_html_e( 'Actual transfer value', 'dfwc-companion' ); ?></label><br>
							<input type="number" step="0.01" min="0.01" id="dfwc-actual-value" name="actual_value" required class="regular-text" placeholder="<?php echo esc_attr( (string) $pledge['estimated_value'] ); ?>">
						</p>
						<p>
							<label for="dfwc-received-at"><?php esc_html_e( 'Received on', 'dfwc-companion' ); ?></label><br>
							<input type="date" id="dfwc-received-at" name="received_at" required class="regular-text" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
						</p>
						<p>
							<label for="dfwc-admin-notes-receive"><?php esc_html_e( 'Notes', 'dfwc-companion' ); ?></label><br>
							<textarea id="dfwc-admin-notes-receive" name="admin_notes" rows="3" class="large-text"><?php echo esc_textarea( $pledge['admin_notes'] ); ?></textarea>
						</p>
						<?php submit_button( __( 'Mark as received', 'dfwc-companion' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<div class="postbox" style="padding:16px;margin-top:16px;">
				<h3><?php esc_html_e( 'Update status', 'dfwc-companion' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Stock_Pledges_Page::SAVE_ACTION ); ?>">
					<input type="hidden" name="pledge_id" value="<?php echo (int) $pledge['id']; ?>">
					<?php wp_nonce_field( Stock_Pledges_Page::SAVE_ACTION, Stock_Pledges_Page::SAVE_NONCE ); ?>
					<p>
						<label for="dfwc-pledge-status"><?php esc_html_e( 'Status', 'dfwc-companion' ); ?></label><br>
						<select id="dfwc-pledge-status" name="status">
							<?php
							foreach ( Stock_Pledge_Post_Type::statuses() as $pledge_status ) :
								// Skip "received" — that has its own form so the hook fires.
								if ( Stock_Pledge_Post_Type::STATUS_RECEIVED === $pledge_status ) {
									continue; }
								?>
								<option value="<?php echo esc_attr( $pledge_status ); ?>" <?php selected( $pledge['status'], $pledge_status ); ?>>
									<?php echo esc_html( Stock_Pledge_Post_Type::status_label( $pledge_status ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="dfwc-admin-notes"><?php esc_html_e( 'Admin notes', 'dfwc-companion' ); ?></label><br>
						<textarea id="dfwc-admin-notes" name="admin_notes" rows="3" class="large-text"><?php echo esc_textarea( $pledge['admin_notes'] ); ?></textarea>
					</p>
					<?php submit_button( __( 'Update pledge', 'dfwc-companion' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
	</div>
</div>
<style>
.dfwc-pledge-status {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 3px;
	font-size: 13px;
	font-weight: 600;
}
.dfwc-pledge-status--pledged    { background: #fef3c7; color: #92400e; }
.dfwc-pledge-status--in_transit { background: #dbeafe; color: #1e40af; }
.dfwc-pledge-status--received   { background: #d1fae5; color: #065f46; }
.dfwc-pledge-status--cancelled  { background: #fee2e2; color: #991b1b; }
</style>
