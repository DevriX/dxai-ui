<?php
/**
 * Encrypted option storage for API keys.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Security;

final class Secret_Store {

	private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

	/**
	 * @return non-empty-string
	 */
	private static function key(): string {
		if ( defined( 'DXAI_UI_SECRETS_KEY' ) && is_string( DXAI_UI_SECRETS_KEY ) && DXAI_UI_SECRETS_KEY !== '' ) {
			return substr( hash( 'sha256', DXAI_UI_SECRETS_KEY, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}

		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'dxai-ui' );

		return substr( hash( 'sha256', $material, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}

		$nonce      = random_bytes( self::NONCE_BYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return base64_encode( $nonce . $ciphertext );
	}

	public static function decrypt( string $payload ): string {
		if ( $payload === '' ) {
			return '';
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) < self::NONCE_BYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}

		$nonce      = substr( $raw, 0, self::NONCE_BYTES );
		$ciphertext = substr( $raw, self::NONCE_BYTES );
		$plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::key() );

		return false === $plain ? '' : $plain;
	}

	public static function mask( string $value ): string {
		$length = strlen( $value );
		if ( $length === 0 ) {
			return '';
		}
		if ( $length < 8 ) {
			return str_repeat( '•', $length );
		}

		return substr( $value, 0, 4 ) . str_repeat( '•', max( 4, $length - 8 ) ) . substr( $value, -4 );
	}
}
