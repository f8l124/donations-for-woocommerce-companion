<?php
/**
 * Template edit form (admin).
 *
 * Variables in scope (from Templates_Page::render_edit):
 *   Template_Config $tpl                    Template being edited (skeleton when new).
 *   bool            $is_new                 True when creating; false when editing.
 *   string          $id_in                  Original template ID from URL (for editing).
 *   array           $flash                  ['type' => ..., 'message' => ...]
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Admin\Templates_Page;
use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\I18n\WPML_Strings;

$intervals = Config_Resolver::intervals();

$interval_labels = array(
	Config_Resolver::INTERVAL_ONE_TIME => __( 'One-time', 'dfwc-companion' ),
	Config_Resolver::INTERVAL_MONTHLY  => __( 'Monthly', 'dfwc-companion' ),
	Config_Resolver::INTERVAL_ANNUAL   => __( 'Annually', 'dfwc-companion' ),
);

$page_title = $is_new
	? __( 'New Template', 'dfwc-companion' )
	: sprintf(
		/* translators: %s: template name */
		__( 'Edit Template — %s', 'dfwc-companion' ),
		WPML_Strings::translate( $tpl->name, $tpl->id . '.name' )
	);

$wpml_active = WPML_Strings::wpml_active();
?>
<div class="wrap dfwc-template-edit">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::TEMPLATES_SLUG ) ); ?>" class="page-title-action">
		<?php esc_html_e( '← Back to all templates', 'dfwc-companion' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( $flash['type'] ) : ?>
		<div class="notice notice-<?php echo 'updated' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Templates_Page::SAVE_ACTION ); ?>">
		<input type="hidden" name="template[id]" value="<?php echo esc_attr( $is_new ? '' : $tpl->id ); ?>">
		<?php wp_nonce_field( Templates_Page::SAVE_ACTION, Templates_Page::SAVE_NONCE ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dfwc-tpl-name"><?php esc_html_e( 'Name', 'dfwc-companion' ); ?></label></th>
				<td>
					<input
						type="text"
						class="regular-text"
						id="dfwc-tpl-name"
						name="template[name]"
						value="<?php echo esc_attr( $tpl->name ); ?>"
						required
						maxlength="120"
					>
					<p class="description">
						<?php esc_html_e( 'Used in admin lists and campaign meta-box selectors. Translatable via WPML String Translation.', 'dfwc-companion' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dfwc-tpl-description"><?php esc_html_e( 'Description', 'dfwc-companion' ); ?></label></th>
				<td>
					<textarea
						id="dfwc-tpl-description"
						name="template[description]"
						class="large-text"
						rows="3"
						maxlength="500"
					><?php echo esc_textarea( $tpl->description ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Optional. Helps admins remember what the template is for. Shown in the templates list.', 'dfwc-companion' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dfwc-tpl-default-interval"><?php esc_html_e( 'Default interval', 'dfwc-companion' ); ?></label></th>
				<td>
					<select id="dfwc-tpl-default-interval" name="template[config][default_interval]">
						<?php foreach ( $intervals as $key ) : ?>
							<option
								value="<?php echo esc_attr( $key ); ?>"
								<?php selected( ( $tpl->config['default_interval'] ?? 'one_time' ), $key ); ?>
							>
								<?php echo esc_html( $interval_labels[ $key ] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Interval pre-selected for donors when the form first renders.', 'dfwc-companion' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php
		foreach ( $intervals as $interval_key ) :
			$block = $tpl->config[ $interval_key ] ?? array();
			?>
			<h2 class="dfwc-tpl-section-h"><?php echo esc_html( $interval_labels[ $interval_key ] ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'dfwc-companion' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="template[config][<?php echo esc_attr( $interval_key ); ?>][enabled]"
								value="1"
								<?php checked( ! empty( $block['enabled'] ) ); ?>
							>
							<?php
							printf(
								/* translators: %s: interval label */
								esc_html__( 'Offer %s donations on campaigns using this template.', 'dfwc-companion' ),
								esc_html( strtolower( $interval_labels[ $interval_key ] ) )
							);
							?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Preset amounts', 'dfwc-companion' ); ?></label></th>
					<td>
						<table class="dfwc-tpl-presets widefat">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Amount', 'dfwc-companion' ); ?></th>
									<th><?php esc_html_e( 'Label', 'dfwc-companion' ); ?></th>
									<th><?php esc_html_e( 'Impact label', 'dfwc-companion' ); ?></th>
									<th><?php esc_html_e( 'Featured', 'dfwc-companion' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( ( $block['presets'] ?? array() ) as $idx => $preset ) :
									$base_name = "template[config][{$interval_key}][presets][{$idx}]";
									?>
									<tr>
										<td>
											<input
												type="number"
												name="<?php echo esc_attr( $base_name . '[amount]' ); ?>"
												value="<?php echo esc_attr( (string) ( $preset['amount'] ?? '' ) ); ?>"
												step="0.01"
												min="0.01"
												required
												style="width:100px"
											>
										</td>
										<td>
											<input
												type="text"
												name="<?php echo esc_attr( $base_name . '[label]' ); ?>"
												value="<?php echo esc_attr( (string) ( $preset['label'] ?? '' ) ); ?>"
												class="regular-text"
												maxlength="60"
												placeholder="<?php esc_attr_e( 'e.g. Supporter', 'dfwc-companion' ); ?>"
											>
										</td>
										<td>
											<input
												type="text"
												name="<?php echo esc_attr( $base_name . '[impact_label]' ); ?>"
												value="<?php echo esc_attr( (string) ( $preset['impact_label'] ?? '' ) ); ?>"
												class="regular-text"
												maxlength="120"
												placeholder="<?php esc_attr_e( 'e.g. Provides school supplies', 'dfwc-companion' ); ?>"
											>
										</td>
										<td>
											<input
												type="checkbox"
												name="<?php echo esc_attr( $base_name . '[is_featured]' ); ?>"
												value="1"
												<?php checked( ! empty( $preset['is_featured'] ) ); ?>
											>
											<input
												type="hidden"
												name="<?php echo esc_attr( $base_name . '[sort_order]' ); ?>"
												value="<?php echo esc_attr( (string) ( $preset['sort_order'] ?? ( ( $idx + 1 ) * 10 ) ) ); ?>"
											>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description">
							<?php esc_html_e( 'At least one preset is required when this interval is enabled. Phase 5 (v0.9.0) wires the impact label and featured flag into the donor-facing form.', 'dfwc-companion' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Min / Max', 'dfwc-companion' ); ?></th>
					<td>
						<label>
							<?php esc_html_e( 'Minimum:', 'dfwc-companion' ); ?>
							<input
								type="number"
								name="template[config][<?php echo esc_attr( $interval_key ); ?>][min]"
								value="<?php echo esc_attr( (string) ( $block['min'] ?? 5.0 ) ); ?>"
								step="0.01"
								min="0.01"
								style="width:100px"
							>
						</label>
						&nbsp;
						<label>
							<?php esc_html_e( 'Maximum:', 'dfwc-companion' ); ?>
							<input
								type="number"
								name="template[config][<?php echo esc_attr( $interval_key ); ?>][max]"
								value="<?php echo esc_attr( (string) ( $block['max'] ?? 10000.0 ) ); ?>"
								step="0.01"
								min="0.01"
								style="width:100px"
							>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Custom amount', 'dfwc-companion' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="template[config][<?php echo esc_attr( $interval_key ); ?>][custom_amount_enabled]"
								value="1"
								<?php checked( ! empty( $block['custom_amount_enabled'] ) ); ?>
							>
							<?php esc_html_e( 'Allow donors to enter a custom amount on this tab.', 'dfwc-companion' ); ?>
						</label>
						<p>
							<label for="dfwc-tpl-<?php echo esc_attr( $interval_key ); ?>-custom-impact" style="display:block;margin-top:8px">
								<?php esc_html_e( 'Custom-amount impact label (optional)', 'dfwc-companion' ); ?>
							</label>
							<input
								type="text"
								class="large-text"
								id="dfwc-tpl-<?php echo esc_attr( $interval_key ); ?>-custom-impact"
								name="template[config][<?php echo esc_attr( $interval_key ); ?>][custom_amount_impact_label]"
								value="<?php echo esc_attr( (string) ( $block['custom_amount_impact_label'] ?? '' ) ); ?>"
								maxlength="120"
								placeholder="<?php esc_attr_e( 'e.g. Every gift makes a difference', 'dfwc-companion' ); ?>"
							>
							<span class="description">
								<?php esc_html_e( 'Shown alongside the donor\'s custom-amount input. Use this when admin doesn\'t want a per-preset impact label to lose meaning when donors type their own amount.', 'dfwc-companion' ); ?>
							</span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'CTA template', 'dfwc-companion' ); ?></label></th>
					<td>
						<input
							type="text"
							class="large-text"
							name="template[config][<?php echo esc_attr( $interval_key ); ?>][cta_template]"
							value="<?php echo esc_attr( (string) ( $block['cta_template'] ?? '' ) ); ?>"
							maxlength="120"
						>
						<p class="description">
							<?php
							printf(
								/* translators: 1: literal {amount}, 2: literal {interval} */
								esc_html__( 'Tokens: %1$s (replaced with formatted price), %2$s (replaced with interval label).', 'dfwc-companion' ),
								'<code>{amount}</code>',
								'<code>{interval}</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<td colspan="2" style="padding-left:0">
						<details class="dfwc-tpl-advanced">
							<summary><?php esc_html_e( 'Advanced impact messaging', 'dfwc-companion' ); ?></summary>

							<table class="form-table dfwc-tpl-advanced__table" role="presentation">
								<tr>
									<th><?php esc_html_e( 'Subtitle', 'dfwc-companion' ); ?></th>
									<td>
										<input
											type="text"
											class="large-text"
											name="template[config][<?php echo esc_attr( $interval_key ); ?>][subtitle]"
											value="<?php echo esc_attr( (string) ( $block['subtitle'] ?? '' ) ); ?>"
											maxlength="120"
											placeholder="<?php esc_attr_e( 'e.g. Become a monthly sponsor', 'dfwc-companion' ); ?>"
										>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Annual equivalency', 'dfwc-companion' ); ?></th>
									<td>
										<input
											type="text"
											class="large-text"
											name="template[config][<?php echo esc_attr( $interval_key ); ?>][annual_equivalency]"
											value="<?php echo esc_attr( (string) ( $block['annual_equivalency'] ?? '' ) ); ?>"
											maxlength="160"
											placeholder="<?php esc_attr_e( '{amount}/month equals {annual_amount}/year', 'dfwc-companion' ); ?>"
										>
										<p class="description">
											<?php
											printf(
												/* translators: 1: literal {amount}, 2: literal {annual_amount} */
												esc_html__( 'Tokens: %1$s (current selected amount), %2$s (12× current amount). Most useful on the Monthly tab.', 'dfwc-companion' ),
												'<code>{amount}</code>',
												'<code>{annual_amount}</code>'
											);
											?>
										</p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Impact display mode', 'dfwc-companion' ); ?></th>
									<td>
										<?php
										$dfwc_mode        = $block['impact_display_mode'] ?? 'below_button';
										$dfwc_mode_labels = array(
											'inline'       => __( 'Inline (next to amount)', 'dfwc-companion' ),
											'below_button' => __( 'Below preset (default)', 'dfwc-companion' ),
											'tooltip'      => __( 'Tooltip on hover/focus', 'dfwc-companion' ),
											'card'         => __( 'Card layout (per preset)', 'dfwc-companion' ),
										);
										?>
										<?php foreach ( $dfwc_mode_labels as $value => $label ) : ?>
											<label style="display:block;margin:4px 0">
												<input
													type="radio"
													name="template[config][<?php echo esc_attr( $interval_key ); ?>][impact_display_mode]"
													value="<?php echo esc_attr( $value ); ?>"
													<?php checked( $dfwc_mode, $value ); ?>
												>
												<?php echo esc_html( $label ); ?>
											</label>
										<?php endforeach; ?>
									</td>
								</tr>
							</table>
						</details>
					</td>
				</tr>
			</table>
		<?php endforeach; ?>

		<h2 class="dfwc-tpl-section-h"><?php esc_html_e( 'Display options', 'dfwc-companion' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show campaign title', 'dfwc-companion' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="template[config][display][show_title]"
							value="1"
							<?php checked( ! empty( $tpl->config['display']['show_title'] ) ); ?>
						>
						<?php esc_html_e( 'Show campaign title above the donor form.', 'dfwc-companion' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show campaign image', 'dfwc-companion' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="template[config][display][show_image]"
							value="1"
							<?php checked( ! empty( $tpl->config['display']['show_image'] ) ); ?>
						>
						<?php esc_html_e( 'Show campaign featured image above the donor form.', 'dfwc-companion' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dfwc-tpl-cause-heading"><?php esc_html_e( 'Cause section heading', 'dfwc-companion' ); ?></label></th>
				<td>
					<input
						type="text"
						class="regular-text"
						id="dfwc-tpl-cause-heading"
						name="template[config][display][cause_heading]"
						value="<?php echo esc_attr( (string) ( $tpl->config['display']['cause_heading'] ?? '' ) ); ?>"
						maxlength="120"
						placeholder="<?php esc_attr_e( 'Select Cause', 'dfwc-companion' ); ?>"
					>
					<p class="description">
						<?php esc_html_e( 'Leave blank to keep the parent plugin\'s default ("Select Cause").', 'dfwc-companion' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( $is_new ? __( 'Create template', 'dfwc-companion' ) : __( 'Save template', 'dfwc-companion' ) ); ?>

		<?php if ( $wpml_active && ! $is_new ) : ?>
			<p class="description">
				<?php
				esc_html_e( 'WPML detected. Translate template strings via WP Admin → WPML → String Translation, filtering by domain "Donations Companion".', 'dfwc-companion' );
				?>
			</p>
		<?php endif; ?>
	</form>
</div>
