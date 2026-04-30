<?php
/**
 * Unit tests for QuickBooks\Sync_Queue.
 *
 * Action Scheduler isn't loaded in the test bootstrap, so the queue
 * gracefully falls back to log-only behavior. This is the production
 * path on sites where AS happens to be unloaded (rare; AS ships with
 * WC core).
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\QuickBooks\Sync_Queue;
use PHPUnit\Framework\TestCase;

final class QBO_Sync_Queue_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_log_appends_with_server_timestamp(): void {
		Sync_Queue::log(
			array(
				'status'      => 'success',
				'message'     => 'Synced.',
				'campaign_id' => 42,
				'amount'      => 25.0,
				'attempt'     => 0,
			)
		);
		$log = Sync_Queue::get_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'success', $log[0]['status'] );
		$this->assertSame( 42, $log[0]['campaign_id'] );
		$this->assertIsInt( $log[0]['ts'] );
		$this->assertGreaterThan( 1700000000, $log[0]['ts'] );
	}

	public function test_log_caps_at_max_entries(): void {
		// LOG_MAX is 50 — write 60, expect 50 with the oldest 10 dropped.
		for ( $i = 0; $i < 60; $i++ ) {
			Sync_Queue::log(
				array(
					'status'      => 'success',
					'message'     => 'iter ' . $i,
					'campaign_id' => 1,
					'amount'      => 1.0,
					'attempt'     => 0,
				)
			);
		}
		$log = Sync_Queue::get_log();
		$this->assertSame( Sync_Queue::LOG_MAX, count( $log ) );
		// Oldest entries dropped, so log[0] should be iter 10 (60 - 50).
		$this->assertSame( 'iter 10', $log[0]['message'] );
		$this->assertSame( 'iter 59', $log[ count( $log ) - 1 ]['message'] );
	}

	public function test_failures_since_counts_window(): void {
		Sync_Queue::log( array( 'status' => 'success', 'message' => '', 'campaign_id' => 1, 'amount' => 1, 'attempt' => 0 ) );
		Sync_Queue::log( array( 'status' => 'failed',  'message' => '', 'campaign_id' => 1, 'amount' => 1, 'attempt' => 0 ) );
		Sync_Queue::log( array( 'status' => 'error',   'message' => '', 'campaign_id' => 1, 'amount' => 1, 'attempt' => 0 ) );
		Sync_Queue::log( array( 'status' => 'deferred', 'message' => '', 'campaign_id' => 1, 'amount' => 1, 'attempt' => 0 ) );

		// Both 'failed' and 'error' count toward the failure tally; 'success'
		// and 'deferred' don't.
		$this->assertSame( 2, Sync_Queue::failures_since( 60 ) );
	}

	public function test_failures_since_excludes_old_entries(): void {
		// Manually inject a log entry with a stale timestamp.
		update_option(
			Sync_Queue::LOG_OPTION,
			array(
				array(
					'ts'          => time() - 7200, // 2h ago
					'status'      => 'failed',
					'message'     => 'old',
					'campaign_id' => 1,
					'amount'      => 1,
					'attempt'     => 0,
				),
				array(
					'ts'          => time() - 30, // 30s ago
					'status'      => 'failed',
					'message'     => 'recent',
					'campaign_id' => 1,
					'amount'      => 1,
					'attempt'     => 0,
				),
			)
		);
		// Window of 60s — only the 30s-old entry counts.
		$this->assertSame( 1, Sync_Queue::failures_since( 60 ) );
	}

	public function test_enqueue_without_action_scheduler_logs_error(): void {
		// Action Scheduler isn't loaded in the bootstrap → enqueue logs an
		// error and returns rather than fatal'ing.
		Sync_Queue::enqueue(
			array(
				'campaign_id' => 7,
				'amount'      => 100.0,
				'currency'    => 'USD',
				'context'     => 'single',
			)
		);
		$log = Sync_Queue::get_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'error', $log[0]['status'] );
		$this->assertStringContainsString( 'Action Scheduler', $log[0]['message'] );
	}

	public function test_reset_clears_log(): void {
		Sync_Queue::log( array( 'status' => 'success', 'message' => 'x', 'campaign_id' => 1, 'amount' => 1, 'attempt' => 0 ) );
		Sync_Queue::reset();
		$this->assertSame( array(), Sync_Queue::get_log() );
	}
}
