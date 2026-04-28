<?php
/**
 * Parent_Form_Contract_Result — single check result.
 *
 * Returned by each contract's check callable. Carries:
 * - status (pass/warning/fail)
 * - human-readable message (translatable)
 * - remediation guidance (translatable)
 * - structured context (small allow-listed fields like version strings, engine name)
 *
 * Context fields are exposed in the support report; do not put PII or
 * server paths in context. Privacy-Guard-style sanitization happens at
 * Report::to_markdown() time.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Contracts;

defined( 'ABSPATH' ) || exit;

final class Parent_Form_Contract_Result {

	public const STATUS_PASS    = 'pass';
	public const STATUS_WARNING = 'warning';
	public const STATUS_FAIL    = 'fail';

	public string $contract_id;
	public string $status;
	public string $message;
	public string $remediation;

	/** @var array<string,scalar> */
	public array $context;

	/**
	 * @param array<string,scalar> $context
	 */
	public function __construct(
		string $contract_id,
		string $status,
		string $message = '',
		string $remediation = '',
		array $context = array()
	) {
		$this->contract_id = $contract_id;
		$this->status      = $status;
		$this->message     = $message;
		$this->remediation = $remediation;
		$this->context     = $context;
	}

	/**
	 * @param array<string,scalar> $context
	 */
	public static function pass( string $contract_id, string $message = '', array $context = array() ): self {
		return new self( $contract_id, self::STATUS_PASS, $message, '', $context );
	}

	/**
	 * @param array<string,scalar> $context
	 */
	public static function warn( string $contract_id, string $message, string $remediation = '', array $context = array() ): self {
		return new self( $contract_id, self::STATUS_WARNING, $message, $remediation, $context );
	}

	/**
	 * @param array<string,scalar> $context
	 */
	public static function fail( string $contract_id, string $message, string $remediation = '', array $context = array() ): self {
		return new self( $contract_id, self::STATUS_FAIL, $message, $remediation, $context );
	}

	public function passed(): bool {
		return self::STATUS_PASS === $this->status;
	}

	public function failed(): bool {
		return self::STATUS_FAIL === $this->status;
	}
}
