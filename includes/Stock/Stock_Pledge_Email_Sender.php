<?php
/**
 * Stock_Pledge_Email_Sender — donor confirmation + admin notification
 * emails for stock pledges.
 *
 * Two emails per pledge:
 *
 * 1. Donor confirmation — DTC instructions to forward to broker, plus
 *    pledge details for the donor's records. Includes the org's tax ID
 *    so the donor's broker can route correctly.
 * 2. Admin notification — sent to `stock_admin_email` global setting
 *    (or to the WP admin email as fallback). Lets staff know a pledge
 *    arrived so they can watch for the broker transfer.
 *
 * Both rendered via PHP templates under `templates/`. HTML emails by
 * default; admins can override via the standard WP template-locator
 * pattern (`wp-content/themes/your-theme/dfwc-companion/...`).
 *
 * Failures are logged to `error_log` but never bubble — pledge creation
 * shouldn't fail because the SMTP server is down.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Stock;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class Stock_Pledge_Email_Sender {

	public static function send_donor_confirmation( int $pledge_id ): bool {
		$pledge = Stock_Pledge_Handler::get_pledge( $pledge_id );
		if ( null === $pledge ) {
			return false;
		}

		$global = Config_Resolver::get_global_settings();
		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		$subject = sprintf(
			/* translators: %s: org/site name */
			__( 'Stock pledge received — %s', 'dfwc-companion' ),
			$site_name
		);

		$body = self::render_template(
			'stock-pledge-email-donor.php',
			array(
				'pledge'      => $pledge,
				'global'      => $global,
				'site_name'   => $site_name,
				'campaign_title' => function_exists( 'get_the_title' )
					? (string) get_the_title( $pledge['campaign_id'] )
					: '',
			)
		);

		return self::send( $pledge['donor_email'], $subject, $body );
	}

	public static function send_admin_notification( int $pledge_id ): bool {
		$pledge = Stock_Pledge_Handler::get_pledge( $pledge_id );
		if ( null === $pledge ) {
			return false;
		}

		$global    = Config_Resolver::get_global_settings();
		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		$admin_email = (string) ( $global['stock_admin_email'] ?? '' );
		if ( '' === $admin_email && function_exists( 'get_option' ) ) {
			$admin_email = (string) get_option( 'admin_email' );
		}
		if ( '' === $admin_email ) {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: ticker, 2: shares count */
			__( '[Stock Pledge] %1$s × %2$s', 'dfwc-companion' ),
			$pledge['ticker'],
			rtrim( rtrim( number_format( (float) $pledge['shares'], 4, '.', '' ), '0' ), '.' )
		);

		$body = self::render_template(
			'stock-pledge-email-admin.php',
			array(
				'pledge'      => $pledge,
				'site_name'   => $site_name,
				'campaign_title' => function_exists( 'get_the_title' )
					? (string) get_the_title( $pledge['campaign_id'] )
					: '',
				'admin_url'   => function_exists( 'admin_url' )
					? (string) admin_url( 'edit.php?post_type=' . Stock_Pledge_Post_Type::POST_TYPE )
					: '',
			)
		);

		return self::send( $admin_email, $subject, $body );
	}

	/**
	 * @param array<string,mixed> $vars
	 */
	private static function render_template( string $filename, array $vars ): string {
		$template_path = '';

		// Theme override location (standard WP template-locator pattern).
		if ( function_exists( 'locate_template' ) ) {
			$located = locate_template( array( 'dfwc-companion/' . $filename ) );
			if ( '' !== $located && file_exists( $located ) ) {
				$template_path = $located;
			}
		}

		if ( '' === $template_path ) {
			$template_path = ( defined( 'DFWC_COMPANION_PATH' ) ? DFWC_COMPANION_PATH : '' ) . 'templates/' . $filename;
		}

		if ( ! file_exists( $template_path ) ) {
			return '';
		}

		// Extract vars into the template's scope. Standard WP pattern.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- safe, scoped
		extract( $vars );

		ob_start();
		include $template_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile -- known-safe path
		return (string) ob_get_clean();
	}

	private static function send( string $to, string $subject, string $body ): bool {
		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $body, $headers );
		if ( ! $sent && function_exists( 'error_log' ) ) {
			error_log( '[dfwc-companion] Stock pledge email failed for ' . $to . ' (subject: ' . $subject . ')' );
		}
		return (bool) $sent;
	}
}
