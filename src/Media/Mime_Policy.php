<?php
/**
 * Extra MIME types needed for pixel-perfect design assets.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

final class Mime_Policy {

	/**
	 * @return array<string, string>
	 */
	public static function extra(): array {
		return array(
			'svg'   => 'image/svg+xml',
			'avif'  => 'image/avif',
			'ico'   => 'image/x-icon',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			'ogv'   => 'video/ogg',
			'mov'   => 'video/quicktime',
			'm4v'   => 'video/x-m4v',
			'mp3'   => 'audio/mpeg',
			'wav'   => 'audio/wav',
			'ogg'   => 'audio/ogg',
			'm4a'   => 'audio/mp4',
			'json'  => 'application/json',
		);
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function allow( array $mimes ): array {
		return array_merge( $mimes, self::extra() );
	}

	/**
	 * @param array{ext?:string, type?:string, proper_filename?:string} $data
	 * @param array<string, string>|false                                $mimes
	 * @return array{ext?:string, type?:string, proper_filename?:string}
	 */
	public static function check( array $data, string $file, string $filename, $mimes, ?string $real_mime = null ): array {
		unset( $file, $mimes, $real_mime );
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$map = self::extra();
		if ( isset( $map[ $ext ] ) ) {
			$data['ext']  = $ext;
			$data['type'] = $map[ $ext ];
		}

		return $data;
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function with_allowed( callable $callback ) {
		add_filter( 'upload_mimes', array( self::class, 'allow' ) );
		add_filter( 'wp_check_filetype_and_ext', array( self::class, 'check' ), 10, 5 );
		try {
			return $callback();
		} finally {
			remove_filter( 'upload_mimes', array( self::class, 'allow' ) );
			remove_filter( 'wp_check_filetype_and_ext', array( self::class, 'check' ), 10 );
		}
	}
}
