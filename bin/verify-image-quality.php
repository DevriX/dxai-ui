<?php
/**
 * A design's photographs are written as light as Lighthouse asks, at the highest quality that is.
 *
 *   bash bin/wp-php.sh bin/verify-image-quality.php        (or: wp eval-file bin/verify-image-quality.php --user=1)
 *
 * Lighthouse counts a picture as too heavy above 1/6 of a byte a pixel, and every byte that starts before the largest paint shares
 * the phone's bandwidth with it (Five Star: LCP 3.5 s with WordPress's WebP quality, 86; docs/PLAN-PERFECT-PAGES.md, T5.1).
 * Media\Image_Quality writes a photograph (a lossy WebP without transparency) that is over the target again at the highest of 82,
 * 78, 74 that meets it, or at 74: the WebP the import makes of a JPEG, each size WordPress makes of it, the scaled copy of a large
 * one. Not a picture under the target, one with transparency, the design's own file, nor anything when `dxai_ui_image_quality`
 * says no. A request that has used half its time limit leaves the sizes to cron; a picture stored before is fitted when an import
 * meets it again.
 *
 * Each file is checked against GD's own bytes for that picture at each quality (an encoder writes the same bytes for the same
 * pixels and quality), so "the highest quality that meets the target" is checked as such, not as "smaller".
 *
 * Makes its pictures in the temp folder and its attachments in the media library, and deletes them, also when a check dies
 * half-way. Exit code 1 when a check fails, 77 when this server cannot write WebP.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Image_Quality;
use DXAI_UI\Media\Sideloader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require DXAI_UI_DIR . 'bin/verify-cleanup.php';

if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
	echo "SKIP  this server's image editor cannot write WebP\n";
	exit( 77 );
}

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

$snapshot  = dxai_verify_snapshot();
$files     = array();
$cleaned   = false;
$started   = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true );
$limit     = (string) ini_get( 'max_execution_time' );
$clean     = static function () use ( &$files, &$cleaned, $snapshot, $started, $limit ): void {
	if ( $cleaned ) {
		return;
	}
	$cleaned                       = true;
	$_SERVER['REQUEST_TIME_FLOAT'] = $started;
	ini_set( 'max_execution_time', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	wp_unschedule_hook( Image_Quality::HOOK );
	foreach ( array_reverse( $files ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		} elseif ( is_dir( $file ) ) {
			rmdir( $file );
		}
	}
	dxai_verify_cleanup( $snapshot, false );
};
register_shutdown_function( $clean );

$dir = trailingslashit( get_temp_dir() ) . 'dxai-verify-quality-' . wp_generate_password( 6, false ) . '/';
wp_mkdir_p( $dir );
$files[] = $dir;

// A made-up photograph: shades, edges and grain ($grain 0: smooth, under the target at any quality); $seed makes its bytes its own.
$photo = static function ( int $seed, int $grain, int $w = 900, int $h = 600 ) {
	$im = imagecreatetruecolor( $w, $h );
	$c  = static fn( $f ) => max( 0, min( 255, (int) $f ) );
	mt_srand( $seed );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$v = 128 + 60 * sin( $x / 23 ) + 40 * cos( $y / 17 ) + ( ( $x >> 5 ) + ( $y >> 5 ) ) % 2 * 30;
			$n = $grain > 0 ? mt_rand( -$grain, $grain ) : 0;
			$g = $grain > 0 ? mt_rand( -$grain, $grain ) : 0;
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, $c( $v + $n ), $c( $v * 0.8 + $g ), $c( 255 - $v + $n ) ) );
		}
	}
	return $im;
};
$jpeg = static function ( $im, string $name ) use ( $dir, &$files ): string {
	$path = $dir . $name;
	imagejpeg( $im, $path, 92 );
	$files[] = $path;
	return $path;
};

// GD's bytes for a picture at a quality: the file $from, turned upright if asked, fitted into $box as WordPress fits a size.
$bytes_at = static function ( string $from, ?array $box, int $q, bool $upright = false, string $as = 'image/webp' ) use ( $dir ): int {
	$ed = wp_get_image_editor( $from, array( 'output_mime_type' => $as ) );
	if ( is_wp_error( $ed ) ) {
		return -1;
	}
	if ( $upright ) {
		$ed->maybe_exif_rotate();
	}
	if ( null !== $box ) {
		$ed->resize( $box[0], $box[1], $box[2] );
	}
	// A save that changes the type sets the quality back to the type's own, through the filter (get_output_format()): held there.
	$hold = static fn() => $q;
	add_filter( 'wp_editor_set_quality', $hold, PHP_INT_MAX );
	$ed->set_quality( $q );
	$saved = $ed->save( $dir . 'ref-' . wp_generate_password( 6, false ) . '.webp', 'image/webp' );
	remove_filter( 'wp_editor_set_quality', $hold, PHP_INT_MAX );
	if ( is_wp_error( $saved ) ) {
		return -1;
	}
	clearstatcache();
	$n = (int) filesize( $saved['path'] );
	wp_delete_file( $saved['path'] );
	return $n;
};
// The bytes the rule keeps for that picture, and the quality: 86 when it meets the target there (left as WordPress wrote it), else the
// highest of 82, 78, 74 that meets it, else 74.
$kept_at = static function ( string $from, ?array $box, int $pixels, bool $upright = false, string $as = 'image/webp' ) use ( $bytes_at ): array {
	foreach ( array( 86, 82, 78, 74 ) as $q ) {
		$n = $bytes_at( $from, $box, $q, $upright, $as );
		if ( $n > 0 && $n / $pixels <= Image_Quality::TARGET ) {
			return array( $n, $q );
		}
	}
	return array( $n, 74 );
};
// An attachment's sizes, each: its file's bytes, the bytes the rule keeps, the quality, and whether its metadata says its bytes.
$sizes_of = static function ( int $id ) use ( $kept_at ): array {
	$meta = wp_get_attachment_metadata( $id );
	$from = (string) get_attached_file( $id );
	$reg  = wp_get_registered_image_subsizes();
	$out  = array();
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
		$path = dirname( $from ) . '/' . $size['file'];
		clearstatcache( true, $path );
		$box               = isset( $reg[ $name ] ) ? array( (int) $reg[ $name ]['width'], (int) $reg[ $name ]['height'], $reg[ $name ]['crop'] ) : array( (int) $size['width'], (int) $size['height'], true );
		list( $n, $q )     = $kept_at( $from, $box, (int) $size['width'] * (int) $size['height'] );
		$dims              = wp_getimagesize( $path );
		$out[ $name ]      = array(
			'bytes' => (int) filesize( $path ),
			'rule'  => $n,
			'q'     => $q,
			'meta'  => (int) ( $size['filesize'] ?? -1 ) === (int) filesize( $path ),
			'dims'  => is_array( $dims ) && (int) $dims[0] === (int) $size['width'] && (int) $dims[1] === (int) $size['height'],
			'type'  => wp_get_webp_info( $path )['type'],
		);
	}
	return $out;
};
$show = static fn( array $sizes ): string => implode( ', ', array_map( static fn( $k, $s ) => $k . ' ' . $s['bytes'] . '/' . $s['rule'] . '@' . $s['q'], array_keys( $sizes ), $sizes ) );

echo "A photograph over the target\n";
$grainy = $jpeg( $photo( 11, 18 ), 'grainy.jpg' );
$t0     = microtime( true );
$got    = Sideloader::from_path( $grainy, 'grainy.jpg', 'src/assets/grainy.jpg' );
$took   = microtime( true ) - $t0;
$id     = is_array( $got ) ? (int) $got['id'] : 0;
$full   = $id > 0 ? (string) get_attached_file( $id ) : '';
list( $full_rule, $full_q ) = $kept_at( $grainy, null, 900 * 600, true );
$expect( 'its WebP (made of the JPEG) is written at the highest of 82, 78, 74 that meets 1/6 of a byte a pixel, or at 74', '' !== $full && $full_q < 86 && (int) filesize( $full ) === $full_rule, ( '' !== $full ? filesize( $full ) : 'none' ) . ' bytes, rule ' . $full_rule . ' at ' . $full_q . ' (86: ' . $bytes_at( $grainy, null, 86, true ) . ')' );
$sizes = $id > 0 ? $sizes_of( $id ) : array();
$over  = array_filter( $sizes, static fn( $s ) => $s['q'] < 86 );
$expect( 'each size WordPress made of it is written at the quality the rule keeps (some of them below 86)', array() !== $over && array() === array_filter( $sizes, static fn( $s ) => $s['bytes'] !== $s['rule'] ), $show( $sizes ) );
$expect( '…as a plain lossy WebP, at the size its metadata says, and the metadata holds its new bytes', array() !== $sizes && array() === array_filter( $sizes, static fn( $s ) => ! $s['dims'] || ! $s['meta'] || 'lossy' !== $s['type'] ), wp_json_encode( $sizes ) );
// Lighter grain: its WebP meets the target at the first step down. A save after a change of type (JPEG → WebP) sets the quality back
// to the type's own; that step is written at 82 all the same.
$light = $jpeg( $photo( 18, 10 ), 'light.jpg' );
$lg    = Sideloader::from_path( $light, 'light.jpg', 'src/assets/light.jpg' );
$lg_f  = is_array( $lg ) ? (string) get_attached_file( (int) $lg['id'] ) : '';
list( $lg_rule, $lg_q ) = $kept_at( $light, null, 900 * 600, true );
$expect( 'a lighter one: its WebP at the first step, 82 — written at 82, not at the 86 a save after the change of type comes back to', '' !== $lg_f && 82 === $lg_q && (int) filesize( $lg_f ) === $lg_rule, ( '' !== $lg_f ? filesize( $lg_f ) : 'none' ) . ' bytes, rule ' . $lg_rule . ' at ' . $lg_q );
$expect( 'the attachment is marked with the rule it was fitted by', $id > 0 && Image_Quality::RULE === get_post_meta( $id, Image_Quality::META, true ) );
printf( "  (stored and fitted in %.1f s; its WebP at %d, its sizes at %s)\n", $took, $full_q, implode( ', ', array_map( static fn( $k, $s ) => $k . ' ' . $s['q'], array_keys( $sizes ), $sizes ) ) );

echo "\nWhat is left as WordPress writes it\n";
$smooth   = $jpeg( $photo( 12, 0 ), 'smooth.jpg' );
$s_got    = Sideloader::from_path( $smooth, 'smooth.jpg', 'src/assets/smooth.jpg' );
$s_id     = is_array( $s_got ) ? (int) $s_got['id'] : 0;
$s_sizes  = $s_id > 0 ? $sizes_of( $s_id ) : array();
$s_full   = $s_id > 0 ? (string) get_attached_file( $s_id ) : '';
$expect( 'a photograph under the target: its WebP and every size at 86', '' !== $s_full && (int) filesize( $s_full ) === $bytes_at( $smooth, null, 86, true ) && array() !== $s_sizes && array() === array_filter( $s_sizes, static fn( $s ) => 86 !== $s['q'] || $s['bytes'] !== $s['rule'] ), $show( $s_sizes ) );
// A badge: the grainy picture with a transparent corner, as a PNG — its WebP keeps the transparency (VP8X), its letters' edges.
$badge = $photo( 13, 40 );
imagesavealpha( $badge, true );
imagealphablending( $badge, false );
imagefilledrectangle( $badge, 0, 0, 120, 120, imagecolorallocatealpha( $badge, 0, 0, 0, 127 ) );
$png     = $dir . 'badge.png';
$files[] = $png;
imagepng( $badge, $png, 9 );
$b_got   = Sideloader::from_path( $png, 'badge.png', 'src/assets/badge.png' );
$b_id    = is_array( $b_got ) ? (int) $b_got['id'] : 0;
$b_full  = $b_id > 0 ? (string) get_attached_file( $b_id ) : '';
$b_sizes = array();
foreach ( (array) ( wp_get_attachment_metadata( $b_id )['sizes'] ?? array() ) as $name => $size ) {
	$b_sizes[ $name ] = (int) filesize( dirname( $b_full ) . '/' . $size['file'] ) === $bytes_at( $b_full, array( (int) wp_get_registered_image_subsizes()[ $name ]['width'], (int) wp_get_registered_image_subsizes()[ $name ]['height'], wp_get_registered_image_subsizes()[ $name ]['crop'] ), 86 );
}
$expect( 'a picture with transparency (a badge, a logo): its WebP and every size as WordPress writes them, at 86', 'animated-alpha' === wp_get_webp_info( $b_full )['type'] && (int) filesize( $b_full ) === $bytes_at( $png, null, 86, true ) && array() !== $b_sizes && ! in_array( false, $b_sizes, true ), wp_json_encode( $b_sizes ) );
// The design's own WebP: the file stays the bytes it came as, only WordPress's sizes of it are fitted.
$own_src = $dir . 'own.webp';
$files[] = $own_src;
imagewebp( $photo( 14, 40 ), $own_src, 95 );
$o_got   = Sideloader::from_path( $own_src, 'own.webp', 'src/assets/own.webp' );
$o_id    = is_array( $o_got ) ? (int) $o_got['id'] : 0;
$o_sizes = $o_id > 0 ? $sizes_of( $o_id ) : array();
$expect( 'the design\'s own WebP is stored as it came; WordPress\'s sizes of it are fitted', $o_id > 0 && sha1_file( (string) get_attached_file( $o_id ) ) === sha1_file( $own_src ) && array() !== $o_sizes && array() === array_filter( $o_sizes, static fn( $s ) => $s['bytes'] !== $s['rule'] ) && array() !== array_filter( $o_sizes, static fn( $s ) => $s['q'] < 86 ), $show( $o_sizes ) );

// The same as a camera or an editor writes it: the extended format (VP8X) with an EXIF chunk before the picture — which
// wp_get_webp_info() calls "animated-alpha" — and no transparency. Five Star's photographs are all such files.
$plain = $dir . 'plain.webp';
$files[] = $plain;
imagewebp( $photo( 19, 40 ), $plain, 95 );
$riff    = (string) file_get_contents( $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$exif    = 'Exif' . str_repeat( "\0", 10 );
$chunk   = static fn( string $id, string $data ): string => $id . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) % 2 ? "\0" : '' );
$canvas  = substr( pack( 'V', 900 - 1 ), 0, 3 ) . substr( pack( 'V', 600 - 1 ), 0, 3 );
$body    = 'WEBP' . $chunk( 'VP8X', chr( 0x08 ) . "\0\0\0" . $canvas ) . $chunk( 'EXIF', $exif ) . substr( $riff, 12 );
$camera  = $dir . 'camera.webp';
$files[] = $camera;
file_put_contents( $camera, 'RIFF' . pack( 'V', strlen( $body ) ) . $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$c_got   = Sideloader::from_path( $camera, 'camera.webp', 'src/assets/camera.webp' );
$c_id    = is_array( $c_got ) ? (int) $c_got['id'] : 0;
$c_sizes = $c_id > 0 ? $sizes_of( $c_id ) : array();
$expect( 'a design\'s WebP with EXIF (the extended format, no transparency) is a photograph: its sizes are fitted', 'animated-alpha' === wp_get_webp_info( $camera )['type'] && array() !== $c_sizes && array() === array_filter( $c_sizes, static fn( $s ) => $s['bytes'] !== $s['rule'] ) && array() !== array_filter( $c_sizes, static fn( $s ) => $s['q'] < 86 ), $show( $c_sizes ) );

echo "\nA large picture: WordPress's scaled copy\n";
$threshold = static fn() => 800;
add_filter( 'big_image_size_threshold', $threshold );
$large   = $dir . 'large.webp';
$files[] = $large;
imagewebp( $photo( 15, 40, 1200, 800 ), $large, 95 );
$l_got  = Sideloader::from_path( $large, 'large.webp', 'src/assets/large.webp' );
$l_id   = is_array( $l_got ) ? (int) $l_got['id'] : 0;
$l_meta = $l_id > 0 ? wp_get_attachment_metadata( $l_id ) : array();
$scaled = $l_id > 0 ? (string) get_attached_file( $l_id ) : '';
list( $sc_rule, $sc_q ) = $kept_at( $large, array( 800, 800, false ), 800 * 533, true );
$expect( 'its scaled copy (800 px) is written at the quality the rule keeps, and the metadata holds its bytes', ! empty( $l_meta['original_image'] ) && $sc_q < 86 && (int) filesize( $scaled ) === $sc_rule && (int) ( $l_meta['filesize'] ?? 0 ) === $sc_rule, ( '' !== $scaled ? filesize( $scaled ) : 'none' ) . ' / ' . $sc_rule . '@' . $sc_q . ' ' . wp_json_encode( array_intersect_key( $l_meta, array_flip( array( 'file', 'width', 'height', 'filesize', 'original_image' ) ) ) ) );
$expect( '…and the original it was made of is the design\'s file, as it came', $l_id > 0 && sha1_file( (string) wp_get_original_image_path( $l_id ) ) === sha1_file( $large ) );
remove_filter( 'big_image_size_threshold', $threshold );

echo "\nOff, before, and late\n";
$off   = static fn() => false;
$fresh = $jpeg( $photo( 16, 40 ), 'fresh.jpg' );
// A size of the theme's, registered square when the picture is stored and as a strip by the time it is met again.
add_image_size( 'dxai-quality-test', 320, 320, true );
add_filter( 'dxai_ui_image_quality', $off );
$f_got = Sideloader::from_path( $fresh, 'fresh.jpg', 'src/assets/fresh.jpg' );
remove_filter( 'dxai_ui_image_quality', $off );
$f_id    = is_array( $f_got ) ? (int) $f_got['id'] : 0;
$f_full  = $f_id > 0 ? (string) get_attached_file( $f_id ) : '';
$at_86   = static function ( int $id ): bool {
	$from = (string) get_attached_file( $id );
	$reg  = wp_get_registered_image_subsizes();
	foreach ( (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? array() ) as $name => $size ) {
		$ed = wp_get_image_editor( $from );
		$ed->resize( (int) $reg[ $name ]['width'], (int) $reg[ $name ]['height'], $reg[ $name ]['crop'] );
		$ed->set_quality( 86 );
		$saved = $ed->save( $from . '.ref.webp', 'image/webp' );
		clearstatcache();
		$same = (int) filesize( $saved['path'] ) === (int) filesize( dirname( $from ) . '/' . $size['file'] );
		wp_delete_file( $saved['path'] );
		if ( ! $same ) {
			return false;
		}
	}
	return true;
};
$expect( 'with the filter saying no: the WebP and its sizes at 86, and the attachment not marked', $f_id > 0 && (int) filesize( $f_full ) === $bytes_at( $fresh, null, 86, true ) && $at_86( $f_id ) && '' === get_post_meta( $f_id, Image_Quality::META, true ) );
$strip_meta = $f_id > 0 ? (array) ( wp_get_attachment_metadata( $f_id )['sizes']['dxai-quality-test'] ?? array() ) : array();
$strip_path = isset( $strip_meta['file'] ) ? dirname( $f_full ) . '/' . $strip_meta['file'] : '';
$strip_sha  = '' !== $strip_path && is_file( $strip_path ) ? sha1_file( $strip_path ) : '';
add_image_size( 'dxai-quality-test', 320, 200, true );
$again   = Sideloader::from_path( $fresh, 'fresh.jpg', 'src/assets/fresh.jpg' );
$f_sizes = $f_id > 0 ? $sizes_of( $f_id ) : array();
$changed = $f_sizes['dxai-quality-test'] ?? null;
unset( $f_sizes['dxai-quality-test'] );
remove_image_size( 'dxai-quality-test' );
$expect( 'a picture stored before is fitted when an import meets it again (its sizes; the same attachment)', is_array( $again ) && $f_id === (int) $again['id'] && array() !== $f_sizes && array() === array_filter( $f_sizes, static fn( $s ) => $s['bytes'] !== $s['rule'] ) && Image_Quality::RULE === get_post_meta( $f_id, Image_Quality::META, true ), $show( $f_sizes ) );
$expect( '…but not a size whose registration has changed since (WordPress made it square; now it would be a strip): its file and size kept', '' !== $strip_sha && sha1_file( $strip_path ) === $strip_sha && null !== $changed && $changed['dims'], wp_json_encode( array( $strip_meta, $changed ) ) );

// A request that has run 30 s of its 40 (timer_float() counts from REQUEST_TIME_FLOAT; the limit is set for the call alone).
$late                          = $jpeg( $photo( 17, 40 ), 'late.jpg' );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 30;
ini_set( 'max_execution_time', '40' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
$l2_got = Sideloader::from_path( $late, 'late.jpg', 'src/assets/late.jpg' );
ini_set( 'max_execution_time', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
$_SERVER['REQUEST_TIME_FLOAT'] = $started;
$l2_id                         = is_array( $l2_got ) ? (int) $l2_got['id'] : 0;
$expect( 'a request that has used half its time limit leaves the sizes to cron: one event for the attachment, nothing written', $l2_id > 0 && false !== wp_next_scheduled( Image_Quality::HOOK, array( $l2_id ) ) && $at_86( $l2_id ) && '' === get_post_meta( $l2_id, Image_Quality::META, true ) );
do_action( Image_Quality::HOOK, $l2_id );
$l2_sizes = $l2_id > 0 ? $sizes_of( $l2_id ) : array();
$expect( '…and cron fits them', array() !== $l2_sizes && array() === array_filter( $l2_sizes, static fn( $s ) => $s['bytes'] !== $s['rule'] ) && Image_Quality::RULE === get_post_meta( $l2_id, Image_Quality::META, true ), $show( $l2_sizes ) );
wp_unschedule_hook( Image_Quality::HOOK );

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
