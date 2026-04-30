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
}
