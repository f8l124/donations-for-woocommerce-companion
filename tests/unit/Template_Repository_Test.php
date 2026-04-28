<?php
/**
 * Unit tests for Config\Template_Repository.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Template_Config;
use DFWC\Companion\Config\Template_Repository;
use PHPUnit\Framework\TestCase;

final class Template_Repository_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_all_returns_empty_when_option_unset(): void {
		$repo = new Template_Repository();
		$this->assertSame( array(), $repo->all() );
		$this->assertSame( 0, $repo->count() );
	}

	public function test_save_persists_template(): void {
		$repo = new Template_Repository();
		$now  = time();
		$tpl  = new Template_Config( 'school_sponsorship', 'School Sponsorship', 'Recurring-first.', $now, $now, Defaults::for_campaign() );

		$this->assertTrue( $repo->save( $tpl ) );
		$this->assertTrue( $repo->exists( 'school_sponsorship' ) );

		$loaded = $repo->get( 'school_sponsorship' );
		$this->assertNotNull( $loaded );
		$this->assertSame( 'School Sponsorship', $loaded->name );
		$this->assertSame( 'Recurring-first.', $loaded->description );
	}

	public function test_save_with_empty_id_fails(): void {
		$repo = new Template_Repository();
		$tpl  = new Template_Config( '', 'noid', '', time(), time(), array() );

		$this->assertFalse( $repo->save( $tpl ) );
	}

	public function test_get_returns_null_for_missing(): void {
		$this->assertNull( ( new Template_Repository() )->get( 'nonexistent' ) );
	}

	public function test_delete_removes_template(): void {
		$repo = new Template_Repository();
		$tpl  = new Template_Config( 'foo', 'Foo', '', time(), time(), array() );
		$repo->save( $tpl );

		$this->assertTrue( $repo->exists( 'foo' ) );
		$this->assertTrue( $repo->delete( 'foo' ) );
		$this->assertFalse( $repo->exists( 'foo' ) );
	}

	public function test_delete_returns_false_for_missing(): void {
		$this->assertFalse( ( new Template_Repository() )->delete( 'nonexistent' ) );
	}

	public function test_count_reflects_stored_templates(): void {
		$repo = new Template_Repository();
		$repo->save( new Template_Config( 'a', 'A', '', time(), time(), array() ) );
		$repo->save( new Template_Config( 'b', 'B', '', time(), time(), array() ) );
		$repo->save( new Template_Config( 'c', 'C', '', time(), time(), array() ) );

		$this->assertSame( 3, $repo->count() );
	}

	public function test_generate_id_uses_sanitized_name(): void {
		$repo = new Template_Repository();
		// sanitize_key strips spaces (no inserted dashes), matching WP behavior.
		$this->assertSame( 'schoolsponsorship', $repo->generate_id( 'School Sponsorship' ) );
	}

	public function test_generate_id_handles_collision(): void {
		$repo = new Template_Repository();
		$repo->save( new Template_Config( 'schoolsponsorship', 'A', '', time(), time(), array() ) );
		$repo->save( new Template_Config( 'schoolsponsorship-2', 'B', '', time(), time(), array() ) );

		$this->assertSame( 'schoolsponsorship-3', $repo->generate_id( 'School Sponsorship' ) );
	}

	public function test_generate_id_falls_back_to_template_when_name_empty(): void {
		$repo = new Template_Repository();
		$this->assertSame( 'template', $repo->generate_id( '' ) );
		$this->assertSame( 'template', $repo->generate_id( '!!!' ) );
	}

	public function test_save_fires_template_saved_action(): void {
		$repo  = new Template_Repository();
		$fired = array();

		add_action(
			'dfwc_companion_template_saved',
			static function ( $id, $tpl ) use ( &$fired ): void {
				$fired[] = array( 'id' => $id, 'name' => $tpl->name );
			},
			10,
			2
		);

		$repo->save( new Template_Config( 'foo', 'Foo', '', time(), time(), array() ) );

		$this->assertCount( 1, $fired );
		$this->assertSame( 'foo', $fired[0]['id'] );
		$this->assertSame( 'Foo', $fired[0]['name'] );
	}

	public function test_delete_fires_template_deleted_action(): void {
		$repo  = new Template_Repository();
		$fired = array();
		$repo->save( new Template_Config( 'foo', 'Foo', '', time(), time(), array() ) );

		add_action(
			'dfwc_companion_template_deleted',
			static function ( $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$repo->delete( 'foo' );

		$this->assertSame( array( 'foo' ), $fired );
	}

	public function test_save_round_trip_preserves_config(): void {
		$repo   = new Template_Repository();
		$config = Defaults::for_campaign();
		$config['monthly']['enabled']             = true;
		$config['monthly']['cta_template']        = 'Donate {amount}/month';
		$config['monthly']['presets'][0]['amount'] = 30.0;
		$tpl                                      = new Template_Config( 'roundtrip', 'Round Trip', 'desc', 100, 200, $config );

		$repo->save( $tpl );
		$loaded = $repo->get( 'roundtrip' );

		$this->assertNotNull( $loaded );
		$this->assertSame( 100, $loaded->created_at );
		$this->assertSame( 200, $loaded->modified_at );
		$this->assertTrue( $loaded->config['monthly']['enabled'] );
		$this->assertSame( 'Donate {amount}/month', $loaded->config['monthly']['cta_template'] );
		$this->assertSame( 30.0, $loaded->config['monthly']['presets'][0]['amount'] );
	}
}
