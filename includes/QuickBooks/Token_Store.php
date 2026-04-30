<?php
/**
 * Token_Store — encrypted persistence for QBO OAuth2 tokens.
 *
 * Threat model:
 *   - **In scope:** an attacker who reads `wp_options` via SQLi, a leaked
 *     SQL backup, or a misconfigured backup-as-zip download must not get
 *     plaintext access tokens.
 *   - **Out of scope:** an attacker with full filesystem read on the WP
 *     install. They can read `AUTH_KEY` from `wp-config.php` and decrypt;
 *     defending against that requires a real KMS, which is out of scope
 *     for a self-hosted plugin.
 *
 * Implementation: AES-256-CBC via PHP's openssl_encrypt, key derived from
 * `AUTH_KEY . AUTH_SALT . 'dfwc-qbo-token-store'`. The IV is randomly
 * generated per write and prefixed onto the ciphertext. Without the IV the
 * ciphertext is indistinguishable from random; without `AUTH_KEY` the IV
 * alone reveals nothing.
 *
 * Storage shape (option key `dfwc_qbo_oauth_tokens`):
 *
 * ```
 * [
 *   'access_token_enc'    => '<base64>',  // IV + ciphertext, base64
 *   'refresh_token_enc'   => '<base64>',
 *   'access_expires_at'   => 1735689600,  // unix timestamp
 *   'refresh_expires_at'  => 1744323200,
 *   'realm_id'            => '4620816365316892901',
 *   'company_name'        => 'Example Nonprofit Inc',
 *   'connected_at'        => 1735603200,
 * ]
 * ```
 *
 * Public methods are intentionally narrow: store / clear / has_tokens /
 * read individual fields. The plaintext access token never leaves this
 * class except via the explicit getter (so `get_option('dfwc_qbo_*')` from
 * elsewhere can never accidentally surface plaintext).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\QuickBooks;

defined( 'ABSPATH' ) || exit;

final class Token_Store {

	public const OPTION_KEY = 'dfwc_qbo_oauth_tokens';
	private const CIPHER    = 'aes-256-cbc';
	private const KEY_SALT  = 'dfwc-qbo-token-store';

	/**
	 * Persist a fresh token bundle from an OAuth response. Caller passes
	 * plaintext; we encrypt before writing.
	 */
	public static function store(
		string $access_token,
		string $refresh_token,
		int $access_expires_at,
		int $refresh_expires_at,
		string $realm_id,
		string $company_name = ''
	): bool {
		$payload = array(
			'access_token_enc'   => self::encrypt( $access_token ),
			'refresh_token_enc'  => self::encrypt( $refresh_token ),
			'access_expires_at'  => $access_expires_at,
			'refresh_expires_at' => $refresh_expires_at,
			'realm_id'           => $realm_id,
			'company_name'       => $company_name,
			'connected_at'       => time(),
		);
		return (bool) update_option( self::OPTION_KEY, $payload, false );
	}

	/**
	 * Update only the access-token half of the bundle (refresh tokens
	 * rotate less frequently than access tokens; called from the refresh
	 * flow when Intuit returns a new access token but the same refresh).
	 */
	public static function update_access_token( string $access_token, int $access_expires_at ): bool {
		$payload = self::raw();
		if ( null === $payload ) {
			return false;
		}
		$payload['access_token_enc']  = self::encrypt( $access_token );
		$payload['access_expires_at'] = $access_expires_at;
		return (bool) update_option( self::OPTION_KEY, $payload, false );
	}

	/**
	 * Refresh tokens DO sometimes rotate (Intuit's behavior). Call this
	 * when the response contains a new refresh_token alongside the access
	 * token.
	 */
	public static function update_both(
		string $access_token,
		string $refresh_token,
		int $access_expires_at,
		int $refresh_expires_at
	): bool {
		$payload = self::raw();
		if ( null === $payload ) {
			return false;
		}
		$payload['access_token_enc']   = self::encrypt( $access_token );
		$payload['refresh_token_enc']  = self::encrypt( $refresh_token );
		$payload['access_expires_at']  = $access_expires_at;
		$payload['refresh_expires_at'] = $refresh_expires_at;
		return (bool) update_option( self::OPTION_KEY, $payload, false );
	}

	public static function clear(): bool {
		return (bool) delete_option( self::OPTION_KEY );
	}

	public static function has_tokens(): bool {
		$raw = self::raw();
		return is_array( $raw )
			&& ! empty( $raw['access_token_enc'] )
			&& ! empty( $raw['refresh_token_enc'] )
			&& ! empty( $raw['realm_id'] );
	}

	public static function realm_id(): string {
		$raw = self::raw();
		return ( is_array( $raw ) && isset( $raw['realm_id'] ) ) ? (string) $raw['realm_id'] : '';
	}

	public static function company_name(): string {
		$raw = self::raw();
		return ( is_array( $raw ) && isset( $raw['company_name'] ) ) ? (string) $raw['company_name'] : '';
	}

	public static function connected_at(): int {
		$raw = self::raw();
		return ( is_array( $raw ) && isset( $raw['connected_at'] ) ) ? (int) $raw['connected_at'] : 0;
	}

	public static function access_expires_at(): int {
		$raw = self::raw();
		return ( is_array( $raw ) && isset( $raw['access_expires_at'] ) ) ? (int) $raw['access_expires_at'] : 0;
	}

	public static function refresh_expires_at(): int {
		$raw = self::raw();
		return ( is_array( $raw ) && isset( $raw['refresh_expires_at'] ) ) ? (int) $raw['refresh_expires_at'] : 0;
	}

	/**
	 * Plaintext access token. ONLY for in-process API calls — never log,
	 * never echo, never persist outside this class.
	 */
	public static function access_token(): string {
		$raw = self::raw();
		if ( ! is_array( $raw ) || empty( $raw['access_token_enc'] ) ) {
			return '';
		}
		return self::decrypt( (string) $raw['access_token_enc'] );
	}

	/**
	 * Plaintext refresh token. Used only by OAuth_Client::refresh() and
	 * the disconnect flow (which calls Intuit's revoke endpoint).
	 */
	public static function refresh_token(): string {
		$raw = self::raw();
		if ( ! is_array( $raw ) || empty( $raw['refresh_token_enc'] ) ) {
			return '';
		}
		return self::decrypt( (string) $raw['refresh_token_enc'] );
	}

	/**
	 * Raw stored payload. Returns null when nothing's stored. Internal —
	 * the public accessors above narrow the surface so callers don't grab
	 * the encrypted blobs by accident.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function raw(): ?array {
		$value = get_option( self::OPTION_KEY, null );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * AES-256-CBC encrypt + base64. IV (16 bytes) prefixed onto ciphertext
	 * so we can decrypt without storing it separately.
	 */
	private static function encrypt( string $plaintext ): string {
		$key = self::derive_key();
		$iv  = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ct  = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return '';
		}
		return base64_encode( $iv . $ct );
	}

	private static function decrypt( string $base64 ): string {
		$blob = base64_decode( $base64, true );
		if ( false === $blob ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $blob ) < $iv_len ) {
			return '';
		}
		$iv  = substr( $blob, 0, $iv_len );
		$ct  = substr( $blob, $iv_len );
		$key = self::derive_key();
		$pt  = openssl_decrypt( $ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	/**
	 * Derive a 32-byte key from `AUTH_KEY . AUTH_SALT . KEY_SALT`. This is
	 * NOT a PBKDF — `AUTH_KEY` is itself a high-entropy value, so a single
	 * sha256 is sufficient.
	 */
	private static function derive_key(): string {
		$auth_key  = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$auth_salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';
		return hash( 'sha256', $auth_key . $auth_salt . self::KEY_SALT, true );
	}
}
