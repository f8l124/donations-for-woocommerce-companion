<?php
/**
 * Unit tests for QuickBooks\Account_Mapper.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\QuickBooks\Account_Mapper;
use PHPUnit\Framework\TestCase;

final class QBO_Account_Mapper_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_unmapped_with_no_default_returns_empty(): void {
		$this->assertSame( '', Account_Mapper::resolve( 42 ) );
	}

	public function test_per_campaign_mapping_takes_precedence(): void {
		Account_Mapper::save(
			array(
				'42'                       => '101',
				Account_Mapper::DEFAULT_KEY => '999',
			)
		);
		$this->assertSame( '101', Account_Mapper::resolve( 42 ) );
		$this->assertSame( '999', Account_Mapper::resolve( 99 ) );
	}

	public function test_default_kicks_in_when_campaign_unmapped(): void {
		Account_Mapper::save(
			array(
				Account_Mapper::DEFAULT_KEY => '999',
			)
		);
		$this->assertSame( '999', Account_Mapper::resolve( 42 ) );
		$this->assertSame( '999', Account_Mapper::resolve( 7 ) );
	}

	public function test_save_strips_empty_account_ids(): void {
		// Empty strings = "remove this mapping". Don't store empty entries.
		Account_Mapper::save(
			array(
				'42' => '101',
				'99' => '',  // unmapped — should NOT persist
			)
		);
		$mapping = Account_Mapper::get_all();
		$this->assertArrayHasKey( '42', $mapping );
		$this->assertArrayNotHasKey( '99', $mapping );
	}

	public function test_save_rejects_non_numeric_account_ids(): void {
		// QBO account IDs are positive integers as strings. Anything else
		// is silently rejected (returned as empty by sanitize).
		Account_Mapper::save(
			array(
				'42' => 'abc-haxx',
				'7'  => '101',
			)
		);
		$mapping = Account_Mapper::get_all();
		$this->assertArrayNotHasKey( '42', $mapping );
		$this->assertSame( '101', $mapping['7'] );
	}

	public function test_save_normalizes_campaign_keys_to_int_string(): void {
		// Whatever the form posts, we re-key by absint cast → string.
		Account_Mapper::save(
			array(
				42                          => '101',
				Account_Mapper::DEFAULT_KEY => '999',
			)
		);
		$mapping = Account_Mapper::get_all();
		$this->assertSame( '101', $mapping['42'] );
		$this->assertSame( '999', $mapping[ Account_Mapper::DEFAULT_KEY ] );
	}

	public function test_clear_removes_all(): void {
		Account_Mapper::save( array( '42' => '101' ) );
		Account_Mapper::clear();
		$this->assertSame( array(), Account_Mapper::get_all() );
		$this->assertSame( '', Account_Mapper::resolve( 42 ) );
	}
}
