<?php
/**
 * Parent_Form_Contract — value object describing a single contract entry.
 *
 * One Parent_Form_Contract instance represents one thing the companion needs
 * to be true about its environment (WooCommerce active, parent plugin
 * version compatible, subscription engine present, etc.). The actual check
 * is a callable returning a Parent_Form_Contract_Result.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Contracts;

defined( 'ABSPATH' ) || exit;

final class Parent_Form_Contract {

	public const SEVERITY_INFO    = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR   = 'error';

	/** @var string Stable identifier; safe for storage, URL, support reports. */
	public string $id;

	/** @var string Translatable human label for admin display. */
	public string $label;

	/** @var string Translatable human description shown to admins. */
	public string $description;

	/** @var string One of SEVERITY_*. */
	public string $severity;

	/** @var callable(): Parent_Form_Contract_Result */
	public $check;

	/**
	 * @param callable(): Parent_Form_Contract_Result $check
	 */
	public function __construct( string $id, string $label, string $description, string $severity, callable $check ) {
		$this->id          = $id;
		$this->label       = $label;
		$this->description = $description;
		$this->severity    = $severity;
		$this->check       = $check;
	}
}
