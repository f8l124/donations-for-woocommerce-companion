<?php
/**
 * Donation_Listener — bridge between Phase 9 hooks and the QBO sync queue.
 *
 * Listens on `dfwc_companion_donation_submitted` (fires for cash AND
 * stock — Overflow webhook AND admin-marked-received pledge) at default
 * priority 10. When QBO sync is enabled and tokens are present, enqueues
 * a sync job carrying only aggregate fields. No donor PII flows from
 * here to QBO (matches Phase 9 privacy posture).
 *
 * Crypto donations (v1.4.0) will fire the same hook with `$context = 'crypto'`
 * so they'll sync to QBO automatically when shipped — no per-channel
 * wiring needed in this module.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class Donation_Listener {

	public function __construct() {
		add_action(
			'dfwc_companion_donation_submitted',
			array( $this, 'on_donation_submitted' ),
			10,
			7
		);
	}

	/**
	 * @param int    $campaign_id
	 * @param string $interval    e.g., 'one_time', 'monthly', 'custom'
	 * @param float  $amount
	 * @param string $context     'single' | 'shortcode' | 'stock' | ...
	 * @param string $currency    ISO 4217 (uppercased)
	 * @param string $language    locale code; '' if WPML inactive + locale unset
	 * @param string $reason      empty for success; populated only on `donation_failed`
	 */
	public function on_donation_submitted(
		$campaign_id,
		$interval,
		$amount,
		$context,
		$currency,
		$language,
		$reason
	): void {
		if ( ! self::is_sync_enabled() ) {
			return;
		}
		if ( ! Token_Store::has_tokens() ) {
			return;
		}

		Sync_Queue::enqueue(
			array(
				'campaign_id' => (int) $campaign_id,
				'interval'    => (string) $interval,
				'amount'      => (float) $amount,
				'context'     => (string) $context,
				'currency'    => (string) $currency,
				'language'    => (string) $language,
				'occurred_at' => time(),
			)
		);
	}

	private static function is_sync_enabled(): bool {
		$settings = Config_Resolver::get_global_settings();
		return ! empty( $settings['qbo_sync_enabled'] );
	}
}
