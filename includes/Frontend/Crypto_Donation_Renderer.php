<?php
/**
 * Crypto_Donation_Renderer — server-side gating + data-attribute computation
 * for the donor-facing crypto donation button.
 *
 * Pure read-side: never mutates state. Two responsibilities:
 *
 *   should_render( $campaign_id, $context ) : bool
 *     - True when crypto is globally enabled, TGB credentials are stored,
 *       per-campaign override doesn't disable, and the render context is
 *       a real donor surface (not the admin preview).
 *
 *   get_data_attributes( $campaign_id ) : array
 *     - Returns the data-* keys + values to emit on the overlay wrapper.
 *       Public-only (no api_key, no webhook_secret).
 *
 * The actual button + widget DOM is built client-side in dfwc-crypto.js
 * by reading these attributes — keeps the server side small and the
 * cloned-DOM pattern (Elementor Pro popups, modal libraries) consistent
 * with how stock pledges and the goal-met card render.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Gateways\TGB_Token_Store;

final class Crypto_Donation_Renderer {

	/**
	 * Decide whether to surface the "Donate Crypto" button for this
	 * campaign + context combo.
	 */
	public static function should_render( int $campaign_id, string $context = 'shortcode' ): bool {
		// Admin preview never shows the crypto button — admins don't need
		// the live TGB widget to verify their layout, and we'd otherwise
		// be loading TGB's script into the iframe unnecessarily.
		if ( 'preview' === $context ) {
			return false;
		}

		$global = Config_Resolver::get_global_settings();
		if ( empty( $global['crypto_donations_enabled'] ) ) {
			return false;
		}

		// Credentials must be stored AND decryptable. Decryption failure
		// (AUTH_KEY rotated, blob corrupted) returns false here, surfacing
		// the disconnect-and-reconnect path.
		if ( ! ( new TGB_Token_Store() )->is_configured() ) {
			return false;
		}

		// Per-campaign opt-out lives under the layered overrides
		// (`_dfwc_companion_overrides.crypto.enabled`). Default is true —
		// when the global toggle is on, every campaign gets the button
		// unless explicitly disabled. Per-campaign admin UI lands in 13.E.
		$campaign_crypto = self::resolve_campaign_crypto_overrides( $campaign_id );
		if ( array_key_exists( 'enabled', $campaign_crypto ) && ! $campaign_crypto['enabled'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the data-* attributes the overlay wrapper carries when crypto
	 * is enabled. Caller emits these after the existing data attrs.
	 *
	 * Public-safe: no api_key, no webhook_secret. Donor-side JS only needs
	 * the public org_id + project_id + environment + the pending-order REST
	 * URL.
	 *
	 * @return array<string,string>
	 */
	public static function get_data_attributes( int $campaign_id ): array {
		$global          = Config_Resolver::get_global_settings();
		$campaign_crypto = self::resolve_campaign_crypto_overrides( $campaign_id );

		$environment = isset( $global['tgb_environment'] ) ? (string) $global['tgb_environment'] : 'sandbox';
		if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
			$environment = 'sandbox';
		}

		$org_id  = isset( $global['tgb_organization_id'] ) ? (string) $global['tgb_organization_id'] : '';
		$default = isset( $global['tgb_default_project_id'] ) ? (string) $global['tgb_default_project_id'] : '';

		// Per-campaign project ID wins over org default. Empty string when
		// neither set; the donor-side JS surfaces a console warning rather
		// than crashing — the admin gets a "no project configured" diagnostic
		// in 13.F.
		$project_id = isset( $campaign_crypto['tgb_project_id'] ) && '' !== (string) $campaign_crypto['tgb_project_id']
			? (string) $campaign_crypto['tgb_project_id']
			: $default;

		$pending_url = function_exists( 'rest_url' )
			? (string) rest_url( 'dfwc-companion/v1/crypto-pending-order' )
			: '';

		return array(
			'data-crypto-enabled'      => '1',
			'data-tgb-environment'     => $environment,
			'data-tgb-org-id'          => $org_id,
			'data-tgb-project-id'      => $project_id,
			'data-crypto-pending-url'  => $pending_url,
		);
	}

	/**
	 * The "off" set — emitted when should_render() is false so client-side
	 * code can read a single canonical attribute (`data-crypto-enabled="0"`)
	 * to decide whether to even attempt to render.
	 *
	 * @return array<string,string>
	 */
	public static function get_disabled_attributes(): array {
		return array(
			'data-crypto-enabled'      => '0',
			'data-tgb-environment'     => '',
			'data-tgb-org-id'          => '',
			'data-tgb-project-id'      => '',
			'data-crypto-pending-url'  => '',
		);
	}

	/**
	 * Read `crypto` overrides from the per-campaign layered store. Returns
	 * an empty array when the campaign has no crypto-specific config.
	 *
	 * @return array<string,mixed>
	 */
	private static function resolve_campaign_crypto_overrides( int $campaign_id ): array {
		$overrides = get_post_meta( $campaign_id, '_dfwc_companion_overrides', true );
		if ( ! is_array( $overrides ) || empty( $overrides['crypto'] ) || ! is_array( $overrides['crypto'] ) ) {
			return array();
		}
		return $overrides['crypto'];
	}
}
