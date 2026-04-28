<?php
/**
 * Unit tests for Parent_Form_Contract_Result value object.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Contracts\Parent_Form_Contract_Result;
use PHPUnit\Framework\TestCase;

final class Parent_Form_Contract_Result_Test extends TestCase {

	public function test_pass_factory_creates_passing_result(): void {
		$r = Parent_Form_Contract_Result::pass( 'foo', 'all good' );

		$this->assertSame( 'foo', $r->contract_id );
		$this->assertSame( Parent_Form_Contract_Result::STATUS_PASS, $r->status );
		$this->assertSame( 'all good', $r->message );
		$this->assertSame( '', $r->remediation );
		$this->assertTrue( $r->passed() );
		$this->assertFalse( $r->failed() );
	}

	public function test_warn_factory_carries_remediation(): void {
		$r = Parent_Form_Contract_Result::warn( 'foo', 'a problem', 'do this' );

		$this->assertSame( Parent_Form_Contract_Result::STATUS_WARNING, $r->status );
		$this->assertSame( 'do this', $r->remediation );
		$this->assertFalse( $r->passed() );
		$this->assertFalse( $r->failed() );
	}

	public function test_fail_factory_carries_remediation_and_context(): void {
		$r = Parent_Form_Contract_Result::fail(
			'foo',
			'broken',
			'fix it',
			array( 'version' => '3.9.8' )
		);

		$this->assertSame( Parent_Form_Contract_Result::STATUS_FAIL, $r->status );
		$this->assertSame( 'broken', $r->message );
		$this->assertSame( 'fix it', $r->remediation );
		$this->assertSame( array( 'version' => '3.9.8' ), $r->context );
		$this->assertTrue( $r->failed() );
	}

	public function test_constructor_accepts_full_argument_list(): void {
		$r = new Parent_Form_Contract_Result(
			'foo',
			Parent_Form_Contract_Result::STATUS_PASS,
			'msg',
			'rem',
			array( 'a' => 1 )
		);

		$this->assertSame( 'foo', $r->contract_id );
		$this->assertSame( 'msg', $r->message );
		$this->assertSame( array( 'a' => 1 ), $r->context );
	}
}
