<?php
/**
 * Unit tests for Analytics\Privacy_Guard.
 *
 * @package DFWC\Companion
 */

use DFWC\Companion\Analytics\Privacy_Guard;
use PHPUnit\Framework\TestCase;

final class Privacy_Guard_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		dfwc_test_reset();
	}

	public function test_sanitize_event_returns_canonical_shape(): void {
		$out = Privacy_Guard::sanitize_event( array(
			'type'        => 'donation_submitted',
			'campaign_id' => '42',
			'interval'    => 'monthly',
			'amount'      => '25.50',
			'context'     => 'shortcode',
			'currency'    => 'usd',
		) );

		$this->assertSame( 'donation_submitted', $out['type'] );
		$this->assertSame( 42, $out['campaign_id'] );
		$this->assertSame( 'monthly', $out['interval'] );
		$this->assertSame( 25.50, $out['amount'] );
		$this->assertSame( 'shortcode', $out['context'] );
		$this->assertSame( 'USD', $out['currency'] );
		$this->assertIsInt( $out['ts'] );
	}

	public function test_unknown_event_type_drops_to_empty_string(): void {
		$out = Privacy_Guard::sanitize_event( array( 'type' => 'pii_exfiltrate' ) );
		$this->assertSame( '', $out['type'] );
	}

	public function test_pii_fields_silently_stripped(): void {
		// Caller might attach donor_email, ip, user_agent, session_id — the
		// canonical shape includes only allow-listed keys, so PII never
		// makes it into the array.
		$out = Privacy_Guard::sanitize_event( array(
			'type'         => 'preset_selected',
			'campaign_id'  => 1,
			'donor_email'  => 'leak@example.com',
			'ip'           => '192.0.2.1',
			'user_agent'   => 'Mozilla/...',
			'session_id'   => 'abc123',
			'utm_source'   => 'newsletter',
		) );

		$this->assertArrayNotHasKey( 'donor_email', $out );
		$this->assertArrayNotHasKey( 'ip', $out );
		$this->assertArrayNotHasKey( 'user_agent', $out );
		$this->assertArrayNotHasKey( 'session_id', $out );
		$this->assertArrayNotHasKey( 'utm_source', $out );
	}

	public function test_sanitize_type_allow_lists(): void {
		foreach ( Privacy_Guard::event_types() as $type ) {
			$this->assertSame( $type, Privacy_Guard::sanitize_type( $type ) );
		}
		$this->assertSame( '', Privacy_Guard::sanitize_type( 'pii_steal' ) );
		// Case insensitive.
		$this->assertSame( 'donation_submitted', Privacy_Guard::sanitize_type( 'DONATION_SUBMITTED' ) );
	}

	public function test_sanitize_interval_allow_lists(): void {
		$this->assertSame( 'monthly', Privacy_Guard::sanitize_interval( 'monthly' ) );
		$this->assertSame( 'weekly', Privacy_Guard::sanitize_interval( 'weekly' ) );
		$this->assertSame( 'custom', Privacy_Guard::sanitize_interval( 'custom' ) );
		// Empty string passes through (form_viewed has no interval).
		$this->assertSame( '', Privacy_Guard::sanitize_interval( '' ) );
		// Anything not in the allow-list rejected.
		$this->assertSame( '', Privacy_Guard::sanitize_interval( 'fortnightly' ) );
	}

	public function test_sanitize_amount_clamps_negatives_and_overflow(): void {
		$this->assertSame( 0.0, Privacy_Guard::sanitize_amount( -50 ) );
		$this->assertSame( 0.0, Privacy_Guard::sanitize_amount( 'abc' ) );
		$this->assertSame( 25.5, Privacy_Guard::sanitize_amount( '25.50' ) );
		$this->assertSame( 999999999.0, Privacy_Guard::sanitize_amount( 1.0e12 ) );
		$this->assertSame( 0.0, Privacy_Guard::sanitize_amount( INF ) );
	}

	public function test_sanitize_context_falls_back_to_unknown(): void {
		$this->assertSame( 'shortcode', Privacy_Guard::sanitize_context( 'shortcode' ) );
		$this->assertSame( 'unknown', Privacy_Guard::sanitize_context( 'wp-cron' ) );
		$this->assertSame( 'unknown', Privacy_Guard::sanitize_context( '' ) );
	}

	public function test_sanitize_context_accepts_stock(): void {
		// Phase 14A — stock-pledge donations need their own context value so
		// listeners can split cash vs stock metrics.
		$this->assertSame( 'stock', Privacy_Guard::sanitize_context( 'stock' ) );
		$this->assertContains( 'stock', Privacy_Guard::contexts() );
	}

	public function test_sanitize_currency_uppercases_and_validates_iso(): void {
		$this->assertSame( 'GBP', Privacy_Guard::sanitize_currency( 'gbp' ) );
		$this->assertSame( 'USD', Privacy_Guard::sanitize_currency( 'USD' ) );
	}

	public function test_sanitize_currency_rejects_bad_shapes(): void {
		// Empty / wrong shape falls back to base currency (USD per stub).
		$this->assertSame( 'USD', Privacy_Guard::sanitize_currency( '' ) );
		$this->assertSame( 'USD', Privacy_Guard::sanitize_currency( 'EURO' ) );
		$this->assertSame( 'USD', Privacy_Guard::sanitize_currency( '$$$' ) );
		$this->assertSame( 'USD', Privacy_Guard::sanitize_currency( '12' ) );
	}

	public function test_sanitize_language_rejects_unknown_codes(): void {
		// In the test stub neither WPML active-languages nor get_locale is
		// defined, so the allow-list is empty → everything rejects.
		$this->assertSame( '', Privacy_Guard::sanitize_language( 'jp' ) );
	}

	public function test_sanitize_language_accepts_filterable_codes(): void {
		add_filter( 'dfwc_companion_active_language_codes', static function () {
			return array( 'en', 'en_US', 'fr', 'pt-br' );
		} );

		$this->assertSame( 'en', Privacy_Guard::sanitize_language( 'en' ) );
		$this->assertSame( 'fr', Privacy_Guard::sanitize_language( 'fr' ) );
		$this->assertSame( 'pt-br', Privacy_Guard::sanitize_language( 'pt-br' ) );
	}

	public function test_sanitize_language_rejects_obvious_garbage(): void {
		add_filter( 'dfwc_companion_active_language_codes', static function () {
			return array( 'en', 'fr' );
		} );

		// Shape gate fails first — these never reach the allow-list check.
		$this->assertSame( '', Privacy_Guard::sanitize_language( '<script>alert(1)</script>' ) );
		$this->assertSame( '', Privacy_Guard::sanitize_language( 'this-is-too-long-for-a-language' ) );
		$this->assertSame( '', Privacy_Guard::sanitize_language( '12' ) );
		// Shape OK but not in allow-list.
		$this->assertSame( '', Privacy_Guard::sanitize_language( 'jp' ) );
	}

	public function test_sanitize_reason_caps_length(): void {
		$long = str_repeat( 'A', 500 );
		$out  = Privacy_Guard::sanitize_reason( $long );
		$this->assertSame( 200, strlen( $out ) );
	}

	public function test_sanitize_reason_strips_html(): void {
		$out = Privacy_Guard::sanitize_reason( '<b>boom</b>' );
		// sanitize_text_field stub trims; the production WP function strips
		// tags. Either way the output should be safe to interpolate.
		$this->assertStringNotContainsString( '<', $out );
	}

	public function test_server_time_replaces_client_supplied_ts(): void {
		$out = Privacy_Guard::sanitize_event( array(
			'type' => 'form_viewed',
			'ts'   => 12345,
		) );

		$this->assertNotEquals( 12345, $out['ts'] );
		$this->assertGreaterThan( 1700000000, $out['ts'] ); // 2023+; server time is always current.
	}
}
