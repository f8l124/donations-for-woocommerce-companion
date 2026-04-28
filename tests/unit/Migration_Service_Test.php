<?php
/**
 * Unit tests for Config\Migration_Service.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Migration_Service;
use PHPUnit\Framework\TestCase;

final class Migration_Service_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_maybe_migrate_runs_when_version_unset(): void {
		$svc = new Migration_Service();
		$svc->maybe_migrate();

		$this->assertSame( Migration_Service::CURRENT_VERSION, (int) get_option( Migration_Service::VERSION_OPTION ) );
	}

	public function test_maybe_migrate_skips_when_already_at_current_version(): void {
		update_option( Migration_Service::VERSION_OPTION, Migration_Service::CURRENT_VERSION );

		$fired = 0;
		add_action( 'dfwc_companion_migrated_v0_6_to_v0_7', static function () use ( &$fired ): void { ++$fired; } );

		( new Migration_Service() )->maybe_migrate();

		$this->assertSame( 0, $fired );
	}

	public function test_maybe_migrate_is_idempotent(): void {
		$svc = new Migration_Service();
		$svc->maybe_migrate();
		$first = (int) get_option( Migration_Service::VERSION_OPTION );

		$svc->maybe_migrate();
		$second = (int) get_option( Migration_Service::VERSION_OPTION );

		$this->assertSame( $first, $second );
	}

	public function test_v0_6_to_v0_7_migration_fires_action(): void {
		$fired = 0;
		add_action( 'dfwc_companion_migrated_v0_6_to_v0_7', static function () use ( &$fired ): void { ++$fired; } );

		( new Migration_Service() )->maybe_migrate();

		$this->assertSame( 1, $fired );
	}

	public function test_v0_6_to_v0_7_clears_orphan_self_check_transient(): void {
		set_transient( 'dfwc_self_check', array( 'orphan' => true ), 3600 );

		( new Migration_Service() )->maybe_migrate();

		$this->assertFalse( get_transient( 'dfwc_self_check' ) );
	}

	public function test_force_migrate_runs_regardless_of_stored_version(): void {
		update_option( Migration_Service::VERSION_OPTION, Migration_Service::CURRENT_VERSION );

		$fired = 0;
		add_action( 'dfwc_companion_migrated_v0_6_to_v0_7', static function () use ( &$fired ): void { ++$fired; } );

		( new Migration_Service() )->force_migrate_to_current();

		$this->assertSame( 1, $fired );
	}

	public function test_migration_completed_action_carries_versions(): void {
		$captured = array();
		add_action(
			'dfwc_companion_migration_completed',
			static function ( $from, $to ) use ( &$captured ): void {
				$captured = array( 'from' => $from, 'to' => $to );
			},
			10,
			2
		);

		( new Migration_Service() )->maybe_migrate();

		$this->assertSame( 0, $captured['from'] );
		$this->assertSame( Migration_Service::CURRENT_VERSION, $captured['to'] );
	}
}
