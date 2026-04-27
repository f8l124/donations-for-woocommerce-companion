<?php
// Verifies v0.6.2 product auto-config writes the right meta keys.
// Run via: wp eval-file tests/smoke-product-config.php

\DFWC\Companion\Plugin::instance();
remove_all_actions( 'save_post_wc-donation' );
$_POST['_wcdnonce'] = wp_create_nonce( '_wcdnonce' );

$cid = wp_insert_post( [
	'post_type'   => 'wc-donation',
	'post_status' => 'publish',
	'post_title'  => 'Smoke v0.6.2 product config',
] );
echo "campaign id: $cid\n";

$product = new WC_Product_Simple();
$product->set_name( 'smoke prod' );
$product->set_regular_price( 25 );
$pid = $product->save();
echo "product id: $pid\n";

update_post_meta( $cid, 'wc_donation_product', $pid );

$mb = new \DFWC\Companion\Admin\Meta_Box();
$rc = new \ReflectionClass( $mb );
$method = $rc->getMethod( 'auto_configure_product_subscription' );
$method->setAccessible( true );
$method->invoke( $mb, (int) $cid, 'month' );

$wps_product = wps_sfw_get_meta_data( $pid, '_wps_sfw_product', true );
$wps_users   = wps_sfw_get_meta_data( $pid, '_wps_sfw_users', true );
$interval    = wps_sfw_get_meta_data( $pid, 'wps_sfw_subscription_interval', true );
$number      = wps_sfw_get_meta_data( $pid, 'wps_sfw_subscription_number', true );

echo "_wps_sfw_product = '$wps_product' (expected 'yes')\n";
echo "_wps_sfw_users   = '$wps_users' (expected 'user')\n";
echo "interval         = '$interval' (expected 'month')\n";
echo "number           = '$number' (expected '1')\n";

if ( 'yes' !== $wps_product ) { echo "FAIL: _wps_sfw_product != yes\n"; exit( 1 ); }
if ( 'user' !== $wps_users )  { echo "FAIL: _wps_sfw_users != user\n"; exit( 1 ); }
if ( 'month' !== $interval )  { echo "FAIL: interval != month\n"; exit( 1 ); }

wp_delete_post( $cid, true );
wp_delete_post( $pid, true );
echo "=== OK auto-config writes the right keys ===\n";
