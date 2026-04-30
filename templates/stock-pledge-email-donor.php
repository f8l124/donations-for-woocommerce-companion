<?php
/**
 * Stock pledge — donor confirmation email.
 *
 * Variables in scope (from Stock_Pledge_Email_Sender::render_template):
 *   array  $pledge         Full pledge data from Stock_Pledge_Handler::get_pledge.
 *   array  $global         Global settings (stock_broker_name, stock_dtc_*, stock_tax_id).
 *   string $site_name      get_bloginfo( 'name' ).
 *   string $campaign_title get_the_title( $pledge['campaign_id'] ).
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

$broker_name             = (string) ( $global['stock_broker_name'] ?? '' );
$dtc_account_number      = (string) ( $global['stock_dtc_account_number'] ?? '' );
$dtc_clearing_house      = (string) ( $global['stock_dtc_clearing_house_number'] ?? '' );
$tax_id                  = (string) ( $global['stock_tax_id'] ?? '' );
$shares_formatted        = rtrim( rtrim( number_format( (float) $pledge['shares'], 4, '.', '' ), '0' ), '.' );
$estimated_value_display = function_exists( 'wc_price' )
	? wp_strip_all_tags( wc_price( (float) $pledge['estimated_value'] ) )
	: '$' . number_format( (float) $pledge['estimated_value'], 2 );
?>
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #1d2327; line-height: 1.5;">

<h2 style="color: #065f46;"><?php esc_html_e( 'Thank you for your stock pledge', 'dfwc-companion' ); ?></h2>

<p>
	<?php
	printf(
		/* translators: 1: donor name, 2: site/org name */
		esc_html__( 'Hi %1$s — thank you for your generous pledge to %2$s. Your gift makes a real difference.', 'dfwc-companion' ),
		esc_html( $pledge['donor_name'] ),
		esc_html( $site_name )
	);
	?>
</p>

<h3 style="color: #1d2327; margin-top: 24px;"><?php esc_html_e( 'Pledge details', 'dfwc-companion' ); ?></h3>

<table cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">
	<tr>
		<td style="font-weight: 600; width: 40%;"><?php esc_html_e( 'Pledge ID', 'dfwc-companion' ); ?></td>
		<td>#<?php echo (int) $pledge['id']; ?></td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Campaign', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $campaign_title ); ?></td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Stock', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $pledge['ticker'] ); ?></td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Shares', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $shares_formatted ); ?></td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Estimated value', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $estimated_value_display ); ?></td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Your broker', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $pledge['broker_name'] ); ?></td>
	</tr>
</table>

<h3 style="color: #1d2327; margin-top: 24px;"><?php esc_html_e( 'Next steps — DTC transfer instructions', 'dfwc-companion' ); ?></h3>

<p><?php esc_html_e( 'Please forward the following instructions to your broker to initiate the share transfer:', 'dfwc-companion' ); ?></p>

<table cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; background: #ecfdf5; border: 1px solid #6ee7b7; border-left: 4px solid #10b981; border-radius: 6px;">
	<?php if ( '' !== $broker_name ) : ?>
	<tr>
		<td style="font-weight: 600; width: 40%;"><?php esc_html_e( 'Receiving broker', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $broker_name ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( '' !== $dtc_account_number ) : ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'DTC account number', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $dtc_account_number ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( '' !== $dtc_clearing_house ) : ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'DTC clearing-house number', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $dtc_clearing_house ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Receiving organization', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $site_name ); ?></td>
	</tr>
	<?php if ( '' !== $tax_id ) : ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Tax ID (EIN)', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $tax_id ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Reference', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( sprintf( /* translators: %d: pledge ID */ __( 'Pledge #%d', 'dfwc-companion' ), (int) $pledge['id'] ) ); ?></td>
	</tr>
</table>

<h3 style="color: #1d2327; margin-top: 24px;"><?php esc_html_e( 'What happens next', 'dfwc-companion' ); ?></h3>

<ol>
	<li><?php esc_html_e( 'Forward the DTC instructions above to your broker.', 'dfwc-companion' ); ?></li>
	<li><?php esc_html_e( 'Your broker will initiate the transfer to our brokerage account; this typically takes 3–10 business days.', 'dfwc-companion' ); ?></li>
	<li><?php esc_html_e( 'Once the shares clear, we\'ll send you a tax-deductible receipt with the fair market value at the transfer date.', 'dfwc-companion' ); ?></li>
</ol>

<p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
	<?php esc_html_e( 'For tax purposes, your charitable deduction is the fair market value of the shares on the date of transfer (not your original cost basis). Please consult your tax advisor for guidance.', 'dfwc-companion' ); ?>
</p>

<p style="color: #6b7280; font-size: 13px;">
	<?php
	printf(
		/* translators: %s: site name */
		esc_html__( 'If you have any questions, please reach out — we\'re grateful for your support of %s.', 'dfwc-companion' ),
		esc_html( $site_name )
	);
	?>
</p>

</body>
</html>
