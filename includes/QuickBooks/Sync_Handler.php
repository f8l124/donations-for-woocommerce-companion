<?php
/**
 * Sync_Handler — the worker that processes a queued sync job.
 *
 * Action Scheduler invokes `process()` for each enqueued job (via the
 * `dfwc_qbo_sync_donation` hook). The handler:
 *
 *   1. Resolves the QBO income account via Account_Mapper.
 *   2. Builds the Sales Receipt payload.
 *   3. Calls API_Client::create_sales_receipt.
 *   4. On success: appends a `success` entry to the sync log.
 *   5. On retryable failure (5xx, timeout, 401): reschedules via Sync_Queue.
 *   6. On permanent failure (4xx other than 401): marks failed in log; no retry.
 *
 * The handler treats the donation payload as aggregate-only: campaign_id,
 * amount, currency, context, language. No donor PII flows through the
 * sync surface — same posture as Phase 9.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

final class Sync_Handler {

	public function __construct() {
		add_action( Sync_Queue::ACTION_SLUG, array( $this, 'process' ), 10, 1 );
	}

	/**
	 * Action Scheduler entry point. The single argument is the job array
	 * we enqueued in Sync_Queue::enqueue.
	 *
	 * @param array<string,mixed> $job
	 */
	public function process( $job ): void {
		if ( ! is_array( $job ) ) {
			return;
		}

		$campaign_id = (int) ( $job['campaign_id'] ?? 0 );
		$amount      = (float) ( $job['amount'] ?? 0.0 );
		$currency    = (string) ( $job['currency'] ?? '' );

		if ( $campaign_id < 1 || $amount <= 0 ) {
			Sync_Queue::log(
				array(
					'status'      => 'failed',
					'message'     => __( 'Invalid job payload (campaign_id or amount); dropped.', 'dfwc-companion' ),
					'campaign_id' => $campaign_id,
					'amount'      => $amount,
					'attempt'     => (int) ( $job['attempt'] ?? 0 ),
				)
			);
			return;
		}

		$account_id = Account_Mapper::resolve( $campaign_id );
		if ( '' === $account_id ) {
			// Defer with retry — admin may be configuring mappings right now.
			$job['last_error'] = 'No QBO account mapped for this campaign and no default mapping set.';
			Sync_Queue::reschedule( $job );
			Sync_Queue::log(
				array(
					'status'      => 'deferred',
					'message'     => $job['last_error'],
					'campaign_id' => $campaign_id,
					'amount'      => $amount,
					'attempt'     => (int) $job['attempt'],
				)
			);
			return;
		}

		$payload = self::build_payload( $campaign_id, $amount, $currency, $account_id, $job );
		$result  = API_Client::create_sales_receipt( $payload );

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			$is_retryable = self::is_retryable( $status, $result->get_error_code() );
			$job['last_error'] = $result->get_error_message();

			if ( $is_retryable ) {
				Sync_Queue::reschedule( $job );
				Sync_Queue::log(
					array(
						'status'      => 'error',
						'message'     => 'Retryable: ' . $result->get_error_message(),
						'campaign_id' => $campaign_id,
						'amount'      => $amount,
						'attempt'     => (int) ( $job['attempt'] ?? 0 ),
					)
				);
				return;
			}

			Sync_Queue::log(
				array(
					'status'      => 'failed',
					'message'     => 'Non-retryable: ' . $result->get_error_message(),
					'campaign_id' => $campaign_id,
					'amount'      => $amount,
					'attempt'     => (int) ( $job['attempt'] ?? 0 ),
				)
			);
			return;
		}

		Sync_Queue::log(
			array(
				'status'      => 'success',
				'message'     => sprintf(
					/* translators: %s: QBO Sales Receipt ID */
					__( 'Synced as Sales Receipt %s', 'dfwc-companion' ),
					(string) $result
				),
				'campaign_id' => $campaign_id,
				'amount'      => $amount,
				'attempt'     => (int) ( $job['attempt'] ?? 0 ),
				'receipt_id'  => (string) $result,
			)
		);
	}

	/**
	 * Build the QBO Sales Receipt JSON payload. Minimal-but-valid: line
	 * item against the mapped Income account. Customer reference is
	 * deliberately a generic "Anonymous donor" since we don't ship PII to
	 * QBO — admins who want named-customer flow can layer that on via
	 * the `dfwc_companion_qbo_payload` filter.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private static function build_payload(
		int $campaign_id,
		float $amount,
		string $currency,
		string $account_id,
		array $job
	): array {
		$campaign_title = function_exists( 'get_the_title' )
			? (string) get_the_title( $campaign_id )
			: '';
		$context        = (string) ( $job['context'] ?? '' );

		$description = sprintf(
			/* translators: 1: campaign title, 2: context */
			__( 'Donation — %1$s (%2$s)', 'dfwc-companion' ),
			'' !== $campaign_title ? $campaign_title : sprintf( 'Campaign #%d', $campaign_id ),
			'' !== $context ? $context : 'donation'
		);

		$payload = array(
			'TxnDate'           => gmdate( 'Y-m-d', (int) ( $job['occurred_at'] ?? time() ) ),
			'PrivateNote'       => sprintf( 'dfwc-companion campaign=%d context=%s', $campaign_id, $context ),
			'Line'              => array(
				array(
					'Description'         => $description,
					'Amount'              => round( $amount, 2 ),
					'DetailType'          => 'SalesItemLineDetail',
					'SalesItemLineDetail' => array(
						'ItemAccountRef' => array(
							'value' => $account_id,
						),
					),
				),
			),
		);
		if ( '' !== $currency ) {
			$payload['CurrencyRef'] = array( 'value' => strtoupper( $currency ) );
		}

		/**
		 * Filter the QBO Sales Receipt payload before submit. Use to add
		 * customer references, classes, locations, etc. Receives the
		 * payload + the source job array.
		 *
		 * @param array<string,mixed> $payload Sales-receipt body that will be POSTed.
		 * @param array<string,mixed> $job     Source job (campaign_id, amount, currency, context, ...).
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed filter
		return (array) apply_filters( 'dfwc_companion_qbo_payload', $payload, $job );
	}

	/**
	 * Decide whether a failed call should be retried. Idempotent retries
	 * only — we never retry 4xx (except 401, which API_Client already
	 * handles via auto-refresh + single retry).
	 */
	private static function is_retryable( int $status, string $error_code ): bool {
		// Network-layer failures from wp_remote_*: empty status + WP_Error code.
		if ( 0 === $status ) {
			$network_codes = array( 'http_request_failed', 'http_request_timeout', 'http_request_failed_curl' );
			return in_array( $error_code, $network_codes, true );
		}
		// 5xx is always retryable; 408 is a request timeout.
		if ( 408 === $status || $status >= 500 ) {
			return true;
		}
		// 429 rate-limited.
		if ( 429 === $status ) {
			return true;
		}
		return false;
	}
}
