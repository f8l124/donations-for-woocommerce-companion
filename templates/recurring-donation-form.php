<?php
/**
 * Donor-facing form markup. Three radio-styled tabs (One-time / Monthly /
 * Annually), each with preset amount buttons + custom amount input, and a
 * single CTA button updated by JS as the donor changes their selection.
 *
 * Variables in scope (from Frontend\Renderer::render):
 *   int    $campaign_id        The wc-donation post ID.
 *   array  $config             Resolved per-campaign config.
 *   string $engine             'wcs' | 'wps_sfw' | 'none'.
 *   array  $intervals          Allow-listed interval keys, in display order.
 *   array  $interval_label     Display labels keyed by interval.
 *   array  $enabled_intervals  Subset of $intervals that's actually selectable.
 *   string $active_interval    Initially-selected interval key.
 *   string $form_uid           Unique per-form ID prefix (multi-instance safe).
 *   array  $form_config        Per-interval JS config (min/max/default/template).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config_Resolver;
use DFWC\Companion\Engine_Detector;
use DFWC\Companion\Frontend\Renderer;

$initial_block        = $config[ $active_interval ];
$initial_default_idx  = (int) $initial_block['default_index'];
$initial_amount       = isset( $initial_block['presets'][ $initial_default_idx ]['amount'] )
	? (float) $initial_block['presets'][ $initial_default_idx ]['amount']
	: 0.0;
$initial_cta_html     = Renderer::format_cta( (string) $initial_block['cta_template'], $initial_amount, $active_interval );
$engine_supports      = Engine_Detector::ENGINE_NONE !== $engine;
$json_form_config     = wp_json_encode( $form_config );
?>
<form
	class="dfwc-form"
	data-dfwc-form
	data-form-uid="<?php echo esc_attr( $form_uid ); ?>"
	data-campaign-id="<?php echo (int) $campaign_id; ?>"
	data-engine="<?php echo esc_attr( $engine ); ?>"
	data-active-interval="<?php echo esc_attr( $active_interval ); ?>"
	data-config="<?php echo esc_attr( (string) $json_form_config ); ?>"
	novalidate
>
	<?php if ( ! $engine_supports ) : ?>
		<p class="dfwc-form__engine-note">
			<?php esc_html_e( 'Recurring donations are unavailable on this site. You can still donate one time.', 'dfwc-companion' ); ?>
		</p>
	<?php endif; ?>

	<div class="dfwc-form__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Donation interval', 'dfwc-companion' ); ?>">
		<?php foreach ( $intervals as $key ) :
			$is_enabled = in_array( $key, $enabled_intervals, true );
			$is_active  = ( $key === $active_interval );
			$tab_id     = $form_uid . '-tab-' . $key;
			$panel_id   = $form_uid . '-panel-' . $key;
			?>
			<button
				type="button"
				role="tab"
				class="dfwc-form__tab<?php echo $is_active ? ' dfwc-form__tab--active' : ''; ?>"
				id="<?php echo esc_attr( $tab_id ); ?>"
				aria-controls="<?php echo esc_attr( $panel_id ); ?>"
				aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
				tabindex="<?php echo $is_active ? '0' : '-1'; ?>"
				data-dfwc-tab="<?php echo esc_attr( $key ); ?>"
				<?php if ( ! $is_enabled ) : ?>aria-disabled="true" disabled<?php endif; ?>
			>
				<?php echo esc_html( $interval_label[ $key ] ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $enabled_intervals as $key ) :
		$block       = $config[ $key ];
		$is_active   = ( $key === $active_interval );
		$panel_id    = $form_uid . '-panel-' . $key;
		$tab_id      = $form_uid . '-tab-' . $key;
		$preset_name = 'dfwc_preset_' . $form_uid;
		$default_idx = (int) $block['default_index'];
		?>
		<fieldset
			class="dfwc-form__panel"
			role="tabpanel"
			id="<?php echo esc_attr( $panel_id ); ?>"
			aria-labelledby="<?php echo esc_attr( $tab_id ); ?>"
			data-dfwc-panel="<?php echo esc_attr( $key ); ?>"
			<?php if ( ! $is_active ) : ?>hidden<?php endif; ?>
		>
			<legend class="screen-reader-text">
				<?php echo esc_html( $interval_label[ $key ] ); ?>
			</legend>

			<?php if ( ! empty( $block['presets'] ) ) : ?>
				<div class="dfwc-form__presets" role="radiogroup" aria-label="<?php esc_attr_e( 'Preset donation amounts', 'dfwc-companion' ); ?>">
					<?php foreach ( $block['presets'] as $idx => $preset ) :
						$preset_id = $form_uid . '-' . $key . '-preset-' . (int) $idx;
						$amount    = (float) $preset['amount'];
						$checked   = ( $idx === $default_idx );
						?>
						<input
							type="radio"
							name="<?php echo esc_attr( $preset_name . '_' . $key ); ?>"
							id="<?php echo esc_attr( $preset_id ); ?>"
							value="<?php echo esc_attr( (string) $amount ); ?>"
							class="dfwc-form__preset-input screen-reader-text"
							data-dfwc-preset
							data-amount="<?php echo esc_attr( (string) $amount ); ?>"
							<?php checked( $checked ); ?>
						>
						<label for="<?php echo esc_attr( $preset_id ); ?>" class="dfwc-form__preset-label">
							<span class="dfwc-form__preset-amount">
								<?php echo wp_kses( wc_price( $amount ), Renderer::allowed_price_tags() ); ?>
							</span>
							<?php if ( '' !== (string) $preset['label'] ) : ?>
								<span class="dfwc-form__preset-tag"><?php echo esc_html( $preset['label'] ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="dfwc-form__custom">
				<label for="<?php echo esc_attr( $form_uid . '-' . $key . '-custom' ); ?>" class="dfwc-form__custom-label">
					<?php esc_html_e( 'Or enter your own amount', 'dfwc-companion' ); ?>
				</label>
				<div class="dfwc-form__custom-input-wrap">
					<span class="dfwc-form__currency" aria-hidden="true">
						<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol() ) ); ?>
					</span>
					<input
						type="number"
						class="dfwc-form__custom-input"
						id="<?php echo esc_attr( $form_uid . '-' . $key . '-custom' ); ?>"
						name="<?php echo esc_attr( 'dfwc_custom_' . $form_uid . '_' . $key ); ?>"
						inputmode="decimal"
						step="0.01"
						min="<?php echo esc_attr( (string) (float) $block['min'] ); ?>"
						max="<?php echo esc_attr( (string) (float) $block['max'] ); ?>"
						placeholder="<?php
							/* translators: 1: minimum donation amount, 2: maximum donation amount */
							echo esc_attr( sprintf( __( 'Between %1$s and %2$s', 'dfwc-companion' ), wp_strip_all_tags( wc_price( (float) $block['min'] ) ), wp_strip_all_tags( wc_price( (float) $block['max'] ) ) ) );
						?>"
						data-dfwc-custom
					>
				</div>
			</div>
		</fieldset>
	<?php endforeach; ?>

	<button
		type="submit"
		class="dfwc-form__cta"
		data-dfwc-cta
	>
		<?php echo $initial_cta_html; // wp_kses-sanitized inside format_cta(). ?>
	</button>

	<div class="dfwc-form__error" role="alert" data-dfwc-error hidden></div>
</form>
