<?php
/**
 * Stock pledge — admin notification email.
 *
 * Variables in scope:
 *   array  $pledge         Full pledge data.
 *   string $site_name
 *   string $campaign_title
 *   string $admin_url      Link to the Stock Pledges list in admin.
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

$shares_formatted        = rtrim( rtrim( number_format( (float) $pledge['shares'], 4, '.', '' ), '0' ), '.' );
$estimated_value_display = function_exists( 'wc_price' )
	? wp_strip_all_tags( wc_price( (float) $pledge['estimated_value'] ) )
	: '$' . number_format( (float) $pledge['estimated_value'], 2 );
?>
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #1d2327; line-height: 1.5;">

<h2 style="color: #1d2327;"><?php esc_html_e( 'New stock pledge', 'dfwc-companion' ); ?></h2>

<p>
	<?php
	printf(
		/* translators: 1: ticker, 2: shares count */
		esc_html__( 'A donor pledged %1$s × %2$s shares. Watch for the broker transfer in the next 3–10 business days.', 'dfwc-companion' ),
		'<strong>' . esc_html( $pledge['ticker'] ) . '</strong>',
		'<strong>' . esc_html( $shares_formatted ) . '</strong>'
	);
	?>
</p>

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
		<td style="font-weight: 600;"><?php esc_html_e( 'Donor', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $pledge['donor_name'] ); ?> &lt;<?php echo esc_html( $pledge['donor_email'] ); ?>&gt;</td>
	</tr>
	<?php if ( '' !== $pledge['donor_phone'] ) : ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Phone', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $pledge['donor_phone'] ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Stock', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $pledge['ticker'] ); ?> × <?php echo esc_html( $shares_formatted ); ?> shares</td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Estimated value', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $estimated_value_display ); ?></td>
	</tr>
	<tr>
		<td style="font-weight: 600;"><?php esc_html_e( 'Donor\'s broker', 'dfwc-companion' ); ?></td>
		<td><?php echo esc_html( $pledge['broker_name'] ); ?></td>
	</tr>
</table>

<?php if ( '' !== $pledge['donor_notes'] ) : ?>
<h3 style="color: #1d2327; margin-top: 24px;"><?php esc_html_e( 'Donor notes', 'dfwc-companion' ); ?></h3>
<blockquote style="border-left: 3px solid #d1d5db; padding-left: 12px; color: #4b5563; margin: 8px 0;">
	<?php echo nl2br( esc_html( $pledge['donor_notes'] ) ); ?>
</blockquote>
<?php endif; ?>

<p style="margin-top: 24px;">
	<?php if ( '' !== $admin_url ) : ?>
		<a href="<?php echo esc_url( $admin_url ); ?>" style="display: inline-block; padding: 10px 16px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600;">
			<?php esc_html_e( 'Review pledges in admin', 'dfwc-companion' ); ?>
		</a>
	<?php endif; ?>
</p>

<p style="color: #6b7280; font-size: 13px; margin-top: 24px;">
	<?php esc_html_e( 'Mark the pledge "received" once the shares clear in your brokerage account. The companion will record the actual fair market value at transfer date and fire the donation_submitted hook for any analytics / CRM listeners you have configured.', 'dfwc-companion' ); ?>
</p>

</body>
</html>
