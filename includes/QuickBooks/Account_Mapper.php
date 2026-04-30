<?php
/**
 * Account_Mapper — campaign → QBO Income Account mapping store.
 *
 * Each `wc-donation` campaign can be mapped to a specific QBO Income
 * Account. Unmapped campaigns fall through to the `_default` mapping. If
 * neither is set, sync defers — the admin sees a diagnostic warning that
 * a default mapping is needed, and the donation goes onto the retry queue.
 *
 * Stored as a single option `dfwc_qbo_account_mapping`:
 *
 * ```php
 * [
 *   '<campaign_id>' => '<qbo_account_id>',
 *   ...
 *   '_default' => '<qbo_account_id>',  // fallback (string '_default' key)
 * ]
 * ```
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

final class Account_Mapper {

	public const OPTION_KEY  = 'dfwc_qbo_account_mapping';
	public const DEFAULT_KEY = '_default';

	/**
	 * Resolve the QBO account ID for a campaign. Returns empty string if
	 * neither a per-campaign nor a default mapping is configured — caller
	 * (Sync_Handler) treats that as "defer; admin needs to configure".
	 */
	public static function resolve( int $campaign_id ): string {
		$mapping = self::get_all();
		$key     = (string) $campaign_id;
		if ( isset( $mapping[ $key ] ) && '' !== $mapping[ $key ] ) {
			return (string) $mapping[ $key ];
		}
		if ( isset( $mapping[ self::DEFAULT_KEY ] ) && '' !== $mapping[ self::DEFAULT_KEY ] ) {
			return (string) $mapping[ self::DEFAULT_KEY ];
		}
		return '';
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_all(): array {
		$value = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		// Coerce all values to string; key shape is whatever was stored.
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ (string) $k ] = (string) $v;
		}
		return $out;
	}

	/**
	 * Persist the full mapping. Caller is the admin form handler; values
	 * are sanitized before reaching here.
	 *
	 * @param array<string,string> $mapping
	 */
	public static function save( array $mapping ): bool {
		$clean = array();
		foreach ( $mapping as $key => $account_id ) {
			$key = self::DEFAULT_KEY === (string) $key
				? self::DEFAULT_KEY
				: (string) absint( $key );
			$account_id = self::sanitize_account_id( (string) $account_id );
			if ( '' === $account_id ) {
				continue; // Empty = not mapped; don't store empty entries.
			}
			$clean[ $key ] = $account_id;
		}
		return (bool) update_option( self::OPTION_KEY, $clean, false );
	}

	public static function clear(): bool {
		return (bool) delete_option( self::OPTION_KEY );
	}

	/**
	 * QBO account IDs are positive integers represented as strings. Reject
	 * anything else; the empty result causes the caller to skip persisting
	 * (treated as "remove this mapping").
	 */
	private static function sanitize_account_id( string $value ): string {
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return '';
		}
		return preg_match( '/^[0-9]+$/', $trimmed ) ? $trimmed : '';
	}
}
