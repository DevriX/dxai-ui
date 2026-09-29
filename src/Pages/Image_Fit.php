<?php
/**
 * Old-site pictures in the design's image slots.
 *
 * The file is sideloaded once (Sideloader keeps one attachment per file), and
 * when the slot shows its picture at its own proportions — the design does
 * not crop it with object-fit — a copy is cut to the slot's aspect ratio from
 * the centre, never upscaled, and kept as its own attachment so the next page
 * that needs the same crop gets it without work. That is what keeps a
 * section's layout identical to the Home's whatever the old picture's shape.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Media\Sideloader;

final class Image_Fit {

	public const CROP_META = '_dxai_ui_crop_of';

	/**
	 * @return array{url:string, id:int}|null
	 */
	public static function fit( string $src, float $aspect, bool $crop ): ?array {
		$got = Sideloader::from_url( $src );
		if ( is_wp_error( $got ) || empty( $got['id'] ) ) {
			return null;
		}
		$id = (int) $got['id'];
		if ( ! $crop || $aspect <= 0 ) {
			return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id );
		}
		$meta = wp_get_attachment_metadata( $id );
		$w    = (int) ( $meta['width'] ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );
		if ( $w < 1 || $h < 1 || abs( $w / $h - $aspect ) < 0.01 ) {
			return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id );
		}
		$key  = $id . '@' . number_format( $aspect, 3, '.', '' );
		$have = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => self::CROP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( $have ) {
			return array( 'url' => (string) wp_get_attachment_url( (int) $have[0] ), 'id' => (int) $have[0] );
		}
		$file = get_attached_file( $id );
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		if ( $w / $h > $aspect ) {
			$cw = (int) round( $h * $aspect );
			$ch = $h;
		} else {
			$cw = $w;
			$ch = (int) round( $w / $aspect );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id );
		}
		$editor->crop( (int) floor( ( $w - $cw ) / 2 ), (int) floor( ( $h - $ch ) / 2 ), $cw, $ch );
		$ext   = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		$tmp   = wp_tempnam( 'dxai-crop' ) . '.' . $ext;
		$saved = $editor->save( $tmp );
		if ( is_wp_error( $saved ) ) {
			return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$name   = pathinfo( $file, PATHINFO_FILENAME ) . '-' . $cw . 'x' . $ch . '.' . $ext;
		$new_id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => (string) $saved['path'] ), 0, (string) get_the_title( $id ) );
		if ( is_wp_error( $new_id ) ) {
			return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id );
		}
		update_post_meta( $new_id, self::CROP_META, $key );
		update_post_meta( $new_id, '_wp_attachment_image_alt', (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		return array( 'url' => (string) wp_get_attachment_url( $new_id ), 'id' => (int) $new_id );
	}
}
