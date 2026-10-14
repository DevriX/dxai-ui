<?php
/**
 * A design's JPEG and PNG pictures stored as WebP, when that is smaller.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * Lighthouse's first finding on every page of H2O Away was "Serve images in next-gen formats": 275–326 KiB to win on a page, the
 * picture the page opens on among them (docs/PLAN-PERFECT-PAGES.md, T5.1). The import stores the design's JPEG and PNG pictures as
 * WebP instead — the media library holds the WebP, its sizes are made from it, and the page, its CSS and the editor all point at it,
 * with nothing rewritten while a page is served. On this server's GD, at WordPress's own WebP quality (86, `wp_editor_set_quality`),
 * H2O's icons became 12–13% of their PNG, its logos 54%, its photographs 55–65%.
 *
 * Not converted: a picture the server cannot write as WebP, an animated PNG, a palette PNG under GD (which cannot encode one), a
 * file under MIN_BYTES, a favicon or touch icon (browsers and platforms that ask for those want PNG), and a WebP that would not be at
 * most RATIO of the file. Then the file is stored as it came. `dxai_ui_webp` turns it off.
 */
final class Webp {

	/** Post meta on a WebP attachment: the SHA-1 of the file it was made from, so the same file is not converted again. */
	public const SOURCE_META = '_dxai_ui_webp_of';

	/** Post meta on a JPEG or PNG attachment whose WebP was tried and not kept: it is not tried again. */
	public const KEPT_META = '_dxai_ui_webp_kept';

	/** Below this, a file has nothing to win. */
	public const MIN_BYTES = 2048;

	/** A WebP is kept when it is at most this share of the file. */
	public const RATIO = 0.9;

	/** Whether this file would be tried as WebP: a JPEG or PNG the site may and can convert. */
	public static function wanted( string $path, string $filename ): bool {
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) || ! is_readable( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size < self::MIN_BYTES ) {
			return false;
		}
		if ( preg_match( '/(?:^|[-_.\/])(?:favicon|apple-touch-icon|android-chrome|mstile|site-?icon)/i', $filename ) ) {
			return false;
		}
		if ( 'png' === $ext && ( self::animated( $path ) || ( self::palette( $path ) && 'WP_Image_Editor_GD' === self::editor() ) ) ) {
			return false;
		}
		$able = '' !== self::editor();

		/**
		 * Whether a design's JPEG or PNG picture is stored as WebP (when the WebP is smaller).
		 *
		 * @param bool   $on       Whether the server can write WebP.
		 * @param string $filename The picture's file name.
		 */
		return (bool) apply_filters( 'dxai_ui_webp', $able, $filename );
	}

	/**
	 * The file as WebP in a temporary file, with its name: turned upright first (a phone's photograph carries its turn in EXIF, which
	 * WebP does not keep). Null when it cannot be made, or would not be at most RATIO of the file; the caller deletes the temporary file.
	 *
	 * @return array{path: string, filename: string}|null
	 */
	public static function make( string $path, string $filename ): ?array {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$type   = wp_check_filetype( $filename );
		$editor = wp_get_image_editor(
			$path,
			array(
				'mime_type'        => is_string( $type['type'] ) ? $type['type'] : 'image/jpeg',
				'output_mime_type' => 'image/webp',
			)
		);
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		$editor->maybe_exif_rotate();
		$dir   = get_temp_dir();
		$saved = $editor->save( $dir . wp_unique_filename( $dir, 'dxai-webp-' . wp_generate_password( 8, false ) . '.webp' ), 'image/webp' );
		$made  = is_array( $saved ) ? (string) $saved['path'] : '';
		$size  = '' !== $made && is_file( $made ) ? filesize( $made ) : false;
		$was   = filesize( $path );
		if ( false === $size || 0 === $size || false === $was || $size > $was * self::RATIO ) {
			if ( '' !== $made && is_file( $made ) ) {
				wp_delete_file( $made );
			}

			return null;
		}
		$base = (string) pathinfo( $filename, PATHINFO_FILENAME );

		return array(
			'path'     => $made,
			'filename' => ( '' !== $base ? $base : 'image' ) . '.webp',
		);
	}

	/** The image editor that can write WebP here, '' when none. */
	private static function editor(): string {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/webp' ) );

		return is_string( $editor ) ? $editor : '';
	}

	/** Whether a PNG is animated (APNG): its `acTL` chunk comes before the first image data. */
	private static function animated( string $path ): bool {
		$head = (string) file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$anim = strpos( $head, 'acTL' );
		$data = strpos( $head, 'IDAT' );

		return false !== $anim && ( false === $data || $anim < $data );
	}

	/** Whether a PNG keeps its colours in a palette (colour type 3 in its header). */
	private static function palette( string $path ): bool {
		$head = (string) file_get_contents( $path, false, null, 0, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return strlen( $head ) > 25 && str_starts_with( $head, "\x89PNG" ) && 3 === ord( $head[25] );
	}
}
