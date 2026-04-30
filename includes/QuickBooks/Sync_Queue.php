<?php
/**
 * Sync_Queue — Action Scheduler-backed queue for QBO sync jobs.
 *
 * Every donation that lands a Phase 9 hook gets enqueued here. A worker
 * (`Sync_Handler::process`) is invoked asynchronously by Action Scheduler
 * (which ships with WC core). On failure the worker re-enqueues with
 * exponential backoff (1m → 5m → 15m); after 3 failures the job is
 * marked permanently failed and surfaces in the admin sync log.
 *
 * Why Action Scheduler (not WP-Cron):
 *   - AS guarantees execution even on traffic-starved sites (no need for
 *     a hit to wp-cron.php; AS hooks into shutdown if there's a worker
 *     pending).
 *   - AS provides admin UI + audit log for free at Tools → Scheduled Actions.
 *   - AS ships with WooCommerce core, so requiring it adds zero install
 *     friction for our user base (WC is already required).
 *
 * Storage: AS owns the queue tables. We additionally write a sync log
 * (`dfwc_qbo_sync_log` option, last 50 entries, ring-buffer) so the admin
 * UI can show a tight history without scanning AS's full log.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

final class Sync_Queue {

	public const ACTION_SLUG  = 'dfwc_qbo_sync_donation';
	public const LOG_OPTION   = 'dfwc_qbo_sync_log';
	public const LOG_MAX      = 50;
	public const MAX_RETRIES  = 3;
	public const GROUP        = 'dfwc-companion-qbo';

	/**
	 * Backoff schedule (seconds). Index = attempt number (0 = first run).
	 *   attempt 0 → run immediately
	 *   attempt 1 → 60s after failure
	 *   attempt 2 → 300s
	 *   attempt 3 → 900s (then mark failed)
	 */
	private const BACKOFFS = array( 0, 60, 300, 900 );

	/**
	 * Enqueue a donation for sync. Called from the
	 * `dfwc_companion_donation_submitted` listener.
	 *
	 * @param array<string,mixed> $job Aggregate-only payload (no PII).
	 *   Required: campaign_id, amount, currency, context, language, interval, occurred_at.
	 */
	public static function enqueue( array $job ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler unavailable — log + bail. Admin will see the
			// diagnostic warning that AS isn't loaded.
			self::log(
				array(
					'status'      => 'error',
					'message'     => 'Action Scheduler not loaded — sync skipped.',
					'campaign_id' => (int) ( $job['campaign_id'] ?? 0 ),
					'amount'      => (float) ( $job['amount'] ?? 0 ),
					'attempt'     => 0,
				)
			);
			return;
		}
		$job['attempt'] = 0;
		as_enqueue_async_action( self::ACTION_SLUG, array( $job ), self::GROUP );
	}

	/**
	 * Re-enqueue with backoff after a transient failure. Caller is
	 * Sync_Handler when the API call returned a retryable error (5xx,
	 * timeout, etc.).
	 */
	public static function reschedule( array $job ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$attempt = (int) ( $job['attempt'] ?? 0 ) + 1;
		if ( $attempt > self::MAX_RETRIES ) {
			self::log(
				array(
					'status'      => 'failed',
					'message'     => sprintf(
						/* translators: 1: campaign id, 2: amount, 3: last error */
						__( 'Sync exceeded %1$d retries; giving up. Last error: %2$s', 'dfwc-companion' ),
						self::MAX_RETRIES,
						(string) ( $job['last_error'] ?? 'unknown' )
					),
					'campaign_id' => (int) ( $job['campaign_id'] ?? 0 ),
					'amount'      => (float) ( $job['amount'] ?? 0 ),
					'attempt'     => $attempt,
				)
			);
			return;
		}
		$job['attempt']  = $attempt;
		$backoffs        = self::BACKOFFS;
		$last_index      = count( $backoffs ) - 1;
		$delay           = $backoffs[ $attempt ] ?? $backoffs[ $last_index ];
		as_schedule_single_action( time() + (int) $delay, self::ACTION_SLUG, array( $job ), self::GROUP );
	}

	/**
	 * Append an entry to the bounded sync log. Older entries fall off the
	 * tail when the buffer reaches LOG_MAX.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function log( array $entry ): void {
		$entry['ts'] = time();
		$log         = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $entry;
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Read the sync log (newest last). Used by the admin UI.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear log + cancel any pending AS actions in our group. Called from
	 * the admin "Reset queue" button + on disconnect.
	 */
	public static function reset(): void {
		delete_option( self::LOG_OPTION );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_SLUG );
		}
	}

	/**
	 * Count failed sync attempts in the last N seconds. Used by the
	 * `qbo_sync_health` diagnostic check.
	 */
	public static function failures_since( int $seconds ): int {
		$cutoff = time() - $seconds;
		$count  = 0;
		foreach ( self::get_log() as $entry ) {
			$status = (string) ( $entry['status'] ?? '' );
			$ts     = (int) ( $entry['ts'] ?? 0 );
			if ( $ts < $cutoff ) {
				continue;
			}
			if ( 'failed' === $status || 'error' === $status ) {
				++$count;
			}
		}
		return $count;
	}
}
