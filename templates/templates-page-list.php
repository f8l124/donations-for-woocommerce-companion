<?php
/**
 * Templates list view (admin).
 *
 * Variables in scope (from Templates_Page::render_list):
 *   array<string,Template_Config> $templates
 *   array{type:?string,message:string} $flash
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Admin\Templates_Page;
use DFWC\Companion\Config\Template_Repository;
use DFWC\Companion\I18n\WPML_Strings;
?>
<div class="wrap dfwc-templates-list">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Donation Templates', 'dfwc-companion' ); ?>
	</h1>
	<a href="
	<?php
	echo esc_url(
		add_query_arg(
			array(
				'page' => Admin_Menu::TEMPLATES_SLUG,
				'action' => 'new',
			),
			admin_url( 'admin.php' )
		)
	);
	?>
	" class="page-title-action">
		<?php esc_html_e( 'Add new', 'dfwc-companion' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( $flash['type'] ) : ?>
		<div class="notice notice-<?php echo 'updated' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $templates ) ) : ?>
		<div class="notice notice-info inline" style="margin-top:16px">
			<p>
				<?php esc_html_e( 'No templates yet. Templates let you configure preset amounts, intervals, and impact labels once and apply to many campaigns at a time.', 'dfwc-companion' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => Admin_Menu::TEMPLATES_SLUG,
							'action' => 'new',
						),
						admin_url( 'admin.php' )
					)
				);
				?>
														">
					<?php esc_html_e( 'Create your first template', 'dfwc-companion' ); ?>
				</a>
			</p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'dfwc-companion' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'dfwc-companion' ); ?></th>
					<th scope="col" style="width:120px"><?php esc_html_e( 'Campaigns using', 'dfwc-companion' ); ?></th>
					<th scope="col" style="width:140px"><?php esc_html_e( 'Last modified', 'dfwc-companion' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$repo = new Template_Repository();
				foreach ( $templates as $tpl ) :
					$campaigns_using = count( $repo->campaign_ids_using( $tpl->id ) );
					$edit_url        = add_query_arg(
						array(
							'page'   => Admin_Menu::TEMPLATES_SLUG,
							'action' => 'edit',
							'id'     => $tpl->id,
						),
						admin_url( 'admin.php' )
					);
					$delete_url      = Templates_Page::delete_url( $tpl->id );
					$translated_name = WPML_Strings::translate( $tpl->name, $tpl->id . '.name' );
					$translated_desc = WPML_Strings::translate( $tpl->description, $tpl->id . '.description' );
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $translated_name ); ?></a>
							</strong>
							<div class="row-actions">
								<span class="edit">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'dfwc-companion' ); ?></a> |
								</span>
								<span class="duplicate">
									<a href="<?php echo esc_url( Templates_Page::duplicate_url( $tpl->id ) ); ?>"><?php esc_html_e( 'Duplicate', 'dfwc-companion' ); ?></a> |
								</span>
								<span class="delete">
									<a
										href="<?php echo esc_url( $delete_url ); ?>"
										onclick="return confirm('
										<?php
											echo esc_js(
												sprintf(
													/* translators: 1: template name, 2: count of campaigns using this template */
													_n(
														'Delete the "%1$s" template? %2$d campaign currently uses it and will fall back to the global defaults.',
														'Delete the "%1$s" template? %2$d campaigns currently use it and will fall back to the global defaults.',
														$campaigns_using,
														'dfwc-companion'
													),
													$translated_name,
													$campaigns_using
												)
											);
										?>
										');"
										class="submitdelete"
									>
										<?php esc_html_e( 'Delete', 'dfwc-companion' ); ?>
									</a>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( $translated_desc ); ?></td>
						<td><?php echo (int) $campaigns_using; ?></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time, e.g. "5 minutes ago" */
									__( '%s ago', 'dfwc-companion' ),
									human_time_diff( $tpl->modified_at )
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
