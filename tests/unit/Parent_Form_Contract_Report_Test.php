<?php
/**
 * Unit tests for Parent_Form_Contract_Report.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Contracts\Parent_Form_Contract_Report;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;
use PHPUnit\Framework\TestCase;

final class Parent_Form_Contract_Report_Test extends TestCase {

	public function test_overall_healthy_when_all_pass(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a' ),
				Parent_Form_Contract_Result::pass( 'b' ),
			),
			time()
		);

		$this->assertTrue( $report->is_healthy() );
		$this->assertFalse( $report->is_broken() );
		$this->assertSame( Parent_Form_Contract_Report::STATUS_HEALTHY, $report->overall_status );
	}

	public function test_overall_warning_when_any_warn_no_fail(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a' ),
				Parent_Form_Contract_Result::warn( 'b', 'careful' ),
				Parent_Form_Contract_Result::pass( 'c' ),
			),
			time()
		);

		$this->assertFalse( $report->is_healthy() );
		$this->assertFalse( $report->is_broken() );
		$this->assertSame( Parent_Form_Contract_Report::STATUS_WARNING, $report->overall_status );
	}

	public function test_overall_broken_when_any_fail(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a' ),
				Parent_Form_Contract_Result::warn( 'b', 'careful' ),
				Parent_Form_Contract_Result::fail( 'c', 'broken' ),
			),
			time()
		);

		$this->assertFalse( $report->is_healthy() );
		$this->assertTrue( $report->is_broken() );
		$this->assertSame( Parent_Form_Contract_Report::STATUS_BROKEN, $report->overall_status );
	}

	public function test_get_result_returns_matching_entry(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a' ),
				Parent_Form_Contract_Result::warn( 'b', 'careful' ),
			),
			time()
		);

		$found = $report->get_result( 'b' );
		$this->assertNotNull( $found );
		$this->assertSame( 'careful', $found->message );

		$this->assertNull( $report->get_result( 'nonexistent' ) );
	}

	public function test_to_array_round_trips_via_to_markdown(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a', 'A is fine', array( 'version' => '1.0' ) ),
				Parent_Form_Contract_Result::warn( 'b', 'B has a warning', 'fix B' ),
			),
			1714000000
		);

		$arr = $report->to_array();
		$this->assertSame( Parent_Form_Contract_Report::STATUS_WARNING, $arr['overall_status'] );
		$this->assertSame( 1714000000, $arr['checked_at'] );
		$this->assertCount( 2, $arr['results'] );
		$this->assertSame( 'A is fine', $arr['results'][0]['message'] );
		$this->assertSame( 'fix B', $arr['results'][1]['remediation'] );
	}

	public function test_to_markdown_strips_absolute_paths(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::warn(
					'a',
					'Found in /home/user/wp-content/plugins/foo/bar.php',
					'Check /var/log/something.log'
				),
			),
			time()
		);

		$md = $report->to_markdown();

		$this->assertStringNotContainsString( '/home/user', $md );
		$this->assertStringNotContainsString( '/var/log', $md );
		$this->assertStringContainsString( '<path>', $md );
	}

	public function test_to_markdown_escapes_pipes_to_avoid_breaking_table(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a', 'has | pipe' ),
			),
			time()
		);

		$md = $report->to_markdown();

		// Raw pipe inside the message would break the markdown table; we escape it.
		$this->assertStringContainsString( 'has \\| pipe', $md );
	}

	public function test_to_markdown_includes_overall_status_emoji(): void {
		$pass_only = new Parent_Form_Contract_Report(
			array( Parent_Form_Contract_Result::pass( 'a' ) ),
			time()
		);
		$this->assertStringContainsString( 'Healthy', $pass_only->to_markdown() );

		$with_fail = new Parent_Form_Contract_Report(
			array( Parent_Form_Contract_Result::fail( 'a', 'broken' ) ),
			time()
		);
		$this->assertStringContainsString( 'Broken', $with_fail->to_markdown() );
	}

	public function test_to_markdown_lists_remediations_for_non_pass_results(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a' ),
				Parent_Form_Contract_Result::warn( 'b', 'B warned', 'do thing for B' ),
				Parent_Form_Contract_Result::fail( 'c', 'C failed', 'do other thing for C' ),
			),
			time()
		);

		$md = $report->to_markdown();

		$this->assertStringContainsString( 'Suggested actions', $md );
		$this->assertStringContainsString( 'do thing for B', $md );
		$this->assertStringContainsString( 'do other thing for C', $md );
	}

	public function test_to_markdown_omits_remediations_section_when_all_pass(): void {
		$report = new Parent_Form_Contract_Report(
			array(
				Parent_Form_Contract_Result::pass( 'a' ),
				Parent_Form_Contract_Result::pass( 'b' ),
			),
			time()
		);

		$md = $report->to_markdown();

		$this->assertStringNotContainsString( 'Suggested actions', $md );
	}

	public function test_to_markdown_uses_provided_labels(): void {
		$report = new Parent_Form_Contract_Report(
			array( Parent_Form_Contract_Result::pass( 'wc_active' ) ),
			time()
		);

		$md = $report->to_markdown( array( 'wc_active' => 'WooCommerce active' ) );

		$this->assertStringContainsString( 'WooCommerce active', $md );
	}
}
