<?php
/**
 * Stock pledges list view.
 *
 * Variables in scope (from Stock_Pledges_Page::render_list):
 *   WP_Post[] $pledges        Posts of type dfwc_stock_pledge
 *   string    $status_filter  Active status filter from $_GET (or '')
 *   array     $flash          ['type' => ..., 'message' => ...]
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Stock\Stock_Pledge_Handler;
use DFWC\Companion\Stock\Stock_Pledge_Post_Type;
?>
<div class="wrap dfwc-stock-pledges">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Stock Pledges', 'dfwc-companion' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( $flash['type'] ) : ?>
		<div class="notice notice-<?php echo 'updated' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::STOCK_PLEDGES_SLUG ) ); ?>"
			   class="<?php echo '' === $status_filter ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'dfwc-companion' ); ?>
			</a> |
		</li>
		<?php
		$all_statuses = Stock_Pledge_Post_Type::statuses();
		$last_index   = count( $all_statuses ) - 1;
		foreach ( $all_statuses as $i => $pledge_status ) :
			$url = add_query_arg(
				array( 'status' => $pledge_status ),
				admin_url( 'admin.php?page=' . Admin_Menu::STOCK_PLEDGES_SLUG )
			);
			$is_last = $last_index === $i;
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>"
				   class="<?php echo $status_filter === $pledge_status ? 'current' : ''; ?>">
					<?php echo esc_html( Stock_Pledge_Post_Type::status_label( $pledge_status ) ); ?>
				</a><?php echo $is_last ? '' : ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Pledge', 'dfwc-companion' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Donor', 'dfwc-companion' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Stock', 'dfwc-companion' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Estimated value', 'dfwc-companion' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Campaign', 'dfwc-companion' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'dfwc-companion' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'dfwc-companion' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $pledges ) ) : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No stock pledges yet.', 'dfwc-companion' ); ?></td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $pledges as $pledge_post ) :
					$pledge   = Stock_Pledge_Handler::get_pledge( (int) $pledge_post->ID );
					if ( null === $pledge ) {
						continue; }
					$edit_url = add_query_arg(
						array(
							'action' => 'edit',
							'id' => $pledge['id'],
						),
						admin_url( 'admin.php?page=' . Admin_Menu::STOCK_PLEDGES_SLUG )
					);
					$status_label = Stock_Pledge_Post_Type::status_label( $pledge['status'] );
					$value_display = function_exists( 'wc_price' )
						? wp_strip_all_tags( wc_price( (float) $pledge['estimated_value'] ) )
						: '$' . number_format( (float) $pledge['estimated_value'], 2 );
					$shares_formatted = rtrim( rtrim( number_format( (float) $pledge['shares'], 4, '.', '' ), '0' ), '.' );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="row-title">
								#<?php echo (int) $pledge['id']; ?>
							</a>
						</td>
						<td>
							<?php echo esc_html( $pledge['donor_name'] ); ?><br>
							<a href="mailto:<?php echo esc_attr( $pledge['donor_email'] ); ?>"><?php echo esc_html( $pledge['donor_email'] ); ?></a>
						</td>
						<td>
							<strong><?php echo esc_html( $pledge['ticker'] ); ?></strong> ×
							<?php echo esc_html( $shares_formatted ); ?>
						</td>
						<td><?php echo esc_html( $value_display ); ?></td>
						<td>
							<?php
							$campaign_title = function_exists( 'get_the_title' )
								? (string) get_the_title( $pledge['campaign_id'] )
								: '';
							echo esc_html( $campaign_title );
							?>
						</td>
						<td>
							<span class="dfwc-pledge-status dfwc-pledge-status--<?php echo esc_attr( $pledge['status'] ); ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
						</td>
						<td>
							<?php
							echo esc_html(
								$pledge['created_at'] > 0
									? date_i18n( get_option( 'date_format' ), $pledge['created_at'] )
									: ''
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
<style>
.dfwc-pledge-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.dfwc-pledge-status--pledged    { background: #fef3c7; color: #92400e; }
.dfwc-pledge-status--in_transit { background: #dbeafe; color: #1e40af; }
.dfwc-pledge-status--received   { background: #d1fae5; color: #065f46; }
.dfwc-pledge-status--cancelled  { background: #fee2e2; color: #991b1b; }
</style>
