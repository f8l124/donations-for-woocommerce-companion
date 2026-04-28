<?php
/**
 * Admin meta-box markup: three interval tabs (one_time / monthly / annual),
 * each with an enable toggle, sortable preset repeater, custom-amount min/max,
 * default-preset radio, and CTA template input.
 *
 * Variables in scope (from Meta_Box::render_intervals):
 *   array   $config         Resolved per-campaign config (see Config_Resolver).
 *   array   $display        Display options (show_title/show_image/cause_heading).
 *   string  $engine         'wcs' | 'wps_sfw' | 'none'.
 *   ?string $product_warn   Warning message about linked product, or null.
 *   array   $intervals      Allow-listed interval keys in display order.
 *   array   $interval_label Display labels keyed by interval.
 *   WP_Post $post           The campaign post being edited.
 *
 * Note: <input type="number"> inputs receive raw dot-decimal floats. Browsers
 * always parse/POST type=number values with `.` as the separator regardless of
 * locale. Don't run amounts through wc_format_localized_price() here — the
 * comma form would render as empty.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Admin_Menu;
use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Engine_Detector;
use DFWC\Companion\I18n\WPML_Strings;

$engine_supports_recurring = Engine_Detector::ENGINE_NONE !== $engine;
$dfwc_extra_currencies     = Currency_Preset_Resolver::extra_currencies();
$dfwc_base_currency        = Currency_Preset_Resolver::base_currency();
$dfwc_multi_currency       = ! empty( $dfwc_extra_currencies );

// v0.7.0: template selector header. $current_tpl_id, $is_detached,
// $all_templates come from Meta_Box::render_intervals().
$has_templates  = ! empty( $all_templates );
$current_tpl    = '' !== $current_tpl_id && isset( $all_templates[ $current_tpl_id ] )
	? $all_templates[ $current_tpl_id ]
	: null;
?>
<div class="dfwc-mb" data-dfwc-meta-box>

	<?php if ( $has_templates ) : ?>
		<div class="dfwc-mb__template-header">
			<label for="dfwc-template-id" class="dfwc-mb__template-label">
				<?php esc_html_e( 'Template:', 'dfwc-companion' ); ?>
			</label>
			<select id="dfwc-template-id" name="dfwc_template_id">
				<option value="" <?php selected( '', $current_tpl_id ); ?>>
					<?php esc_html_e( '— No template —', 'dfwc-companion' ); ?>
				</option>
				<?php foreach ( $all_templates as $tpl_option ) : ?>
					<option value="<?php echo esc_attr( $tpl_option->id ); ?>" <?php selected( $tpl_option->id, $current_tpl_id ); ?>>
						<?php echo esc_html( WPML_Strings::translate( $tpl_option->name, $tpl_option->id . '.name' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="hidden" id="dfwc-template-action" name="dfwc_template_action" value="">

			<button type="submit" class="button" data-dfwc-tpl-action="apply">
				<?php esc_html_e( 'Apply', 'dfwc-companion' ); ?>
			</button>
			<?php if ( null !== $current_tpl && ! $is_detached ) : ?>
				<button type="submit" class="button" data-dfwc-tpl-action="detach" title="<?php esc_attr_e( 'Snapshot resolved values into campaign overrides; future template changes will not affect this campaign.', 'dfwc-companion' ); ?>">
					<?php esc_html_e( 'Detach', 'dfwc-companion' ); ?>
				</button>
				<button type="submit" class="button button-link-delete" data-dfwc-tpl-action="reset" title="<?php esc_attr_e( 'Clear all campaign overrides and inherit cleanly from the template.', 'dfwc-companion' ); ?>">
					<?php esc_html_e( 'Reset to template', 'dfwc-companion' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( null !== $current_tpl ) : ?>
				<p class="description">
					<?php
					if ( $is_detached ) {
						esc_html_e( 'This campaign is detached. Future changes to the template will not affect it.', 'dfwc-companion' );
					} else {
						printf(
							/* translators: %s: template name */
							esc_html__( 'Inheriting from "%s". Changes you make below become campaign overrides.', 'dfwc-companion' ),
							'<strong>' . esc_html( WPML_Strings::translate( $current_tpl->name, $current_tpl->id . '.name' ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner already escaped
						);
					}
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<p class="description dfwc-mb__no-templates-hint">
			<?php
			printf(
				/* translators: %s: link to the Templates page */
				esc_html__( 'Tip: %s to apply the same configuration to many campaigns at once.', 'dfwc-companion' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Menu::TEMPLATES_SLUG . '&action=new' ) ) . '">' . esc_html__( 'create a template', 'dfwc-companion' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composed of already-escaped fragments
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( ! $engine_supports_recurring ) : ?>
		<div class="notice notice-warning inline dfwc-mb__notice">
			<p>
				<?php esc_html_e( 'No recurring billing engine is active. Donors will only see the One-time tab.', 'dfwc-companion' ); ?>
				<a href="<?php echo esc_url( Engine_Detector::recommended_install_url() ); ?>">
					<?php esc_html_e( 'Install Subscriptions For WooCommerce (free)', 'dfwc-companion' ); ?>
				</a>
				<?php esc_html_e( 'or', 'dfwc-companion' ); ?>
				<a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'WooCommerce Subscriptions', 'dfwc-companion' ); ?>
				</a>
				<?php esc_html_e( 'to enable Monthly and Annually.', 'dfwc-companion' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $product_warn ) : ?>
		<div class="notice notice-warning inline dfwc-mb__notice">
			<p><?php echo esc_html( $product_warn ); ?></p>
		</div>
	<?php endif; ?>

	<div class="dfwc-mb__tabs" role="tablist">
		<?php
		foreach ( $intervals as $i => $key ) :
			$tab_disabled = ( Config_Resolver::INTERVAL_ONE_TIME !== $key ) && ! $engine_supports_recurring;
			?>
			<button
				type="button"
				role="tab"
				class="dfwc-mb__tab"
				data-dfwc-tab="<?php echo esc_attr( $key ); ?>"
				aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
				aria-controls="dfwc-panel-<?php echo esc_attr( $key ); ?>"
				id="dfwc-tab-<?php echo esc_attr( $key ); ?>"
				<?php disabled( $tab_disabled ); ?>
			>
				<?php echo esc_html( $interval_label[ $key ] ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<?php
	foreach ( $intervals as $i => $key ) :
		$block         = $config[ $key ];
		$panel_hidden  = 0 !== $i;
		$tab_disabled  = ( Config_Resolver::INTERVAL_ONE_TIME !== $key ) && ! $engine_supports_recurring;
		$preset_count  = count( $block['presets'] );
		$decimals      = wc_get_price_decimals();
		?>
		<section
			class="dfwc-mb__panel"
			role="tabpanel"
			id="dfwc-panel-<?php echo esc_attr( $key ); ?>"
			aria-labelledby="dfwc-tab-<?php echo esc_attr( $key ); ?>"
			data-dfwc-panel="<?php echo esc_attr( $key ); ?>"
			<?php
			if ( $panel_hidden ) :
				?>
				hidden<?php endif; ?>
		>
			<p class="dfwc-mb__enable">
				<label>
					<input
						type="checkbox"
						name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][enabled]"
						value="1"
						<?php checked( $block['enabled'] ); ?>
						<?php disabled( $tab_disabled ); ?>
					>
					<?php
					printf(
						/* translators: %s: interval label (One-time / Monthly / Annually) */
						esc_html__( 'Offer %s donations on this campaign', 'dfwc-companion' ),
						esc_html( strtolower( $interval_label[ $key ] ) )
					);
					?>
				</label>
			</p>

			<fieldset class="dfwc-mb__fieldset" <?php disabled( $tab_disabled ); ?>>
				<legend><?php esc_html_e( 'Preset amounts', 'dfwc-companion' ); ?></legend>
				<table class="dfwc-mb__presets widefat" data-dfwc-presets="<?php echo esc_attr( $key ); ?>">
					<thead>
						<tr>
							<th class="dfwc-mb__col-handle" aria-hidden="true"></th>
							<th class="dfwc-mb__col-amount"><?php esc_html_e( 'Amount', 'dfwc-companion' ); ?></th>
							<th class="dfwc-mb__col-label"><?php esc_html_e( 'Label', 'dfwc-companion' ); ?></th>
							<th class="dfwc-mb__col-impact"><?php esc_html_e( 'Impact label', 'dfwc-companion' ); ?></th>
							<th class="dfwc-mb__col-featured" title="<?php esc_attr_e( 'Marks one preset as "Most popular" with a badge on the donor form.', 'dfwc-companion' ); ?>"><?php esc_html_e( 'Featured', 'dfwc-companion' ); ?></th>
							<th class="dfwc-mb__col-default"><?php esc_html_e( 'Default', 'dfwc-companion' ); ?></th>
							<th class="dfwc-mb__col-remove"><?php esc_html_e( 'Remove', 'dfwc-companion' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $block['presets'] as $idx => $preset ) : ?>
							<tr class="dfwc-mb__preset-row" draggable="true">
								<td class="dfwc-mb__col-handle" aria-hidden="true">
									<span class="dfwc-mb__drag" title="<?php esc_attr_e( 'Drag to reorder', 'dfwc-companion' ); ?>">&#x2630;</span>
								</td>
								<td class="dfwc-mb__col-amount">
									<input
										type="number"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][<?php echo (int) $idx; ?>][amount]"
										value="<?php echo esc_attr( (string) $preset['amount'] ); ?>"
										step="0.01"
										min="0.01"
										required
									>
								</td>
								<td class="dfwc-mb__col-label">
									<input
										type="text"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][<?php echo (int) $idx; ?>][label]"
										value="<?php echo esc_attr( $preset['label'] ?? '' ); ?>"
										maxlength="60"
										placeholder="<?php esc_attr_e( 'e.g. Supporter', 'dfwc-companion' ); ?>"
									>
								</td>
								<td class="dfwc-mb__col-impact">
									<input
										type="text"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][<?php echo (int) $idx; ?>][impact_label]"
										value="<?php echo esc_attr( (string) ( $preset['impact_label'] ?? '' ) ); ?>"
										maxlength="120"
										placeholder="<?php esc_attr_e( 'Provides school supplies for one student', 'dfwc-companion' ); ?>"
									>
								</td>
								<td class="dfwc-mb__col-featured">
									<input
										type="checkbox"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][<?php echo (int) $idx; ?>][is_featured]"
										value="1"
										<?php checked( ! empty( $preset['is_featured'] ) ); ?>
									>
									<input
										type="hidden"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][<?php echo (int) $idx; ?>][sort_order]"
										value="<?php echo esc_attr( (string) ( $preset['sort_order'] ?? ( ( $idx + 1 ) * 10 ) ) ); ?>"
									>
								</td>
								<td class="dfwc-mb__col-default">
									<input
										type="radio"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][default_index]"
										value="<?php echo (int) $idx; ?>"
										<?php checked( (int) $block['default_index'], (int) $idx ); ?>
									>
								</td>
								<td class="dfwc-mb__col-remove">
									<button type="button" class="button-link dfwc-mb__remove" data-dfwc-remove>
										<?php esc_html_e( 'Remove', 'dfwc-companion' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button dfwc-mb__add" data-dfwc-add="<?php echo esc_attr( $key ); ?>">
						<?php esc_html_e( '+ Add preset', 'dfwc-companion' ); ?>
					</button>
				</p>

				<template id="dfwc-preset-template-<?php echo esc_attr( $key ); ?>">
					<tr class="dfwc-mb__preset-row" draggable="true">
						<td class="dfwc-mb__col-handle" aria-hidden="true">
							<span class="dfwc-mb__drag" title="<?php esc_attr_e( 'Drag to reorder', 'dfwc-companion' ); ?>">&#x2630;</span>
						</td>
						<td class="dfwc-mb__col-amount">
							<input type="number" name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][__INDEX__][amount]" value="" step="0.01" min="0.01" required>
						</td>
						<td class="dfwc-mb__col-label">
							<input type="text" name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][__INDEX__][label]" value="" maxlength="60" placeholder="<?php esc_attr_e( 'e.g. Supporter', 'dfwc-companion' ); ?>">
						</td>
						<td class="dfwc-mb__col-impact">
							<input type="text" name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][__INDEX__][impact_label]" value="" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Provides school supplies', 'dfwc-companion' ); ?>">
						</td>
						<td class="dfwc-mb__col-featured">
							<input type="checkbox" name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][__INDEX__][is_featured]" value="1">
							<input type="hidden" name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][presets][__INDEX__][sort_order]" value="">
						</td>
						<td class="dfwc-mb__col-default">
							<input type="radio" name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][default_index]" value="__INDEX__">
						</td>
						<td class="dfwc-mb__col-remove">
							<button type="button" class="button-link dfwc-mb__remove" data-dfwc-remove>
								<?php esc_html_e( 'Remove', 'dfwc-companion' ); ?>
							</button>
						</td>
					</tr>
				</template>
			</fieldset>

			<fieldset class="dfwc-mb__fieldset" <?php disabled( $tab_disabled ); ?>>
				<legend><?php esc_html_e( 'Custom amount', 'dfwc-companion' ); ?></legend>
				<p>
					<label>
						<input
							type="checkbox"
							name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][custom_amount_enabled]"
							value="1"
							<?php checked( ! empty( $block['custom_amount_enabled'] ) ); ?>
						>
						<?php esc_html_e( 'Allow donors to enter a custom amount on this tab', 'dfwc-companion' ); ?>
					</label>
				</p>
				<p>
					<label>
						<?php esc_html_e( 'Minimum', 'dfwc-companion' ); ?>
						<input
							type="number"
							name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][min]"
							value="<?php echo esc_attr( (string) $block['min'] ); ?>"
							step="0.01"
							min="0.01"
						>
					</label>
				</p>
				<p>
					<label>
						<?php esc_html_e( 'Maximum', 'dfwc-companion' ); ?>
						<input
							type="number"
							name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][max]"
							value="<?php echo esc_attr( (string) $block['max'] ); ?>"
							step="0.01"
							min="0.01"
						>
					</label>
				</p>
				<p>
					<label>
						<?php esc_html_e( 'Custom-amount impact label (optional)', 'dfwc-companion' ); ?>
						<input
							type="text"
							class="large-text"
							name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][custom_amount_impact_label]"
							value="<?php echo esc_attr( (string) ( $block['custom_amount_impact_label'] ?? '' ) ); ?>"
							maxlength="120"
							placeholder="<?php esc_attr_e( 'e.g. Every gift makes a difference', 'dfwc-companion' ); ?>"
						>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'When custom amount is unchecked, donors must pick a preset. Min/max still applies to preset validation.', 'dfwc-companion' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'The custom-amount impact label appears alongside the donor\'s free-form amount — useful when per-preset impact labels don\'t apply to arbitrary amounts.', 'dfwc-companion' ); ?>
				</p>
			</fieldset>

			<?php if ( $dfwc_multi_currency ) : ?>
				<fieldset class="dfwc-mb__fieldset dfwc-mb__currencies" <?php disabled( $tab_disabled ); ?>>
					<legend><?php esc_html_e( 'Per-currency preset amounts', 'dfwc-companion' ); ?></legend>
					<p class="description">
						<?php
						printf(
							/* translators: %s: base currency code */
							esc_html__( 'Base currency is %s — base presets above. Define per-currency amounts here so donors in other currencies see psychologically rounded numbers (e.g., £20 instead of an auto-converted £19.78). Empty rows fall back to the base amount.', 'dfwc-companion' ),
							'<code>' . esc_html( $dfwc_base_currency ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner already escaped
						);
						?>
					</p>
					<?php
					$dfwc_currency_overrides = isset( $block['currency_overrides'] ) && is_array( $block['currency_overrides'] )
						? $block['currency_overrides']
						: array();
					foreach ( $dfwc_extra_currencies as $dfwc_currency_code ) :
						$dfwc_override_block = isset( $dfwc_currency_overrides[ $dfwc_currency_code ] ) && is_array( $dfwc_currency_overrides[ $dfwc_currency_code ] )
							? $dfwc_currency_overrides[ $dfwc_currency_code ]
							: array();
						$dfwc_override_presets = isset( $dfwc_override_block['presets'] ) && is_array( $dfwc_override_block['presets'] )
							? $dfwc_override_block['presets']
							: array();
						$dfwc_has_override = ! empty( $dfwc_override_block );
						?>
						<details class="dfwc-mb__currency" <?php echo $dfwc_has_override ? 'open' : ''; ?>>
							<summary><?php echo esc_html( Currency_Preset_Resolver::currency_label( $dfwc_currency_code ) ); ?></summary>
							<table class="dfwc-mb__currency-presets widefat">
								<thead>
									<tr>
										<th class="dfwc-mb__col-baseref"><?php esc_html_e( 'Base amount', 'dfwc-companion' ); ?></th>
										<th class="dfwc-mb__col-baseref"><?php esc_html_e( 'Base label', 'dfwc-companion' ); ?></th>
										<th class="dfwc-mb__col-amount"><?php echo esc_html( $dfwc_currency_code ); ?> <?php esc_html_e( 'amount', 'dfwc-companion' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $block['presets'] as $idx => $preset ) :
										$dfwc_existing = isset( $dfwc_override_presets[ $idx ] ) && is_array( $dfwc_override_presets[ $idx ] ) && isset( $dfwc_override_presets[ $idx ]['amount'] )
											? (string) $dfwc_override_presets[ $idx ]['amount']
											: '';
										?>
										<tr>
											<td class="dfwc-mb__col-baseref"><code><?php echo esc_html( (string) $preset['amount'] ); ?></code></td>
											<td class="dfwc-mb__col-baseref"><?php echo esc_html( (string) ( $preset['label'] ?? '' ) ); ?></td>
											<td class="dfwc-mb__col-amount">
												<input
													type="number"
													name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][currency_overrides][<?php echo esc_attr( $dfwc_currency_code ); ?>][presets][<?php echo (int) $idx; ?>][amount]"
													value="<?php echo esc_attr( $dfwc_existing ); ?>"
													step="0.01"
													min="0"
													placeholder="<?php esc_attr_e( '— uses base —', 'dfwc-companion' ); ?>"
												>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p>
								<label>
									<?php esc_html_e( 'Minimum (optional)', 'dfwc-companion' ); ?>
									<input
										type="number"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][currency_overrides][<?php echo esc_attr( $dfwc_currency_code ); ?>][min]"
										value="<?php echo esc_attr( isset( $dfwc_override_block['min'] ) ? (string) $dfwc_override_block['min'] : '' ); ?>"
										step="0.01"
										min="0"
										placeholder="<?php esc_attr_e( '— uses base —', 'dfwc-companion' ); ?>"
									>
								</label>
								&nbsp;
								<label>
									<?php esc_html_e( 'Maximum (optional)', 'dfwc-companion' ); ?>
									<input
										type="number"
										name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][currency_overrides][<?php echo esc_attr( $dfwc_currency_code ); ?>][max]"
										value="<?php echo esc_attr( isset( $dfwc_override_block['max'] ) ? (string) $dfwc_override_block['max'] : '' ); ?>"
										step="0.01"
										min="0"
										placeholder="<?php esc_attr_e( '— uses base —', 'dfwc-companion' ); ?>"
									>
								</label>
							</p>
						</details>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<fieldset class="dfwc-mb__fieldset" <?php disabled( $tab_disabled ); ?>>
				<legend><?php esc_html_e( 'Advanced impact messaging', 'dfwc-companion' ); ?></legend>
				<p>
					<label style="display:block">
						<?php esc_html_e( 'Subtitle', 'dfwc-companion' ); ?>
						<input
							type="text"
							class="large-text"
							name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][subtitle]"
							value="<?php echo esc_attr( (string) ( $block['subtitle'] ?? '' ) ); ?>"
							maxlength="120"
							placeholder="<?php esc_attr_e( 'e.g. Become a monthly sponsor', 'dfwc-companion' ); ?>"
						>
					</label>
				</p>
				<p>
					<label style="display:block">
						<?php esc_html_e( 'Annual equivalency', 'dfwc-companion' ); ?>
						<input
							type="text"
							class="large-text"
							name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][annual_equivalency]"
							value="<?php echo esc_attr( (string) ( $block['annual_equivalency'] ?? '' ) ); ?>"
							maxlength="160"
							placeholder="<?php esc_attr_e( '{amount}/month equals {annual_amount}/year', 'dfwc-companion' ); ?>"
						>
					</label>
					<span class="description">
						<?php
						printf(
							/* translators: 1: literal {amount}, 2: literal {annual_amount} */
							esc_html__( 'Tokens: %1$s (current selected amount), %2$s (12× current). Most useful on the Monthly tab.', 'dfwc-companion' ),
							'<code>{amount}</code>',
							'<code>{annual_amount}</code>'
						);
						?>
					</span>
				</p>
				<fieldset class="dfwc-mb__display-mode-group">
					<legend style="font-weight:normal"><?php esc_html_e( 'Impact display mode', 'dfwc-companion' ); ?></legend>
					<?php
					$current_mode = $block['impact_display_mode'] ?? 'below_button';
					$mode_labels  = array(
						'inline'       => __( 'Inline (next to amount in preset button)', 'dfwc-companion' ),
						'below_button' => __( 'Below preset (default)', 'dfwc-companion' ),
						'tooltip'      => __( 'Tooltip on hover/focus (a11y-safe)', 'dfwc-companion' ),
						'card'         => __( 'Card layout (each preset becomes a full card)', 'dfwc-companion' ),
					);
					foreach ( $mode_labels as $mode_value => $mode_label ) :
						?>
						<label style="display:block;margin:4px 0">
							<input
								type="radio"
								name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][impact_display_mode]"
								value="<?php echo esc_attr( $mode_value ); ?>"
								<?php checked( $current_mode, $mode_value ); ?>
							>
							<?php echo esc_html( $mode_label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</fieldset>

			<fieldset class="dfwc-mb__fieldset" <?php disabled( $tab_disabled ); ?>>
				<legend><?php esc_html_e( 'Call-to-action template', 'dfwc-companion' ); ?></legend>
				<p>
					<input
						type="text"
						class="large-text"
						name="dfwc_intervals[<?php echo esc_attr( $key ); ?>][cta_template]"
						value="<?php echo esc_attr( $block['cta_template'] ); ?>"
						maxlength="120"
					>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: literal {amount} token, 2: literal {interval} token */
						esc_html__( 'Tokens: %1$s (replaced with the formatted price), %2$s (replaced with the interval label).', 'dfwc-companion' ),
						'<code>{amount}</code>',
						'<code>{interval}</code>'
					);
					?>
				</p>
			</fieldset>
		</section>
	<?php endforeach; ?>

	<fieldset class="dfwc-mb__fieldset dfwc-mb__display">
		<legend><?php esc_html_e( 'Display options', 'dfwc-companion' ); ?></legend>
		<p>
			<label>
				<input
					type="checkbox"
					name="dfwc_display[show_title]"
					value="1"
					<?php checked( ! empty( $display['show_title'] ) ); ?>
				>
				<?php esc_html_e( 'Show campaign title above the form', 'dfwc-companion' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input
					type="checkbox"
					name="dfwc_display[show_image]"
					value="1"
					<?php checked( ! empty( $display['show_image'] ) ); ?>
				>
				<?php esc_html_e( 'Show campaign image above the form', 'dfwc-companion' ); ?>
			</label>
		</p>
		<p>
			<label for="dfwc_display_cause_heading"><?php esc_html_e( 'Cause section heading', 'dfwc-companion' ); ?></label><br>
			<input
				id="dfwc_display_cause_heading"
				type="text"
				class="large-text"
				name="dfwc_display[cause_heading]"
				value="<?php echo esc_attr( (string) ( $display['cause_heading'] ?? '' ) ); ?>"
				maxlength="120"
				placeholder="<?php esc_attr_e( 'Select Cause', 'dfwc-companion' ); ?>"
			>
		</p>
		<p class="description">
			<?php esc_html_e( 'Leave the heading blank to keep the parent plugin\'s default ("Select Cause"). When you uncheck "Show campaign title" or "Show campaign image", the parent plugin still renders those elements — we just hide them on the donor-facing form.', 'dfwc-companion' ); ?>
		</p>
	</fieldset>

	<p class="description dfwc-mb__footnote">
		<?php esc_html_e( 'Enabling Monthly or Annually here overrides the parent plugin\'s "Recurring" campaign setting (forces it to "User chooses"). One-time-only campaigns leave the parent setting untouched.', 'dfwc-companion' ); ?>
	</p>
</div>
