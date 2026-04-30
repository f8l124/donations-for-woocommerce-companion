<?php
/**
 * QuickBooks Sync admin page template.
 *
 * Variables in scope (from QuickBooks_Page::render):
 *   bool   $connected    Token_Store::has_tokens()
 *   string $company      connected company display name
 *   string $realm_id
 *   int    $connected_at unix timestamp
 *   array<string,string> $accounts  QBO income accounts ([id => name])
 *   array<string,string> $mapping   campaign_id => account_id (plus '_default')
 *   WP_Post[] $campaigns            list of wc-donation posts
 *   array $log                      Sync_Queue::get_log()
 *   array{type:?string,message:string,oauth_status:string} $flash
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Admin\QuickBooks_Page;
use DFWC\Companion\QuickBooks\Account_Mapper;
use DFWC\Companion\QuickBooks\OAuth_Client;
?>
<div class="wrap dfwc-qbo-page">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'QuickBooks Sync', 'dfwc-companion' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( null !== $flash['type'] ) : ?>
		<div class="notice notice-<?php echo 'updated' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="dfwc-qbo-grid" style="display:grid;grid-template-columns:1fr;gap:24px;margin-top:16px;">
		<div class="postbox" style="padding:16px;">
			<h2><?php esc_html_e( 'Connection', 'dfwc-companion' ); ?></h2>
			<?php if ( $connected ) : ?>
				<p>
					<strong><?php esc_html_e( 'Connected:', 'dfwc-companion' ); ?></strong>
					<?php echo esc_html( '' !== $company ? $company : __( '(company name unavailable)', 'dfwc-companion' ) ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Realm ID:', 'dfwc-companion' ); ?></strong>
					<code><?php echo esc_html( $realm_id ); ?></code>
				</p>
				<?php if ( $connected_at > 0 ) : ?>
					<p>
						<strong><?php esc_html_e( 'Connected at:', 'dfwc-companion' ); ?></strong>
						<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $connected_at ) ); ?>
					</p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="<?php echo esc_attr( QuickBooks_Page::DISCONNECT_ACTION ); ?>">
					<?php wp_nonce_field( QuickBooks_Page::DISCONNECT_ACTION, QuickBooks_Page::NONCE ); ?>
					<?php submit_button( __( 'Disconnect from QuickBooks', 'dfwc-companion' ), 'delete', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Not connected. Configure your Intuit app credentials in Settings → QuickBooks Sync, then click Connect below.', 'dfwc-companion' ); ?>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: redirect URI to register at developer.intuit.com */
						esc_html__( 'Redirect URI to register in your Intuit app: %s', 'dfwc-companion' ),
						'<code>' . esc_html( OAuth_Client::redirect_uri() ) . '</code>'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( QuickBooks_Page::CONNECT_ACTION ); ?>">
					<?php wp_nonce_field( QuickBooks_Page::CONNECT_ACTION, QuickBooks_Page::NONCE ); ?>
					<?php submit_button( __( 'Connect to QuickBooks', 'dfwc-companion' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>

		<?php if ( $connected ) : ?>
			<div class="postbox" style="padding:16px;">
				<h2><?php esc_html_e( 'Account mapping', 'dfwc-companion' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Map each campaign to an Income Account in QuickBooks. Unmapped campaigns fall through to the default account.', 'dfwc-companion' ); ?>
				</p>
				<?php if ( empty( $accounts ) ) : ?>
					<p class="notice notice-warning" style="padding:8px;">
						<?php esc_html_e( 'No active Income accounts found in this QuickBooks company. Create at least one Income account in QuickBooks, then refresh this page.', 'dfwc-companion' ); ?>
					</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( QuickBooks_Page::MAPPING_ACTION ); ?>">
						<?php wp_nonce_field( QuickBooks_Page::MAPPING_ACTION, QuickBooks_Page::NONCE ); ?>

						<table class="wp-list-table widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Campaign', 'dfwc-companion' ); ?></th>
									<th scope="col"><?php esc_html_e( 'QuickBooks income account', 'dfwc-companion' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'Default (fallback)', 'dfwc-companion' ); ?></strong></td>
									<td>
										<?php
										$default_value = isset( $mapping[ Account_Mapper::DEFAULT_KEY ] )
											? (string) $mapping[ Account_Mapper::DEFAULT_KEY ]
											: '';
										?>
										<select name="mapping[<?php echo esc_attr( Account_Mapper::DEFAULT_KEY ); ?>]">
											<option value=""><?php esc_html_e( '— None —', 'dfwc-companion' ); ?></option>
											<?php foreach ( $accounts as $acc_id => $acc_name ) : ?>
												<option value="<?php echo esc_attr( $acc_id ); ?>" <?php selected( $default_value, $acc_id ); ?>>
													<?php echo esc_html( $acc_name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<?php foreach ( $campaigns as $campaign ) : ?>
									<?php
									$key   = (string) $campaign->ID;
									$value = isset( $mapping[ $key ] ) ? (string) $mapping[ $key ] : '';
									?>
									<tr>
										<td>
											<?php echo esc_html( $campaign->post_title ); ?>
											<small style="color:#666;">(#<?php echo (int) $campaign->ID; ?>)</small>
										</td>
										<td>
											<select name="mapping[<?php echo esc_attr( $key ); ?>]">
												<option value=""><?php esc_html_e( '— Use default —', 'dfwc-companion' ); ?></option>
												<?php foreach ( $accounts as $acc_id => $acc_name ) : ?>
													<option value="<?php echo esc_attr( $acc_id ); ?>" <?php selected( $value, $acc_id ); ?>>
														<?php echo esc_html( $acc_name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php submit_button( __( 'Save mapping', 'dfwc-companion' ) ); ?>
					</form>
				<?php endif; ?>
			</div>

			<div class="postbox" style="padding:16px;">
				<h2><?php esc_html_e( 'Recent sync activity', 'dfwc-companion' ); ?></h2>
				<?php if ( empty( $log ) ) : ?>
					<p><?php esc_html_e( 'No sync activity yet. Run a test donation to see entries appear here.', 'dfwc-companion' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" style="width:140px;"><?php esc_html_e( 'When', 'dfwc-companion' ); ?></th>
								<th scope="col" style="width:90px;"><?php esc_html_e( 'Status', 'dfwc-companion' ); ?></th>
								<th scope="col" style="width:80px;"><?php esc_html_e( 'Attempt', 'dfwc-companion' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'dfwc-companion' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$reverse = array_reverse( $log );
							foreach ( $reverse as $entry ) :
								$entry_status = (string) ( $entry['status'] ?? '' );
								$pill_class   = 'success' === $entry_status ? 'updated' : ( 'failed' === $entry_status ? 'error' : 'warning' );
								?>
								<tr>
									<td>
										<?php
										echo esc_html(
											date_i18n(
												get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
												(int) ( $entry['ts'] ?? 0 )
											)
										);
										?>
									</td>
									<td>
										<span class="dfwc-qbo-status dfwc-qbo-status--<?php echo esc_attr( $pill_class ); ?>">
											<?php echo esc_html( $entry_status ); ?>
										</span>
									</td>
									<td><?php echo (int) ( $entry['attempt'] ?? 0 ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<style>
.dfwc-qbo-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}
.dfwc-qbo-status--updated { background: #d1fae5; color: #065f46; }
.dfwc-qbo-status--error   { background: #fee2e2; color: #991b1b; }
.dfwc-qbo-status--warning { background: #fef3c7; color: #92400e; }
</style>
