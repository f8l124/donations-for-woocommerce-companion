<?php
/**
 * Uninstall handler — runs when the admin clicks "Delete" on the plugin
 * (after deactivation). Respects the admin's `preserve_data_on_uninstall`
 * preference (default: true). Opt-in deletion required.
 *
 * What this file does NOT touch:
 * - The `wc-donation` campaign posts themselves (parent-plugin owned data).
 * - WooCommerce orders, products, or any non-companion data.
 * - The companion-registered taxonomies' terms (so re-installation can
 *   recover them; documented as known residue).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dfwc_global   = get_option( 'dfwc_companion_global_settings', array() );
$dfwc_preserve = is_array( $dfwc_global ) ? ! empty( $dfwc_global['preserve_data_on_uninstall'] ) : true;

// Always clear caches — they're cheap to rebuild and can grow stale across
// reinstalls regardless of the preserve preference.
delete_transient( 'dfwc_contract_report' );
delete_transient( 'dfwc_self_check' );

if ( $dfwc_preserve ) {
	// Preserve everything else. Admins who want a clean wipe must turn off
	// "Preserve data on uninstall" in Settings before deleting the plugin.
	return;
}

// === Opt-in clean removal: delete all companion options + post meta. ===

$dfwc_options = array(
	'dfwc_companion_global_settings',
	'dfwc_companion_templates',
	'dfwc_companion_schema_version',
	'dfwc_companion_terms_seeded',
);
foreach ( $dfwc_options as $dfwc_option ) {
	delete_option( $dfwc_option );
}

$dfwc_meta_keys = array(
	'_dfwc_companion_intervals',
	'_dfwc_companion_display',
	'_dfwc_companion_template_id',
	'_dfwc_companion_overrides',
	'_dfwc_companion_detached',
	'_dfwc_companion_featured',
);
foreach ( $dfwc_meta_keys as $dfwc_meta_key ) {
	// `delete_metadata( 'post', 0, $key, '', true )` deletes the meta from
	// every post that carries it — exactly the wipe semantics we want.
	delete_metadata( 'post', 0, $dfwc_meta_key, '', true );
}

// Companion-prefixed transients (rate-limit transients, preview-pane
// nonces, etc.). The `dfwc_` prefix is plugin-private; safe to wipe in
// bulk without colliding with anything else.
global $wpdb;
$dfwc_like         = $wpdb->esc_like( '_transient_dfwc_' ) . '%';
$dfwc_timeout_like = $wpdb->esc_like( '_transient_timeout_dfwc_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$dfwc_like,
		$dfwc_timeout_like
	)
);
