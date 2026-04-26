<?php
/**
 * Admin meta-box markup: three interval tabs (one_time / monthly / annual),
 * each with an enable toggle, sortable preset repeater, custom-amount min/max,
 * default-preset radio, and CTA template input.
 *
 * Variables in scope (from Meta_Box::render_intervals):
 *   array   $config         Resolved per-campaign config (see Config_Resolver).
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

use DFWC\Companion\Config_Resolver;
use DFWC\Companion\Engine_Detector;

$engine_supports_recurring = Engine_Detector::ENGINE_NONE !== $engine;
?>
<div class="dfwc-mb" data-dfwc-meta-box>
	<?php if ( ! $engine_supports_recurring ) : ?>
		<div class="notice notice-warning inline dfwc-mb__notice">
			<p>
				<?php esc_html_e( 'No recurring billing engine is active. Donors will only see the One-time tab. Install WooCommerce Subscriptions or Subscriptions For WooCommerce to enable Monthly and Annually.', 'dfwc-companion' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $product_warn ) : ?>
		<div class="notice notice-warning inline dfwc-mb__notice">
			<p><?php echo esc_html( $product_warn ); ?></p>
		</div>
	<?php endif; ?>

	<div class="dfwc-mb__tabs" role="tablist">
		<?php foreach ( $intervals as $i => $key ) :
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

	<?php foreach ( $intervals as $i => $key ) :
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
			<?php if ( $panel_hidden ) : ?>hidden<?php endif; ?>
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
							<th class="dfwc-mb__col-label"><?php esc_html_e( 'Label (optional)', 'dfwc-companion' ); ?></th>
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
										value="<?php echo esc_attr( $preset['label'] ); ?>"
										maxlength="60"
										placeholder="<?php esc_attr_e( 'e.g. Supporter', 'dfwc-companion' ); ?>"
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
				<legend><?php esc_html_e( 'Custom amount range', 'dfwc-companion' ); ?></legend>
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

	<p class="description dfwc-mb__footnote">
		<?php esc_html_e( 'Enabling Monthly or Annually here overrides the parent plugin\'s "Recurring" campaign setting (forces it to "User chooses"). One-time-only campaigns leave the parent setting untouched.', 'dfwc-companion' ); ?>
	</p>
</div>
