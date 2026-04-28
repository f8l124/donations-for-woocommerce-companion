<?php
/**
 * Admin preview pane.
 *
 * Variables in scope (from Preview_Controller::*):
 *   array $context {
 *     string source       'campaign' | 'template' | 'settings'
 *     int    campaign_id  Only set when source='campaign'.
 *   }
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

$source      = isset( $context['source'] ) ? (string) $context['source'] : 'campaign';
$campaign_id = isset( $context['campaign_id'] ) ? (int) $context['campaign_id'] : 0;

$wpml_active = \DFWC\Companion\I18n\WPML_Strings::wpml_active();
$wcml_active = \DFWC\Companion\I18n\WPML_Strings::wcml_active();
?>
<div
	class="dfwc-preview-pane"
	data-dfwc-preview-pane
	data-source="<?php echo esc_attr( $source ); ?>"
	data-campaign-id="<?php echo (int) $campaign_id; ?>"
>
	<div class="dfwc-preview-pane__header">
		<h2 class="dfwc-preview-pane__title">
			<?php esc_html_e( 'Live preview', 'dfwc-companion' ); ?>
		</h2>
		<span class="dfwc-preview-pane__status" data-dfwc-preview-status>
			<?php esc_html_e( 'Make a change to see the preview update.', 'dfwc-companion' ); ?>
		</span>
	</div>

	<div class="dfwc-preview-pane__toolbar">
		<label class="dfwc-preview-pane__field">
			<span><?php esc_html_e( 'Viewport', 'dfwc-companion' ); ?></span>
			<select data-dfwc-preview-viewport>
				<option value="desktop"><?php esc_html_e( 'Desktop', 'dfwc-companion' ); ?></option>
				<option value="tablet"><?php esc_html_e( 'Tablet', 'dfwc-companion' ); ?></option>
				<option value="mobile"><?php esc_html_e( 'Mobile', 'dfwc-companion' ); ?></option>
			</select>
		</label>

		<label class="dfwc-preview-pane__field">
			<span><?php esc_html_e( 'Engine', 'dfwc-companion' ); ?></span>
			<select data-dfwc-preview-engine>
				<option value="auto"><?php esc_html_e( 'Active engine (auto-detect)', 'dfwc-companion' ); ?></option>
				<option value="wcs"><?php esc_html_e( 'Simulate: WC Subscriptions', 'dfwc-companion' ); ?></option>
				<option value="wps_sfw"><?php esc_html_e( 'Simulate: Subscriptions for WooCommerce', 'dfwc-companion' ); ?></option>
				<option value="none"><?php esc_html_e( 'Simulate: No engine (one-time only)', 'dfwc-companion' ); ?></option>
			</select>
		</label>

		<?php if ( $wpml_active ) : ?>
			<label class="dfwc-preview-pane__field">
				<span><?php esc_html_e( 'Language', 'dfwc-companion' ); ?></span>
				<select data-dfwc-preview-language>
					<option value=""><?php esc_html_e( 'Active language', 'dfwc-companion' ); ?></option>
					<!-- JS populates from dfwcAdminPreview.languages -->
				</select>
			</label>
		<?php endif; ?>

		<?php if ( $wcml_active ) : ?>
			<label class="dfwc-preview-pane__field">
				<span><?php esc_html_e( 'Currency', 'dfwc-companion' ); ?></span>
				<input
					type="text"
					data-dfwc-preview-currency
					maxlength="3"
					placeholder="<?php esc_attr_e( 'USD', 'dfwc-companion' ); ?>"
					style="width:80px;text-transform:uppercase"
				>
			</label>
		<?php endif; ?>
	</div>

	<div class="dfwc-preview-pane__viewport-wrap">
		<iframe
			class="dfwc-preview-pane__iframe"
			data-dfwc-preview-iframe
			sandbox="allow-same-origin allow-scripts"
			title="<?php esc_attr_e( 'Donor form preview', 'dfwc-companion' ); ?>"
			src="about:blank"
		></iframe>
	</div>

	<p class="dfwc-preview-pane__note description">
		<?php esc_html_e( 'Preview reflects unsaved changes. No donations are submitted from this view.', 'dfwc-companion' ); ?>
	</p>
</div>
