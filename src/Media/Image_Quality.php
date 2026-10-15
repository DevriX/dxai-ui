<?php
/**
 * A design's photographs written as light as Lighthouse asks, at the highest quality that is.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * Lighthouse's "Improve image delivery" counts a picture as too heavy above 1/6 of a byte a pixel (the trace engine's ImageDelivery
 * insight; WebP is held to it too), and Lantern shares the phone's bandwidth among every request that starts before the largest
 * paint. On Five Star the hero's 100 KiB and five cards' 380 KiB, all written by WordPress at its WebP quality (86), took the LCP to
 * 3.5 s (docs/PLAN-PERFECT-PAGES.md, T5.1). Its photographs at 768 px on GD: quality 86 is 0.20–0.23 byte a pixel, and 82, 78, 74
 * take about 14% off each (SSIM against quality 100: 0.987, 0.983, 0.977, 0.972); below 74 a step takes 4–5% for the same loss.
 *
 * So a design's photograph — a lossy WebP without transparency, with EXIF or a colour profile or without — that is over the target
 * at WordPress's quality is written again at the highest of 82, 78, 74 that meets it, or at 74: each size WordPress made of it, the
 * scaled copy of a large one, the WebP the import makes of a JPEG and the crop it makes for a slot. Five Star: LCP 3.5 → 2.8 s,
 * Performance 91 → 95. Never the design's own file, a picture with transparency (a logo, a badge: lossy edges show around their
 * letters), nor a lossless one. Sizes are written in the request that imports the picture, by cron once that request has used half
 * its time limit; an attachment imported before is fitted when an import meets it again. `dxai_ui_image_quality` turns it off.
 */
final class Image_Quality {

	/** Post meta on an attachment whose sizes were fitted: the rule they were fitted by (a changed rule fits them again). */
	public const META = '_dxai_ui_quality';

	/** The rule, as META holds it. */
	public const RULE = '1/6 byte a pixel, quality 74 at least';

	/** Bytes a pixel Lighthouse asks of a photograph. */
	public const TARGET = 1 / 6;

	/** The lowest quality a photograph is written at. */
	public const FLOOR = 74;

	/** One step down, in quality. */
	public const STEP = 4;

	/** Cron: one attachment's sizes, when the request that imported it ran long. */
	public const HOOK = 'dxai_ui_fit_image_quality';

	public function register(): void {
		add_action( self::HOOK, array( self::class, 'fit' ) );
	}

	/**
	 * The editor's picture saved to $path, and — a photograph over the target — again at the quality that meets it.
	 *
	 * @return array<string, mixed>|\WP_Error What the editor's save() gives for the file kept.
	 */
	public static function save( \WP_Image_Editor $editor, string $path, ?string $mime = null ): array|\WP_Error {
		$saved = $editor->save( $path, $mime );
		if ( is_wp_error( $saved ) || ! self::on( 0 ) ) {
			return $saved;
		}
		$down = self::down( $editor, (string) $saved['path'], (int) $editor->get_quality() );

		return $down ?? $saved;
	}

	/** An attachment's sizes fitted now, or by cron when this request has used half its time limit. */
	public static function fit_or_queue( int $id ): void {
		if ( $id < 1 || self::RULE === get_post_meta( $id, self::META, true ) ) {
			return;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 || wp_doing_cron() || timer_float() < $limit / 2 ) {
			self::fit( $id );
		} elseif ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $id ) );
		}
	}

	/**
	 * Each size WordPress made of an attachment, and its scaled copy, fitted to the target.
	 *
	 * @param int|string|mixed $id The attachment (cron hands it as it was scheduled).
	 */
	public static function fit( $id ): void {
		$id = (int) $id;
		if ( $id < 1 || self::RULE === get_post_meta( $id, self::META, true ) || ! self::on( $id ) ) {
			return;
		}
		$meta = wp_get_attachment_metadata( $id );
		$made = (string) get_attached_file( $id );
		if ( ! is_array( $meta ) || '' === $made || ! is_readable( $made ) ) {
			return;
		}
		// The picture is a photograph or none of its sizes is: a badge's thumbnail, cut from its middle, has no transparent pixel left.
		if ( 'image/jpeg' !== wp_get_image_mime( $made ) && ! self::photo( $made ) ) {
			update_post_meta( $id, self::META, self::RULE );

			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$dir        = dirname( $made );
		$registered = wp_get_registered_image_subsizes();
		// What WordPress made each from, and how: its sizes from the attached file (the scaled copy, when there is one), as
		// _wp_make_subsizes() does; the scaled copy from the original, turned upright as wp_create_image_subsizes() turns it.
		$jobs = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$box    = isset( $registered[ $name ] ) ? array( (int) $registered[ $name ]['width'], (int) $registered[ $name ]['height'], $registered[ $name ]['crop'] ) : array( (int) $size['width'], (int) $size['height'], true );
			$jobs[] = array( 'size', (string) $name, $dir . '/' . $size['file'], (int) $size['width'], (int) $size['height'], $box, $made );
		}
		$original = (string) wp_get_original_image_path( $id );
		if ( ! empty( $meta['original_image'] ) && '' !== $original && $original !== $made ) {
			$jobs[] = array( 'scaled', '', $made, (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), array( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), false ), $original );
		}
		$changed = false;
		foreach ( $jobs as list( $kind, $name, $path, $width, $height, $box, $from ) ) {
			if ( ! is_file( $path ) || ! self::photo( $path ) || self::bytes_per_pixel( $path ) <= self::TARGET || ! is_readable( $from ) ) {
				continue;
			}
			$editor = wp_get_image_editor( $from );
			if ( is_wp_error( $editor ) ) {
				break;
			}
			if ( 'scaled' === $kind ) {
				$editor->maybe_exif_rotate();
			}
			$resized = $editor->resize( $box[0], $box[1], $box[2] );
			$got     = $editor->get_size();
			// Not the size WordPress made (another crop, a rounding apart): left as it is.
			if ( is_wp_error( $resized ) || $width !== (int) $got['width'] || $height !== (int) $got['height'] ) {
				continue;
			}
			$tmp = $path . '.dxai-quality.webp';
			if ( ! copy( $path, $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				continue;
			}
			$down = self::down( $editor, $tmp, (int) $editor->get_quality() );
			clearstatcache( true, $tmp );
			clearstatcache( true, $path );
			$bytes = (int) filesize( $tmp );
			if ( null === $down || $bytes < 1 || $bytes >= (int) filesize( $path ) || ! self::replace( $tmp, $path ) ) {
				wp_delete_file( $tmp );
				continue;
			}
			if ( 'size' === $kind ) {
				$meta['sizes'][ $name ]['filesize'] = $bytes;
			} else {
				$meta['filesize'] = $bytes;
			}
			$changed = true;
		}
		if ( $changed ) {
			wp_update_attachment_metadata( $id, $meta );
		}
		update_post_meta( $id, self::META, self::RULE );
	}

	/**
	 * Writes the editor's picture to $file again, down from $at, until it meets the target or is at the floor: $file holds it at $at.
	 * The editor's quality is set back after.
	 *
	 * @return array<string, mixed>|null The last save() answer; null when it was not written again.
	 */
	private static function down( \WP_Image_Editor $editor, string $file, int $at ): ?array {
		if ( ! self::photo( $file ) || $at <= self::FLOOR || $at > 100 || self::bytes_per_pixel( $file ) <= self::TARGET ) {
			return null;
		}
		// A step at a time from the quality it was written at, so the one kept is the highest that meets the target.
		$q    = max( self::FLOOR, $at - self::STEP );
		$last = null;
		// A save can set the quality back to its type's own (the first two saves after a change of type do: get_output_format()),
		// through the filter: held there while it is written.
		$hold = static function () use ( &$q ) {
			return $q;
		};
		add_filter( 'wp_editor_set_quality', $hold, PHP_INT_MAX );
		while ( $q > 0 ) {
			$editor->set_quality( $q );
			$saved = $editor->save( $file, 'image/webp' );
			if ( is_wp_error( $saved ) ) {
				break;
			}
			$last = $saved;
			$q    = $q > self::FLOOR && self::bytes_per_pixel( $file ) > self::TARGET ? max( self::FLOOR, $q - self::STEP ) : 0;
		}
		remove_filter( 'wp_editor_set_quality', $hold, PHP_INT_MAX );
		$editor->set_quality( $at );

		return $last;
	}

	/**
	 * Whether a file is a photograph as WebP writes one: lossy, without transparency or animation. A plain file (VP8) is; an extended
	 * one (VP8X, which wp_get_webp_info() calls "animated-alpha" whatever it holds) is when its first picture chunk is lossy data with
	 * no ALPH or animation before it — a design's photographs come with a colour profile or EXIF that way (all of Five Star's).
	 */
	private static function photo( string $file ): bool {
		$type = wp_get_webp_info( $file )['type'];
		if ( 'animated-alpha' !== $type ) {
			return 'lossy' === $type;
		}
		// The chunks after the RIFF header, up to the first that is the picture's (or says it moves or is transparent).
		$at = 12;
		for ( $i = 0; $i < 16; $i++ ) {
			$head = (string) file_get_contents( $file, false, null, $at, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( strlen( $head ) < 8 ) {
				return false;
			}
			$id = substr( $head, 0, 4 );
			if ( in_array( $id, array( 'VP8 ', 'VP8L', 'ALPH', 'ANIM', 'ANMF' ), true ) ) {
				return 'VP8 ' === $id;
			}
			$size = (int) unpack( 'V', substr( $head, 4, 4 ) )[1];
			$at  += 8 + $size + ( $size & 1 );
		}

		return false;
	}

	/** A WebP file's bytes over its pixels; 0 when it cannot be read. */
	private static function bytes_per_pixel( string $file ): float {
		clearstatcache( true, $file );
		$info   = wp_get_webp_info( $file );
		$pixels = (int) $info['width'] * (int) $info['height'];
		$bytes  = filesize( $file );

		return $pixels > 0 && false !== $bytes ? $bytes / $pixels : 0.0;
	}

	/** $tmp in $path's place. */
	private static function replace( string $tmp, string $path ): bool {
		if ( @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		if ( ! copy( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return false;
		}
		wp_delete_file( $tmp );

		return true;
	}

	/** Whether pictures are written to the target. */
	private static function on( int $id ): bool {
		/**
		 * Whether a design's photographs (lossy WebP, no transparency) are written again at the highest quality — down to 74 — that
		 * meets Lighthouse's 1/6 of a byte a pixel, when WordPress's quality is over it.
		 *
		 * @param bool $on Whether they are.
		 * @param int  $id The attachment; 0 for a file written before it is one (the WebP of a JPEG, a crop for a slot).
		 */
		return (bool) apply_filters( 'dxai_ui_image_quality', true, $id );
	}
}
