<?php
/**
 * TGB_Projects_Cache — transient-cached fetch of TGB's project list.
 *
 * The admin meta-box and Settings page render dropdowns of TGB
 * sub-projects so admins pick by name rather than by opaque id. Each
 * fetch is a TGB API round-trip; cache for 1 hour to keep admin pageload
 * fast. Cache key is per-environment so sandbox vs. production don't
 * cross-contaminate.
 *
 * Manual refresh: admin can click "Refresh project list" in Settings
 * (handler clears the transient + immediately re-fetches).
 *
 * Failure modes: TGB API down → cached value stays usable until TTL;
 * after TTL → fetch returns empty array; admin can still type a project
 * id manually in the per-campaign meta-box's "enter custom" path.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;

final class TGB_Projects_Cache {

	private const TRANSIENT_PREFIX = 'dfwc_tgb_projects_';
	private const TTL_SECONDS      = HOUR_IN_SECONDS;

	/**
	 * Fetch the list of TGB projects for the active environment. Returns
	 * an array of `['id' => string, 'name' => string]` entries; empty when
	 * not configured, when the API fails, or during cooldown after a
	 * failure.
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function get(): array {
		$environment = self::current_environment();
		$cached      = get_transient( self::transient_key( $environment ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$projects = self::fetch_from_api( $environment );
		set_transient( self::transient_key( $environment ), $projects, self::TTL_SECONDS );

		return $projects;
	}

	/**
	 * Discard the cached project list for both environments and re-fetch
	 * the active one. Used by the Settings page "Refresh" action.
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function refresh(): array {
		delete_transient( self::transient_key( TGB_Client::ENV_SANDBOX ) );
		delete_transient( self::transient_key( TGB_Client::ENV_PRODUCTION ) );
		return self::get();
	}

	private static function current_environment(): string {
		$global = Config_Resolver::get_global_settings();
		$env    = isset( $global['tgb_environment'] ) ? (string) $global['tgb_environment'] : TGB_Client::ENV_SANDBOX;
		return in_array( $env, array( TGB_Client::ENV_SANDBOX, TGB_Client::ENV_PRODUCTION ), true )
			? $env
			: TGB_Client::ENV_SANDBOX;
	}

	private static function transient_key( string $environment ): string {
		return self::TRANSIENT_PREFIX . sanitize_key( $environment );
	}

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	private static function fetch_from_api( string $environment ): array {
		$api_key = ( new TGB_Token_Store() )->get_api_key();
		if ( null === $api_key ) {
			return array();
		}

		$client = new TGB_Client( (string) $api_key, $environment );
		$result = $client->list_projects();
		if ( empty( $result['ok'] ) ) {
			return array();
		}

		$projects = array();
		// TGB's exact response shape isn't pinned at v2.3.0; we accept
		// either { projects: [{id,name},...] } or [{id,name},...] at the
		// top level. Defensive against schema drift.
		$source = isset( $result['data']['projects'] ) && is_array( $result['data']['projects'] )
			? $result['data']['projects']
			: ( isset( $result['data'][0] ) ? $result['data'] : array() );

		foreach ( $source as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id   = isset( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : '';
			$name = isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$projects[] = array(
				'id'   => $id,
				'name' => '' !== $name ? $name : $id,
			);
		}

		return $projects;
	}
}
