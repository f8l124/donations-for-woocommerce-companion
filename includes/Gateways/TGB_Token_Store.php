<?php
/**
 * TGB_Token_Store — encrypted at-rest storage for The Giving Block credentials.
 *
 * Holds the API key and webhook secret separately from the public global
 * settings option (`dfwc_companion_global_settings`) so secrets never live
 * alongside form-rendered fields. AES-256-CBC with key derived from
 * AUTH_KEY . AUTH_SALT — mitigation against the threat "attacker reads
 * wp_options via SQLi or backup leak", NOT a substitute for KMS.
 *
 * Stored shape (one wp_option):
 *
 *     dfwc_companion_tgb_credentials = array(
 *         'api_key_blob'        => 'base64(iv|ciphertext)',
 *         'webhook_secret_blob' => 'base64(iv|ciphertext)',
 *         'updated_at'          => 1735689600,
 *     )
 *
 * Decryption failures (e.g., AUTH_KEY rotated since storage) return null
 * rather than throwing; callers surface the "re-enter credentials" admin
 * notice. Settings page never re-displays plaintext after save — admins
 * rotate by re-entering, never by reading the stored value.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

final class TGB_Token_Store {

	public const OPTION_KEY = 'dfwc_companion_tgb_credentials';
	private const CIPHER    = 'aes-256-cbc';
	private const IV_LEN    = 16;

	public function set_api_key( string $plaintext ): bool {
		return $this->store_field( 'api_key_blob', $plaintext );
	}

	public function get_api_key(): ?string {
		return $this->read_field( 'api_key_blob' );
	}

	public function set_webhook_secret( string $plaintext ): bool {
		return $this->store_field( 'webhook_secret_blob', $plaintext );
	}

	public function get_webhook_secret(): ?string {
		return $this->read_field( 'webhook_secret_blob' );
	}

	/**
	 * Wipe stored credentials. Used by admin "Disconnect" action and by
	 * uninstall.php opt-in clean-removal path.
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * True when both api_key and webhook_secret are stored AND decryptable.
	 * A decryption failure (AUTH_KEY rotated, blob corrupted) returns false
	 * so callers correctly treat the integration as needing reconnect.
	 */
	public function is_configured(): bool {
		return null !== $this->get_api_key() && null !== $this->get_webhook_secret();
	}

	/**
	 * Returns the timestamp of the last credential update, or 0 if never set.
	 * Surfaced in admin diagnostics.
	 */
	public function updated_at(): int {
		$stored = (array) get_option( self::OPTION_KEY, array() );
		return isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0;
	}

	private function store_field( string $field, string $plaintext ): bool {
		if ( '' === $plaintext ) {
			return false;
		}
		$blob = $this->encrypt( $plaintext );
		if ( null === $blob ) {
			return false;
		}
		$stored                 = (array) get_option( self::OPTION_KEY, array() );
		$stored[ $field ]       = $blob;
		$stored['updated_at']   = time();
		return (bool) update_option( self::OPTION_KEY, $stored, false );
	}

	private function read_field( string $field ): ?string {
		$stored = (array) get_option( self::OPTION_KEY, array() );
		if ( empty( $stored[ $field ] ) || ! is_string( $stored[ $field ] ) ) {
			return null;
		}
		return $this->decrypt( $stored[ $field ] );
	}

	/**
	 * Derive a 32-byte AES-256 key from AUTH_KEY . AUTH_SALT. Both constants
	 * are sourced from wp-config.php; on hosts that haven't customised the
	 * salts (rare), the WP defaults still produce 64+ chars of entropy.
	 */
	private function key(): string {
		$source = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );
		return hash( 'sha256', $source, true );
	}

	private function encrypt( string $plain ): ?string {
		try {
			$iv = random_bytes( self::IV_LEN );
		} catch ( \Exception $e ) {
			return null;
		}
		$cipher = openssl_encrypt( $plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return null;
		}
		return base64_encode( $iv . $cipher );
	}

	private function decrypt( string $blob ): ?string {
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) <= self::IV_LEN ) {
			return null;
		}
		$iv     = substr( $raw, 0, self::IV_LEN );
		$cipher = substr( $raw, self::IV_LEN );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv );
		return ( false === $plain ) ? null : $plain;
	}
}
