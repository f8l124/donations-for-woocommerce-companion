<?php
/**
 * Migration_Service — idempotent schema migrations.
 *
 * Tracks the last-applied migration via the `dfwc_companion_schema_version`
 * option. On every plugins_loaded the service compares stored version to
 * CURRENT_VERSION and runs missing migrations forward-only.
 *
 * Migrations are idempotent: re-running produces no changes. Failure to
 * apply a single migration logs an admin notice but doesn't fatal — better
 * to run with stale schema than to brick the site.
 *
 * Migration v0.6.x → v0.7.0 (this version 1):
 *   - Clears orphan transient `dfwc_self_check` (Phase 2 left it behind
 *     after refactoring Self_Check to delegate to Parent_Form_Contract_Checker).
 *   - Per-campaign legacy intervals→overrides relocation is NOT done globally
 *     here — too slow on sites with thousands of campaigns. Instead,
 *     Campaign_Config_Repository::migrate_legacy_to_overrides_if_needed()
 *     migrates one campaign at a time on its next admin save.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Migration_Service {

	public const VERSION_OPTION  = 'dfwc_companion_schema_version';
	public const CURRENT_VERSION = 1;

	/**
	 * Run any missing migrations. No-op when stored version matches current.
	 * Idempotent.
	 */
	public function maybe_migrate(): void {
		$stored = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $stored >= self::CURRENT_VERSION ) {
			return;
		}
		$this->run_migrations( $stored, self::CURRENT_VERSION );
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, true );

		/**
		 * Fires after a migration pass completes. Receives the
		 * pre-migration version so listeners can act on specific upgrades.
		 *
		 * @param int $from
		 * @param int $to
		 */
		do_action( 'dfwc_companion_migration_completed', $stored, self::CURRENT_VERSION );
	}

	/**
	 * Force a re-run of all migrations regardless of stored version.
	 * Used in tests; never called from production code.
	 */
	public function force_migrate_to_current(): void {
		$this->run_migrations( 0, self::CURRENT_VERSION );
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, true );
	}

	private function run_migrations( int $from, int $to ): void {
		if ( $from < 1 && $to >= 1 ) {
			$this->migrate_v0_6_to_v0_7();
		}
		// Future: if ( $from < 2 && $to >= 2 ) { $this->migrate_v0_7_to_v0_8(); }
	}

	/**
	 * v0.6.x → v0.7.0 migration. Idempotent.
	 *
	 * - Clears orphan transient `dfwc_self_check` from the v0.6.x Self_Check
	 *   implementation (Phase 2 refactor moved to `dfwc_contract_report`).
	 * - Per-campaign meta migration is deferred to first-save-per-campaign
	 *   so this method stays fast on every site.
	 */
	private function migrate_v0_6_to_v0_7(): void {
		delete_transient( 'dfwc_self_check' );

		/**
		 * Fires after the v0.6 → v0.7 migration block runs.
		 */
		do_action( 'dfwc_companion_migrated_v0_6_to_v0_7' );
	}
}
