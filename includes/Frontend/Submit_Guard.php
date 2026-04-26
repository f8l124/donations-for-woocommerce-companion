<?php
/**
 * Server-side amount min/max + interval-enabled enforcement, hooked to the
 * parent plugin's `wc_donation_before_donate` action.
 *
 * Phase A: stub. Validation logic lands in Phase D (deviation D9).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Submit_Guard {

	public function __construct() {
		// Hooks wired in Phase D.
	}
}
