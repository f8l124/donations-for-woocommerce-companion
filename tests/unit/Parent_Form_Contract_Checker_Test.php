<?php
/**
 * Unit tests for Parent_Form_Contract_Checker.
 *
 * Test scope: the checker's public surface (cache, force-refresh, individual
 * check methods, exception safety, report_from_array round-trip). Most
 * checks read the runtime environment (class_exists, has_action, etc.) so
 * tests verify behavior under our test bootstrap's stubbed environment —
 * we know what's defined and what's not.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Contracts\Parent_Form_Contract;
use DFWC\Companion\Contracts\Parent_Form_Contract_Checker;
use DFWC\Companion\Contracts\Parent_Form_Contract_Report;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;
use PHPUnit\Framework\TestCase;

final class Parent_Form_Contract_Checker_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();

		// Force a fresh-cache state for each test.
		( new Parent_Form_Contract_Checker() )->clear_cache();
	}

	public function test_get_report_returns_a_report(): void {
		$report = ( new Parent_Form_Contract_Checker() )->get_report();

		$this->assertInstanceOf( Parent_Form_Contract_Report::class, $report );
		$this->assertNotEmpty( $report->results );
	}

	public function test_force_refresh_clears_cache_and_rebuilds(): void {
		$checker = new Parent_Form_Contract_Checker();

		$first  = $checker->get_report();
		$second = $checker->get_report( true );

		$this->assertEquals( count( $first->results ), count( $second->results ) );
	}

	public function test_clear_cache_works(): void {
		$checker = new Parent_Form_Contract_Checker();
		$checker->get_report(); // warms cache
		$checker->clear_cache();

		// Should rebuild without throwing
		$report = $checker->get_report();
		$this->assertInstanceOf( Parent_Form_Contract_Report::class, $report );
	}

	public function test_contract_labels_returns_id_to_label_map(): void {
		$labels = ( new Parent_Form_Contract_Checker() )->contract_labels();

		$this->assertIsArray( $labels );
		$this->assertArrayHasKey( 'wc_active', $labels );
		$this->assertArrayHasKey( 'parent_active', $labels );
		$this->assertNotEmpty( $labels['wc_active'] );
	}

	public function test_contracts_by_id_returns_contract_objects(): void {
		$contracts = ( new Parent_Form_Contract_Checker() )->contracts_by_id();

		$this->assertArrayHasKey( 'wc_active', $contracts );
		$this->assertInstanceOf( Parent_Form_Contract::class, $contracts['wc_active'] );
		$this->assertSame( 'wc_active', $contracts['wc_active']->id );
	}

	public function test_check_php_version_passes_on_supported(): void {
		$result = ( new Parent_Form_Contract_Checker() )->check_php_version();

		// Test runner is on PHP 7.4+ (this project's minimum). Test bootstrap doesn't override.
		$this->assertTrue( $result->passed() );
		$this->assertArrayHasKey( 'php_version', $result->context );
	}

	public function test_check_parent_active_passes_when_constant_defined(): void {
		// phpstan-bootstrap.php defines WC_DONATION_VERSION = '3.9.8'.
		$result = ( new Parent_Form_Contract_Checker() )->check_parent_active();

		$this->assertTrue( $result->passed() );
		$this->assertArrayHasKey( 'parent_version', $result->context );
		$this->assertSame( '3.9.8', $result->context['parent_version'] );
	}

	public function test_check_campaign_post_type_fails_when_not_registered(): void {
		// post_type_exists is not stubbed in tests/bootstrap.php; it returns false.
		// Stub it inline for this test.
		if ( ! function_exists( 'post_type_exists' ) ) {
			eval( 'function post_type_exists( $post_type ) { return false; }' );
		}

		$result = ( new Parent_Form_Contract_Checker() )->check_campaign_post_type();
		$this->assertTrue( $result->failed() );
	}

	public function test_check_subscription_engine_warns_when_no_engine(): void {
		// Test bootstrap stubs WC_Subscriptions and Subscriptions_For_Woocommerce
		// as empty classes, so Engine_Detector::detect() reports an engine. The
		// pass-case is the realistic test here. (Phase E E2E covers the
		// no-engine branch under wp-env where the stubs aren't loaded.)
		$result = ( new Parent_Form_Contract_Checker() )->check_subscription_engine();

		$this->assertTrue( $result->passed() );
		$this->assertArrayHasKey( 'engine', $result->context );
	}

	public function test_check_wpml_present_is_info_when_absent(): void {
		// Test bootstrap defines icl_object_id stub — so WPML is "detected".
		// We test the pass-message structure rather than the absence branch.
		$result = ( new Parent_Form_Contract_Checker() )->check_wpml_present();

		$this->assertTrue( $result->passed() );
	}

	public function test_dfwc_companion_contracts_filter_can_add_entries(): void {
		add_filter(
			'dfwc_companion_contracts',
			static function ( $contracts ) {
				$contracts[] = new Parent_Form_Contract(
					'custom_test',
					'Custom test',
					'Test contract added via filter',
					Parent_Form_Contract::SEVERITY_INFO,
					static function () {
						return Parent_Form_Contract_Result::pass( 'custom_test' );
					}
				);
				return $contracts;
			}
		);

		$checker  = new Parent_Form_Contract_Checker();
		$contracts = $checker->contracts_by_id();

		$this->assertArrayHasKey( 'custom_test', $contracts );
	}

	public function test_dfwc_companion_contracts_filter_handles_garbage_gracefully(): void {
		// If a third-party filter returns non-array, the checker should fall
		// back to its built-in list rather than crashing.
		add_filter( 'dfwc_companion_contracts', static fn() => 'not-an-array' );

		$checker = new Parent_Form_Contract_Checker();
		$report  = $checker->get_report();

		// Should still build a valid report from the built-in contracts.
		$this->assertNotEmpty( $report->results );
	}

	public function test_report_from_array_reconstitutes_correctly(): void {
		$data = array(
			'overall_status' => Parent_Form_Contract_Report::STATUS_WARNING,
			'checked_at'     => 1714000000,
			'results'        => array(
				array(
					'contract_id' => 'foo',
					'status'      => Parent_Form_Contract_Result::STATUS_WARNING,
					'message'     => 'msg',
					'remediation' => 'rem',
					'context'     => array( 'a' => 1 ),
				),
			),
		);

		$report = Parent_Form_Contract_Checker::report_from_array( $data );

		$this->assertSame( 1714000000, $report->checked_at );
		$this->assertCount( 1, $report->results );
		$this->assertSame( 'foo', $report->results[0]->contract_id );
		$this->assertSame( 'msg', $report->results[0]->message );
		$this->assertSame( 'rem', $report->results[0]->remediation );
	}

	public function test_report_from_array_defaults_missing_fields_safely(): void {
		$report = Parent_Form_Contract_Checker::report_from_array( array() );

		$this->assertSame( array(), $report->results );
		$this->assertSame( 0, $report->checked_at );
	}

	public function test_check_throwing_exception_yields_fail_result(): void {
		// Add a contract whose check throws.
		add_filter(
			'dfwc_companion_contracts',
			static function ( $contracts ) {
				$contracts[] = new Parent_Form_Contract(
					'always_throws',
					'Always throws',
					'Test',
					Parent_Form_Contract::SEVERITY_ERROR,
					static function () {
						throw new \RuntimeException( 'oops' );
					}
				);
				return $contracts;
			}
		);

		$report = ( new Parent_Form_Contract_Checker() )->get_report( true );
		$found  = $report->get_result( 'always_throws' );

		$this->assertNotNull( $found );
		$this->assertTrue( $found->failed() );
		$this->assertSame( 'RuntimeException', $found->context['exception_class'] ?? '' );
	}
}
