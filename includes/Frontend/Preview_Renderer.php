<?php
/**
 * Preview_Renderer — render the donor-facing form for the admin preview pane.
 *
 * Mirrors `Frontend\Renderer` but takes config directly instead of reading
 * post meta. Produces a self-contained HTML snippet the admin preview
 * iframe loads inline (no external network requests).
 *
 * Mock-parent scope: the rendered HTML mocks parent's `.wc-donation-in-action`
 * + `.row1` + the hidden inputs the overlay JS expects to find. This way
 * the same overlay JS that runs on the donor frontend also runs in the
 * preview iframe — same code path = same visual result.
 *
 * Submission prevention: the wrapper carries `data-preview="1"` so the
 * overlay JS short-circuits its submit handler, and the mock submit
 * button is `disabled`.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Engine_Interval_Map;
use DFWC\Companion\Engine_Detector;

final class Preview_Renderer {

	/**
	 * Render preview HTML for the supplied (already-sanitized) config.
	 *
	 * @param array<string,mixed>     $config Sanitized config (Template_Validator output).
	 * @param array<string,mixed>     $args   Optional rendering args:
	 *                                        - engine:    'auto' | 'wcs' | 'wps_sfw' | 'none'
	 *                                        - currency:  ISO currency code; passed through to overlay JS
	 *                                        - viewport:  'desktop' | 'tablet' | 'mobile'
	 *                                        - language:  WPML language code (only when WPML active)
	 * @return string Sanitized HTML safe to set as iframe srcdoc.
	 */
	public function render( array $config, array $args = array() ): string {
		$engine = isset( $args['engine'] ) ? (string) $args['engine'] : 'auto';
		if ( '' === $engine || 'auto' === $engine ) {
			$engine = Engine_Detector::detect();
		}

		$enabled_intervals = $this->resolve_enabled_intervals( $config, $engine );
		$active_interval   = ! empty( $enabled_intervals )
			? $enabled_intervals[0]
			: Config_Resolver::INTERVAL_ONE_TIME;

		// Phase 6 — preview honors the toolbar's currency selector. Empty /
		// "auto" passes through to active_currency() (WCML / WC base). The
		// preview pane lets admins eyeball what donors in each currency see.
		$preview_currency = isset( $args['currency'] ) ? (string) $args['currency'] : '';
		if ( '' === $preview_currency || 'auto' === $preview_currency ) {
			$preview_currency = Currency_Preset_Resolver::active_currency();
		}

		// Build the same shape `Renderer::build_form_config` produces so the
		// overlay JS doesn't know it's looking at preview vs production.
		$form_config = $this->build_form_config( $config, $enabled_intervals, $preview_currency );

		$display = isset( $config['display'] ) && is_array( $config['display'] )
			? $config['display']
			: Defaults::display();

		$viewport = isset( $args['viewport'] ) ? (string) $args['viewport'] : 'desktop';
		$viewport = in_array( $viewport, array( 'desktop', 'tablet', 'mobile' ), true ) ? $viewport : 'desktop';

		ob_start();
		?>
		<div
			class="dfwc-overlay dfwc-preview dfwc-preview--<?php echo esc_attr( $viewport ); ?>"
			data-dfwc-overlay-target
			data-campaign-id="0"
			data-engine="<?php echo esc_attr( $engine ); ?>"
			data-active-interval="<?php echo esc_attr( $active_interval ); ?>"
			data-config="<?php echo esc_attr( (string) wp_json_encode( $form_config ) ); ?>"
			data-intervals="<?php echo esc_attr( (string) wp_json_encode( $enabled_intervals ) ); ?>"
			data-display="<?php echo esc_attr( (string) wp_json_encode( $display ) ); ?>"
			data-context="preview"
			data-language="<?php echo esc_attr( isset( $args['language'] ) ? (string) $args['language'] : '' ); ?>"
			data-fully-funded="0"
			data-general-fund-url=""
			data-stock-pledge-enabled="0"
			data-stock-mode="pledge_form"
			data-stock-overflow-url=""
			data-closed-causes="{}"
			data-crypto-enabled="0"
			data-tgb-environment=""
			data-tgb-org-id=""
			data-tgb-project-id=""
			data-crypto-pending-url=""
			data-preview="1"
		>
			<div class="wc-donation-in-action dfwc-preview__scope">
				<?php if ( ! empty( $display['show_title'] ) ) : ?>
					<h2 class="campaign-title dfwc-preview__title"><?php esc_html_e( '(Preview campaign title)', 'dfwc-companion' ); ?></h2>
				<?php endif; ?>

				<?php if ( ! empty( $display['show_image'] ) ) : ?>
					<div class="block-campaign-thumbnail dfwc-preview__image" aria-hidden="true">
						<span class="dfwc-preview__image-placeholder"><?php esc_html_e( 'Campaign image', 'dfwc-companion' ); ?></span>
					</div>
				<?php endif; ?>

				<div class="row2">
					<h3 class="wc-donation-title">
						<?php
						$heading = isset( $display['cause_heading'] ) && '' !== $display['cause_heading']
							? (string) $display['cause_heading']
							: __( 'Select Cause', 'dfwc-companion' );
						echo esc_html( $heading );
						?>
					</h3>
					<select class="dfwc-preview__cause-select" disabled aria-label="<?php esc_attr_e( 'Cause selector (disabled in preview)', 'dfwc-companion' ); ?>">
						<option><?php esc_html_e( '— Preview cause —', 'dfwc-companion' ); ?></option>
					</select>
				</div>

				<!--
					Mock parent's amount block. The overlay JS finds `.row1`,
					hides it, and inserts our 3-tab UI in its place.
				-->
				<div class="row1 dfwc-preview__row1">
					<select class="dfwc-preview__amount-select" disabled>
						<option>--Please select--</option>
					</select>
				</div>

				<!-- Mock parent's hidden inputs the overlay writes to. -->
				<input type="hidden" name="wc-donation-price" value="">
				<input type="hidden" name="wc-donation-cause" value="">
				<input type="hidden" class="wc_donation_camp" value="0">
				<input type="hidden" class="wp_rand" value="preview">

				<!-- Mock recurring controls (overlay writes to these for monthly/annual). -->
				<input type="checkbox" class="donation-is-recurring" style="display:none">

				<!-- Mock submit button. Disabled + data-preview prevents click submit. -->
				<button
					type="button"
					class="wc-donation-f-submit-donation dfwc-preview__submit"
					disabled
					aria-label="<?php esc_attr_e( 'Donate (disabled in preview)', 'dfwc-companion' ); ?>"
				>
					<?php esc_html_e( 'Donate', 'dfwc-companion' ); ?>
				</button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Compute which intervals are enabled given the engine state. Mirrors
	 * Renderer::resolve_enabled_intervals.
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,string>
	 */
	private function resolve_enabled_intervals( array $config, string $engine ): array {
		$out            = array();
		$recurring_ok   = Engine_Detector::ENGINE_NONE !== $engine;
		// Phase 7 — all non-one_time intervals are recurring.
		$recurring_keys = array_diff(
			Config_Resolver::intervals( true ),
			array( Config_Resolver::INTERVAL_ONE_TIME )
		);

		foreach ( Config_Resolver::intervals() as $key ) {
			if ( ! ( $config[ $key ]['enabled'] ?? false ) ) {
				continue;
			}
			if ( in_array( $key, $recurring_keys, true ) && ! $recurring_ok ) {
				continue;
			}
			if ( empty( $config[ $key ]['presets'] ) && ( $config[ $key ]['min'] ?? 0 ) <= 0 ) {
				continue;
			}
			$out[] = $key;
		}

		if ( empty( $out ) ) {
			$out[] = Config_Resolver::INTERVAL_ONE_TIME;
		}
		return $out;
	}

	/**
	 * Build the form_config payload consumed by the overlay JS. Mirrors
	 * Renderer::build_form_config so the same JS code path serves both.
	 *
	 * @param array<string,mixed> $config
	 * @param array<int,string>   $enabled_intervals
	 * @return array<string,array<string,mixed>>
	 */
	private function build_form_config( array $config, array $enabled_intervals, string $currency = '' ): array {
		$out             = array();
		$interval_labels = array(
			Config_Resolver::INTERVAL_ONE_TIME   => __( 'One-time', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_MONTHLY    => __( 'Monthly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_ANNUAL     => __( 'Annually', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_WEEKLY     => __( 'Weekly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_QUARTERLY  => __( 'Quarterly', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_SEMIANNUAL => __( 'Semi-annually', 'dfwc-companion' ),
			Config_Resolver::INTERVAL_CUSTOM     => __( 'Custom', 'dfwc-companion' ),
		);
		$resolved_currency = '' !== $currency ? $currency : Currency_Preset_Resolver::active_currency();
		foreach ( $enabled_intervals as $key ) {
			$block   = Currency_Preset_Resolver::resolve( $config[ $key ], $resolved_currency );
			$cadence = Engine_Interval_Map::for_interval( $key, $block );
			$entry   = array(
				'min'                        => (float) $block['min'],
				'max'                        => (float) $block['max'],
				'default_index'              => (int) $block['default_index'],
				'cta_template'               => (string) $block['cta_template'],
				'label'                      => (string) ( $interval_labels[ $key ] ?? $key ),
				'custom_amount_enabled'      => ! empty( $block['custom_amount_enabled'] ),
				'subtitle'                   => (string) ( $block['subtitle'] ?? '' ),
				'annual_equivalency'         => (string) ( $block['annual_equivalency'] ?? '' ),
				'impact_display_mode'        => (string) ( $block['impact_display_mode'] ?? 'below_button' ),
				'custom_amount_impact_label' => (string) ( $block['custom_amount_impact_label'] ?? '' ),
				'currency'                   => (string) ( $block['_resolved_currency'] ?? $resolved_currency ),
				'cadence'                    => null !== $cadence
					? array(
						'period'   => (string) $cadence['period'],
						'interval' => (int) $cadence['interval'],
					)
					: null,
				'presets'                    => array_map(
					static function ( $p ) {
						return array(
							'amount'       => (float) ( $p['amount'] ?? 0 ),
							'label'        => (string) ( $p['label'] ?? '' ),
							'impact_label' => (string) ( $p['impact_label'] ?? '' ),
							'is_featured'  => ! empty( $p['is_featured'] ),
						);
					},
					(array) ( $block['presets'] ?? array() )
				),
			);
			if ( Config_Resolver::INTERVAL_CUSTOM === $key ) {
				$entry['custom_label'] = (string) ( $block['custom_label'] ?? '' );
			}
			$out[ $key ] = $entry;
		}
		return $out;
	}
}
