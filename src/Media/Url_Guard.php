<?php
/**
 * Block private/loopback URLs when downloading design assets.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

final class Url_Guard {

	public static function is_safe_http( string $url ): bool {
		$url = esc_url_raw( $url );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) ) {
			return false !== wp_http_validate_url( $url );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return false;
		}

		return true;
	}
}
