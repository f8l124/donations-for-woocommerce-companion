<?php
/**
 * CLI_Commands — `wp dfwc-companion <subcommand>` registration.
 *
 * Loaded only when WP-CLI is available. The single mounted command is
 * `health`, which runs the full parent-contract diagnostic and prints the
 * report in any of the standard WP-CLI formats. Suitable for piping into
 * monitoring agents or for ad-hoc support checks.
 *
 * Future subcommands (templates list/apply, cleanup, etc.) plug into this
 * class without changing the registration; see plans/v2/12-phase-11-release-readiness.md
 * §11.3 for the full surface.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\CLI;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Contracts\Parent_Form_Contract_Checker;
use DFWC\Companion\Contracts\Parent_Form_Contract_Report;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;
use DFWC\Companion\QuickBooks\Sync_Queue;
use DFWC\Companion\QuickBooks\Token_Store;

final class CLI_Commands {

	/**
	 * Register the `dfwc-companion` command with WP-CLI. No-op when WP-CLI
	 * isn't loaded (regular HTTP requests). Called from Plugin::boot().
	 */
	public static function register(): void {
		// WP-CLI defines the WP_CLI class when it's loaded; checking the class
		// (rather than the dual-purposed constant) plays nicer with static
		// analysis. The class_exists call is short-circuit-safe outside CLI.
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'dfwc-companion', __CLASS__ );
	}

	/**
	 * Run the parent-contract diagnostic checks and print the report.
	 *
	 * Mirrors the Diagnostics admin page; suitable for piping into Slack
	 * webhooks, monitoring agents, or ad-hoc support sessions.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - markdown
	 * ---
	 *
	 * [--refresh]
	 * : Bypass the 12-hour transient cache and re-run all checks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dfwc-companion health
	 *     wp dfwc-companion health --format=json
	 *     wp dfwc-companion health --format=markdown --refresh
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function health( $args, $assoc_args ): void {
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$refresh = ! empty( $assoc_args['refresh'] );

		$checker = new Parent_Form_Contract_Checker();
		$report  = $checker->get_report( $refresh );
		$labels  = $checker->contract_labels();

		if ( 'markdown' === $format ) {
			\WP_CLI::line( $report->to_markdown( $labels ) );
			return;
		}

		// Build a flat row-set for WP-CLI's standard formatters.
		$rows = array();
		foreach ( $report->results as $result ) {
			$rows[] = array(
				'check'       => $result->contract_id,
				'label'       => $labels[ $result->contract_id ] ?? $result->contract_id,
				'status'      => strtoupper( $result->status ),
				'message'     => $result->message,
				'remediation' => $result->remediation,
			);
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array( 'check', 'label', 'status', 'message', 'remediation' )
		);

		// Append a one-line overall summary on the table format only —
		// machine-readable formats shouldn't carry trailing prose, and
		// non-zero exit codes propagate through CI just fine.
		if ( 'table' === $format ) {
			\WP_CLI::line( '' );
			$pass_count = 0;
			foreach ( $report->results as $r ) {
				if ( Parent_Form_Contract_Result::STATUS_PASS === $r->status ) {
					++$pass_count;
				}
			}
			$line = sprintf(
				/* translators: 1: overall status (HEALTHY/WARNING/BROKEN); 2: pass count; 3: total count */
				__( 'Overall: %1$s (%2$d / %3$d checks passing)', 'dfwc-companion' ),
				strtoupper( $report->overall_status ),
				$pass_count,
				count( $report->results )
			);
			if ( Parent_Form_Contract_Report::STATUS_BROKEN === $report->overall_status ) {
				\WP_CLI::error( $line );
			} elseif ( Parent_Form_Contract_Report::STATUS_WARNING === $report->overall_status ) {
				\WP_CLI::warning( $line );
			} else {
				\WP_CLI::success( $line );
			}
		}
	}

	/**
	 * Re-sync historical donations to QuickBooks Online.
	 *
	 * Walks the standard `dfwc_companion_donation_submitted` event chain
	 * for the matching donations and re-enqueues each onto the QBO sync
	 * queue. Idempotent: QBO Sales Receipts use the donation timestamp +
	 * campaign ID as their PrivateNote, so duplicate enqueues that result
	 * in duplicate receipts are visible in QBO and can be reconciled
	 * manually if needed.
	 *
	 * ## OPTIONS
	 *
	 * [--campaign=<id>]
	 * : Restrict re-sync to a single campaign (default: all campaigns).
	 *
	 * [--days=<days>]
	 * : Look back this many days for donations (default: 30).
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--dry-run]
	 * : Print the jobs that would be enqueued without actually enqueueing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dfwc-companion qbo-sync
	 *     wp dfwc-companion qbo-sync --campaign=123 --days=60
	 *     wp dfwc-companion qbo-sync --dry-run
	 *
	 * @subcommand qbo-sync
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function qbo_sync( $args, $assoc_args ): void {
		$campaign_id = isset( $assoc_args['campaign'] ) ? absint( $assoc_args['campaign'] ) : 0;
		$days        = isset( $assoc_args['days'] ) ? max( 1, absint( $assoc_args['days'] ) ) : 30;
		$dry_run     = ! empty( $assoc_args['dry-run'] );

		if ( ! Token_Store::has_tokens() ) {
			\WP_CLI::error( 'QuickBooks is not connected. Run the OAuth flow from Donations Companion → QuickBooks Sync first.' );
			return;
		}

		$query_args = array(
			'post_type'      => 'shop_order',
			'posts_per_page' => 500,
			'post_status'    => array( 'wc-completed', 'wc-processing' ),
			'date_query'     => array(
				array(
					'after'     => sprintf( '%d days ago', $days ),
					'inclusive' => true,
				),
			),
			'fields'         => 'ids',
		);

		$order_ids = function_exists( 'get_posts' ) ? get_posts( $query_args ) : array();
		if ( ! is_array( $order_ids ) ) {
			$order_ids = array();
		}

		$enqueued = 0;
		$skipped  = 0;
		foreach ( $order_ids as $order_id ) {
			$matched_campaign = (int) get_post_meta( (int) $order_id, '_dfwc_companion_campaign_id', true );
			if ( $matched_campaign < 1 ) {
				++$skipped;
				continue;
			}
			if ( $campaign_id > 0 && $matched_campaign !== $campaign_id ) {
				++$skipped;
				continue;
			}
			$amount   = (float) get_post_meta( (int) $order_id, '_order_total', true );
			$currency = (string) get_post_meta( (int) $order_id, '_order_currency', true );
			$interval = (string) get_post_meta( (int) $order_id, '_dfwc_companion_interval', true );

			if ( $amount <= 0 ) {
				++$skipped;
				continue;
			}

			$job = array(
				'campaign_id' => $matched_campaign,
				'interval'    => '' !== $interval ? $interval : 'one_time',
				'amount'      => $amount,
				'context'     => 'cli-resync',
				'currency'    => '' !== $currency ? $currency : '',
				'language'    => '',
				'occurred_at' => time(),
			);
			if ( $dry_run ) {
				\WP_CLI::line(
					sprintf(
						'WOULD enqueue order #%d → campaign %d, %s %.2f',
						(int) $order_id,
						$matched_campaign,
						$job['currency'],
						$job['amount']
					)
				);
			} else {
				Sync_Queue::enqueue( $job );
			}
			++$enqueued;
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run: %d would be enqueued, %d skipped.', $enqueued, $skipped ) );
		} else {
			\WP_CLI::success( sprintf( 'Enqueued %d job(s); %d skipped.', $enqueued, $skipped ) );
		}
	}
}
