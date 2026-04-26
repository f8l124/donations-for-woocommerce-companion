<?php
/**
 * Smoke test: exercise companion code paths inside wp-env to catch fatals
 * before shipping. Run via: wp eval-file tests/smoke-save.php
 *
 * Not part of any released artifact — gitignored if needed; kept in tests/
 * for local validation only.
 */

// Note: no `declare(strict_types=1);` because `wp eval-file` wraps in eval()
// which means our <?php is not the first statement, and strict_types must be.

echo "=== dfwc smoke ===\n";

// 0. Become admin so current_user_can('edit_post') passes during save.
wp_set_current_user( 1 );

// 1. Plugin classes load.
foreach ( [
	'\DFWC\Companion\Autoloader',
	'\DFWC\Companion\Plugin',
	'\DFWC\Companion\Engine_Detector',
	'\DFWC\Companion\Config_Resolver',
	'\DFWC\Companion\Admin\Meta_Box',
	'\DFWC\Companion\Admin\Assets',
	'\DFWC\Companion\Admin\Self_Check',
	'\DFWC\Companion\Frontend\Renderer',
	'\DFWC\Companion\Frontend\Shortcode',
	'\DFWC\Companion\Frontend\Block',
	'\DFWC\Companion\Frontend\Assets',
	'\DFWC\Companion\Frontend\Submit_Guard',
	'\DFWC\Companion\Frontend\Elementor_Adapter',
] as $cls ) {
	echo class_exists( $cls ) ? "OK   $cls\n" : "MISS $cls\n";
}

// 2. Engine detection.
echo "engine: " . \DFWC\Companion\Engine_Detector::detect() . "\n";

// 3. Create a campaign. Parent hooks save_post (not save_post_wc-donation)
// and wp_die's without _wcdnonce — set it pre-emptively so wp_insert_post
// passes its check.
$_POST['_wcdnonce'] = wp_create_nonce( '_wcdnonce' );
$cid = wp_insert_post( [
	'post_type'   => 'wc-donation',
	'post_status' => 'publish',
	'post_title'  => 'Smoke Campaign',
] );
echo "campaign id: $cid\n";
if ( ! $cid || is_wp_error( $cid ) ) { echo "FAIL: could not create\n"; exit( 1 ); }

// 4. Resolve config (exercises Config_Resolver default + inference paths).
$cfg = \DFWC\Companion\Config_Resolver::resolve( (int) $cid );
echo "config keys: " . implode( ',', array_keys( $cfg ) ) . "\n";
echo "one_time enabled: " . ( ! empty( $cfg['one_time']['enabled'] ) ? 'yes' : 'no' ) . "\n";

// 5. Render via the augmentation pattern (delegates to parent's shortcode).
// In 0.4.0 the Renderer no longer outputs a self-contained form — it wraps
// parent's `[wc_woo_donation]` HTML in our overlay marker.
$html = \DFWC\Companion\Frontend\Renderer::render( (int) $cid );
$len = strlen( $html );
echo "render html length: $len\n";
if ( strpos( $html, 'data-dfwc-overlay-target' ) === false ) {
	echo "FAIL: overlay marker [data-dfwc-overlay-target] missing — Renderer didn't wrap parent shortcode\n";
	echo substr( $html, 0, 500 ) . "\n";
	exit( 1 );
}
if ( strpos( $html, 'wc-donation-in-action' ) === false ) {
	echo "FAIL: parent's .wc-donation-in-action root not present in overlay output — parent shortcode produced nothing\n";
	echo substr( $html, 0, 500 ) . "\n";
	exit( 1 );
}
echo "overlay marker: present\n";
echo "parent form root: present\n";

// 6. Simulate a save by invoking Meta_Box::save() with mock $_POST.
// This is the path that fataled on the user's site.
// check_admin_referer is hard to satisfy in a CLI context (no real session/
// cookie), so monkey-patch the save handler's nonce check by short-circuiting
// the action check via a filter.
add_filter( 'nonce_user_logged_out', function ( $uid ) { return 1; }, 1 );
$_POST = [
	'_dfwc_nonce'      => wp_create_nonce( 'dfwc_companion_save_meta' ),
	'_wp_http_referer' => '/wp-admin/post.php',
	'dfwc_intervals'   => [
		'one_time' => [ 'enabled' => '1', 'presets' => [ [ 'amount' => '25', 'label' => '' ] ], 'min' => '5', 'max' => '1000', 'default_index' => '0', 'cta_template' => 'Donate {amount}' ],
		'monthly'  => [ 'enabled' => '1', 'presets' => [ [ 'amount' => '10', 'label' => '' ], [ 'amount' => '25', 'label' => '' ] ], 'min' => '5', 'max' => '500', 'default_index' => '1', 'cta_template' => 'Donate {amount}/month' ],
		'annual'   => [ 'enabled' => '0', 'presets' => [], 'min' => '50', 'max' => '50000', 'default_index' => '0', 'cta_template' => '' ],
	],
	'dfwc_form_mode'   => 'replace',
];
echo "nonce verify: " . ( wp_verify_nonce( $_POST['_dfwc_nonce'], 'dfwc_companion_save_meta' ) ? 'OK' : 'FAIL' ) . "\n";

$mb = new \DFWC\Companion\Admin\Meta_Box();
try {
	$mb->save( (int) $cid );
	echo "save: ok\n";
} catch ( \Throwable $e ) {
	echo "FAIL: save threw " . get_class( $e ) . ": " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine() . "\n";
	exit( 1 );
}

// 7. Verify D1 force-set landed.
$rec = get_post_meta( (int) $cid, 'wc-donation-recurring', true );
echo "wc-donation-recurring after save: '$rec'\n";
if ( 'user' !== $rec ) { echo "FAIL: D1 force-set missing\n"; exit( 1 ); }

// 8. Cleanup.
wp_delete_post( (int) $cid, true );
echo "=== ALL SMOKE OK ===\n";
