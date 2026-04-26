<?php
/**
 * Admin health probe (verifies parent plugin contract is intact).
 *
 * Phase A: stub. Transient-cached probe + admin notices land in Phase E.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

final class Self_Check {

	public function __construct() {
		// Hooks wired in Phase E.
	}
}
