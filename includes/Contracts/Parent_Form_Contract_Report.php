<?php
/**
 * Parent_Form_Contract_Report — collection of results + overall status.
 *
 * Built by Parent_Form_Contract_Checker after running every contract's check
 * callable. Cached as an array (via to_array() / report_from_array() round
 * trip) in a transient so admin page loads don't re-run probes.
 *
 * Overall status is derived from constituent results:
 *   any FAIL    → broken
 *   any WARNING → warning
 *   else        → healthy
 *
 * `is_broken()` is the gate Context_Augmenter consults to decide whether to
 * augment parent's render or fall back to the vanilla form.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Contracts;

defined( 'ABSPATH' ) || exit;

final class Parent_Form_Contract_Report {

	public const STATUS_HEALTHY = 'healthy';
	public const STATUS_WARNING = 'warning';
	public const STATUS_BROKEN  = 'broken';

	/** @var Parent_Form_Contract_Result[] */
	public array $results;

	public int $checked_at;

	public string $overall_status;

	/**
	 * @param Parent_Form_Contract_Result[] $results
	 */
	public function __construct( array $results, int $checked_at ) {
		$this->results        = $results;
		$this->checked_at     = $checked_at;
		$this->overall_status = $this->compute_overall();
	}

	private function compute_overall(): string {
		$has_fail = false;
		$has_warn = false;
		foreach ( $this->results as $r ) {
			if ( Parent_Form_Contract_Result::STATUS_FAIL === $r->status ) {
				$has_fail = true;
			} elseif ( Parent_Form_Contract_Result::STATUS_WARNING === $r->status ) {
				$has_warn = true;
			}
		}
		if ( $has_fail ) {
			return self::STATUS_BROKEN;
		}
		if ( $has_warn ) {
			return self::STATUS_WARNING;
		}
		return self::STATUS_HEALTHY;
	}

	public function is_healthy(): bool {
		return self::STATUS_HEALTHY === $this->overall_status;
	}

	public function is_broken(): bool {
		return self::STATUS_BROKEN === $this->overall_status;
	}

	public function get_result( string $contract_id ): ?Parent_Form_Contract_Result {
		foreach ( $this->results as $r ) {
			if ( $r->contract_id === $contract_id ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Serialize to plain array for transient storage. Reconstituted via
	 * Parent_Form_Contract_Checker::report_from_array().
	 *
	 * @return array{overall_status:string,checked_at:int,results:array<int,array<string,mixed>>}
	 */
	public function to_array(): array {
		return array(
			'overall_status' => $this->overall_status,
			'checked_at'     => $this->checked_at,
			'results'        => array_map(
				static function ( Parent_Form_Contract_Result $r ): array {
					return array(
						'contract_id' => $r->contract_id,
						'status'      => $r->status,
						'message'     => $r->message,
						'remediation' => $r->remediation,
						'context'     => $r->context,
					);
				},
				$this->results
			),
		);
	}

	/**
	 * Build a copy-paste-ready support report in Markdown form.
	 *
	 * Sanitization rules:
	 * - Strip absolute filesystem paths (replace with `wp-content/...` style).
	 * - Strip nonces, salts, anything secret-shaped.
	 * - Allow-listed context fields only.
	 *
	 * Output safe to paste into a support thread or GitHub issue.
	 *
	 * @param array<string,string> $contract_labels Map of contract_id => translated label.
	 */
	public function to_markdown( array $contract_labels = array() ): string {
		$status_emoji = array(
			Parent_Form_Contract_Result::STATUS_PASS    => '✅ Pass',
			Parent_Form_Contract_Result::STATUS_WARNING => '⚠️ Warning',
			Parent_Form_Contract_Result::STATUS_FAIL    => '❌ Fail',
		);

		$overall_emoji = array(
			self::STATUS_HEALTHY => '✅ Healthy',
			self::STATUS_WARNING => '⚠️ Warning',
			self::STATUS_BROKEN  => '❌ Broken',
		);

		$out  = "# Donations for WooCommerce Companion — Diagnostics\n\n";
		$out .= '- Companion version: ' . self::sanitize_value( defined( 'DFWC_COMPANION_VERSION' ) ? DFWC_COMPANION_VERSION : 'unknown' ) . "\n";
		$out .= '- Parent version: ' . self::sanitize_value( defined( 'WC_DONATION_VERSION' ) ? WC_DONATION_VERSION : 'unknown' ) . "\n";
		$out .= '- WordPress: ' . self::sanitize_value( $GLOBALS['wp_version'] ?? 'unknown' ) . "\n";
		$out .= '- PHP: ' . self::sanitize_value( PHP_VERSION ) . "\n";
		$out .= '- Checked at (UTC): ' . self::sanitize_value( gmdate( 'Y-m-d H:i:s', $this->checked_at ) ) . "\n\n";

		$out .= '## Overall: ' . ( $overall_emoji[ $this->overall_status ] ?? $this->overall_status ) . "\n\n";

		$out .= "## Checks\n\n";
		$out .= "| Check | Status | Result |\n";
		$out .= "|---|---|---|\n";
		foreach ( $this->results as $r ) {
			$label  = $contract_labels[ $r->contract_id ] ?? $r->contract_id;
			$status = $status_emoji[ $r->status ] ?? $r->status;
			$out   .= '| ' . self::sanitize_value( $label )
				. ' | ' . self::sanitize_value( $status )
				. ' | ' . self::sanitize_value( $r->message );
			if ( ! empty( $r->context ) ) {
				$out .= ' (' . self::sanitize_context( $r->context ) . ')';
			}
			$out .= " |\n";
		}

		// Surface remediation steps for any non-pass results.
		$remediations = array();
		foreach ( $this->results as $r ) {
			if ( $r->passed() || '' === $r->remediation ) {
				continue;
			}
			$label          = $contract_labels[ $r->contract_id ] ?? $r->contract_id;
			$remediations[] = '- **' . self::sanitize_value( $label ) . '**: ' . self::sanitize_value( $r->remediation );
		}
		if ( ! empty( $remediations ) ) {
			$out .= "\n## Suggested actions\n\n" . implode( "\n", $remediations ) . "\n";
		}

		return $out;
	}

	/**
	 * Sanitize a single scalar for support-report inclusion. Strips absolute
	 * paths and characters that could break Markdown table rendering.
	 *
	 * @param scalar|null $value
	 */
	private static function sanitize_value( $value ): string {
		$str = (string) $value;
		// Strip absolute filesystem paths — common server patterns.
		$str = preg_replace( '#[A-Z]:\\\\[^\s|]+#i', '<path>', $str );
		$str = preg_replace( '#/(home|srv|var|opt|usr|tmp|root|wp-content)/[^\s|]+#', '<path>', $str );
		// Collapse pipes (would break Markdown tables).
		$str = str_replace( '|', '\\|', $str );
		// Cap length defensively.
		if ( strlen( $str ) > 500 ) {
			$str = substr( $str, 0, 497 ) . '...';
		}
		return $str;
	}

	/**
	 * Format a context array as `key=value, key=value` for in-row display.
	 * Allow-list keys: only known-safe ones get included.
	 *
	 * @param array<string,scalar> $context
	 */
	private static function sanitize_context( array $context ): string {
		$allowed = array( 'version', 'engine', 'php_version', 'wp_version', 'wc_version', 'parent_version', 'language', 'currency', 'count', 'missing' );
		$pairs   = array();
		foreach ( $context as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', $value ) );
			}
			$pairs[] = self::sanitize_value( (string) $key ) . '=' . self::sanitize_value( (string) $value );
		}
		return implode( ', ', $pairs );
	}
}
