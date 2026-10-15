<?php
/**
 * A design's JPEG and PNG pictures are stored as WebP when that is smaller.
 *
 *   bash bin/wp-php.sh bin/verify-webp.php        (or: wp eval-file bin/verify-webp.php --user=1)
 *
 * Lighthouse's first finding on every page of H2O Away was "Serve images in next-gen formats" (docs/PLAN-PERFECT-PAGES.md, T5.1).
 * The import's Sideloader hands the media library a WebP made of the file (Media\Webp): smaller, upright (a photograph's EXIF turn
 * applied), its sizes made from it, and the page, its CSS and the import's image map pointing at it. What is not converted: a file
 * the server cannot write as WebP, a GIF, an animated PNG, a palette PNG under GD, a file under 2 KB, a favicon or touch icon, a
 * WebP that would not be at most 90% of the file, and anything when `dxai_ui_webp` says no. A file stored before keeps its
 * attachment; the next import makes its WebP beside it and uses that.
 *
 * The pictures of the plugin's own blocks (a `dxai-ui/box` written as an <img>: Five Star's full-width hero, 2400 px on a phone) are
 * named after their attachment (Media\Image_Sizes), so WordPress writes their width, height, srcset and sizes as for an Image block.
 *
 * Makes its pictures in the temp folder, its attachments in the media library and a page, and deletes them, also when a check dies
 * half-way. Exit code 1 when a check fails, 77 when this server cannot write WebP.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Sideloader;
use DXAI_UI\Media\Webp;

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

$snapshot = dxai_verify_snapshot();
$files    = array();
$pages    = array();
$cleaned  = false;
$clean    = static function () use ( &$files, &$pages, &$cleaned, $snapshot ): void {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;
	foreach ( $pages as $page_id ) {
		wp_delete_post( (int) $page_id, true );
	}
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

$dir = trailingslashit( get_temp_dir() ) . 'dxai-verify-webp-' . wp_generate_password( 6, false ) . '/';
wp_mkdir_p( $dir );
$files[] = $dir;

// A made-up photograph: smooth shades and a little noise, as a camera's picture has; $seed makes each one's bytes its own.
$photo = static function ( int $seed, int $w = 400, int $h = 300 ) {
	$im = imagecreatetruecolor( $w, $h );
	mt_srand( $seed );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$v = max( 0, min( 255, (int) ( 128 + 60 * sin( $x / 23 ) + 40 * cos( $y / 17 ) + mt_rand( -12, 12 ) ) ) );
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, $v, (int) ( $v * 0.8 ), 255 - $v ) );
		}
	}
	return $im;
};
$write = static function ( $im, string $name ) use ( $dir, &$files ): string {
	$path = $dir . $name;
	str_ends_with( $name, '.png' ) ? imagepng( $im, $path, 9 ) : imagejpeg( $im, $path, 85 );
	$files[] = $path;
	return $path;
};
$jpg = $write( $photo( 1 ), 'photo.jpg' );
$png = $write( $photo( 2 ), 'photo.png' );
// A one-pixel checkerboard: a PNG of 2.4 KB whose WebP is some 80 KB.
$board = imagecreatetruecolor( 600, 600 );
$black = imagecolorallocate( $board, 0, 0, 0 );
$white = imagecolorallocate( $board, 255, 255, 255 );
for ( $y = 0; $y < 600; $y++ ) {
	for ( $x = 0; $x < 600; $x++ ) {
		imagesetpixel( $board, $x, $y, ( $x + $y ) % 2 ? $white : $black );
	}
}
$checker = $write( $board, 'checker.png' );
$tiny    = $write( $photo( 3, 8, 8 ), 'tiny.png' );
$fav     = $write( $photo( 4 ), 'favicon.png' );
$palette = imagecreatetruecolor( 400, 300 );
imagecopy( $palette, $photo( 5 ), 0, 0, 0, 0, 400, 300 );
imagetruecolortopalette( $palette, false, 64 );
$pal = $write( $palette, 'palette.png' );
// An animated PNG: its acTL chunk before the image data (the chunk's content is not read).
$apng    = $dir . 'moving.png';
$files[] = $apng;
$bytes   = (string) file_get_contents( $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
file_put_contents( $apng, substr( $bytes, 0, 33 ) . pack( 'N', 8 ) . 'acTL' . pack( 'NN', 2, 0 ) . 'CRC!' . substr( $bytes, 33 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
// A photograph taken with the phone on its side: 400×300 stored, EXIF orientation 6 (turn it 90° clockwise to see it upright).
$turned  = $dir . 'turned.jpg';
$files[] = $turned;
$plainj  = (string) file_get_contents( $write( $photo( 6 ), 'turned-plain.jpg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$exif    = 'Exif' . "\0\0" . 'MM' . "\0\x2A" . pack( 'N', 8 ) . pack( 'n', 1 ) . pack( 'nnN', 0x0112, 3, 1 ) . pack( 'nn', 6, 0 ) . pack( 'N', 0 );
file_put_contents( $turned, substr( $plainj, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $plainj, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/webp' ) );
echo "What is tried as WebP ({$editor})\n";
$expect( 'a JPEG photograph', Webp::wanted( $jpg, 'photo.jpg' ) );
$expect( 'a PNG', Webp::wanted( $png, 'photo.png' ) );
$expect( 'not a file under 2 KB', ! Webp::wanted( $tiny, 'tiny.png' ), (string) filesize( $tiny ) );
$expect( 'not a favicon or a touch icon (they are asked for as PNG)', ! Webp::wanted( $fav, 'favicon.png' ) && ! Webp::wanted( $fav, 'apple-touch-icon.png' ) && ! Webp::wanted( $fav, 'public/favicon-32x32.png' ) );
$expect( 'not a GIF (it may move)', ! Webp::wanted( $jpg, 'photo.gif' ) );
$expect( 'not an animated PNG', ! Webp::wanted( $apng, 'moving.png' ) );
if ( 'WP_Image_Editor_GD' === $editor ) {
	$expect( 'not a palette PNG under GD, which cannot encode one', ! Webp::wanted( $pal, 'palette.png' ) );
} else {
	$expect( 'a palette PNG under ' . $editor . ', which can encode one', Webp::wanted( $pal, 'palette.png' ) );
}
add_filter( 'dxai_ui_webp', '__return_false' );
$expect( 'nothing when the filter says no', ! Webp::wanted( $jpg, 'photo.jpg' ) );
remove_filter( 'dxai_ui_webp', '__return_false' );

echo "\nWhat a WebP is made of it\n";
$made = Webp::make( $jpg, 'photo.jpg' );
if ( null !== $made ) {
	$files[] = $made['path'];
}
$expect( 'a WebP file named after the picture', null !== $made && 'photo.webp' === $made['filename'] && 'image/webp' === wp_get_image_mime( $made['path'] ), wp_json_encode( $made ) );
$expect( 'at most 90% of the file', null !== $made && filesize( $made['path'] ) <= filesize( $jpg ) * Webp::RATIO, null !== $made ? filesize( $made['path'] ) . ' of ' . filesize( $jpg ) : '' );
$up = Webp::make( $turned, 'turned.jpg' );
if ( null !== $up ) {
	$files[] = $up['path'];
}
$dims = null !== $up ? wp_getimagesize( $up['path'] ) : false;
if ( is_callable( 'exif_read_data' ) ) {
	$expect( 'a photograph taken on its side comes out upright (EXIF turn applied: 400×300 → 300×400)', is_array( $dims ) && 300 === $dims[0] && 400 === $dims[1], is_array( $dims ) ? $dims[0] . '×' . $dims[1] : 'none' );
} else {
	echo "  n/a   the EXIF turn (this PHP has no exif extension, so WordPress cannot read it either)\n";
}
$before = glob( get_temp_dir() . 'dxai-webp-*' );
$none   = Webp::make( $checker, 'checker.png' );
$after  = glob( get_temp_dir() . 'dxai-webp-*' );
$expect( 'none when the WebP would be larger (a checkerboard: 2.4 KB as PNG), and its temporary file is gone', null === $none && count( (array) $before ) === count( (array) $after ), wp_json_encode( $none ) );

echo "\nWhat the import stores\n";
$count = static fn(): int => (int) ( new WP_Query(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
) )->found_posts;
$got = Sideloader::from_path( $jpg, 'photo.jpg', 'src/assets/photo.jpg' );
$id  = is_array( $got ) ? (int) $got['id'] : 0;
$expect( 'a JPEG is stored as its WebP: the attachment, its address and its type', $id > 0 && str_ends_with( (string) $got['url'], '.webp' ) && 'image/webp' === $got['mime'] && 'image/webp' === get_post_mime_type( $id ), is_wp_error( $got ) ? $got->get_error_message() : wp_json_encode( $got ) );
$expect( 'the answer keeps the caller\'s name and original, as the import\'s image map looks it up', is_array( $got ) && 'photo.jpg' === $got['filename'] && 'src/assets/photo.jpg' === $got['original'] );
$expect( 'the WebP knows the file it was made of, and its own bytes', $id > 0 && sha1_file( $jpg ) === get_post_meta( $id, Webp::SOURCE_META, true ) && sha1_file( (string) get_attached_file( $id ) ) === get_post_meta( $id, Sideloader::HASH_META, true ) );
$meta  = $id > 0 ? wp_get_attachment_metadata( $id ) : array();
$sizes = is_array( $meta ) ? array_column( (array) ( $meta['sizes'] ?? array() ), 'file' ) : array();
$expect( 'its sizes are made from it, as WebP', array() !== $sizes && array() === array_filter( $sizes, static fn( $f ) => ! str_ends_with( (string) $f, '.webp' ) ), wp_json_encode( $sizes ) );
$n     = $count();
$again = Sideloader::from_path( $jpg, 'photo.jpg', 'src/assets/photo.jpg' );
$expect( 'the same file met again is answered with the same WebP, and nothing is added', is_array( $again ) && $id === (int) $again['id'] && $n === $count() );

add_filter( 'dxai_ui_webp', '__return_false' );
$old = Sideloader::from_path( $png, 'photo.png', 'src/assets/photo.png' );
remove_filter( 'dxai_ui_webp', '__return_false' );
$old_id = is_array( $old ) ? (int) $old['id'] : 0;
$expect( 'with the filter saying no, a PNG is stored as it came', $old_id > 0 && 'image/png' === get_post_mime_type( $old_id ) );
$new    = Sideloader::from_path( $png, 'photo.png', 'src/assets/photo.png' );
$new_id = is_array( $new ) ? (int) $new['id'] : 0;
$expect( 'a file stored before WebP gets its WebP at the next import, beside it', $new_id > 0 && $new_id !== $old_id && 'image/webp' === get_post_mime_type( $new_id ) );
$expect( '…and the attachment stored before is kept, with its file', $old_id > 0 && null !== get_post( $old_id ) && is_file( (string) get_attached_file( $old_id ) ) );
$third = Sideloader::from_path( $png, 'photo.png', 'src/assets/photo.png' );
$expect( '…and that WebP is the answer from then on', is_array( $third ) && $new_id === (int) $third['id'] );

$kept    = Sideloader::from_path( $checker, 'checker.png', 'src/assets/checker.png' );
$kept_id = is_array( $kept ) ? (int) $kept['id'] : 0;
$expect( 'a file whose WebP would be larger is stored as it came, marked so it is not tried again', $kept_id > 0 && 'image/png' === get_post_mime_type( $kept_id ) && '1' === get_post_meta( $kept_id, Webp::KEPT_META, true ) );
$n    = $count();
$back = Sideloader::from_path( $checker, 'checker.png', 'src/assets/checker.png' );
$expect( '…and met again, it is that attachment', is_array( $back ) && $kept_id === (int) $back['id'] && $n === $count() );
$ico = Sideloader::from_path( $fav, 'favicon.png', 'public/favicon.png' );
$expect( 'a favicon is stored as PNG', is_array( $ico ) && 'image/png' === $ico['mime'] );

echo "\nA design's import\n";
$zip_path = $dir . 'verify-webp.zip';
$files[]  = $zip_path;
$zip      = new ZipArchive();
if ( true === $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	$zip->addFromString( 'index.html', '<!DOCTYPE html><html><head><link rel="stylesheet" href="style.css"></head><body><section class="hero"><h1>Dry in a day</h1></section><img src="crew.jpg" alt="The crew"></body></html>' );
	$zip->addFromString( 'style.css', '.hero { background-image: url(hero.jpg); min-height: 60vh; }' );
	$zip->addFromString( 'hero.jpg', (string) file_get_contents( $write( $photo( 7 ), 'hero.jpg' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$zip->addFromString( 'crew.jpg', (string) file_get_contents( $write( $photo( 8 ), 'crew.jpg' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$zip->close();
}
$doc = ( new DXAI_UI\Connectors\Generated_Design_Connector() )->import_zip(
	array(
		'tmp_name' => $zip_path,
		'name'     => 'verify-webp.zip',
	)
);
$css    = is_wp_error( $doc ) ? '' : (string) ( $doc->payload['styles']['style.css'] ?? '' );
$images = is_wp_error( $doc ) ? array() : (array) ( $doc->payload['harvest']['images'] ?? array() );
$crew   = array_values( array_filter( $images, static fn( $i ) => is_array( $i ) && str_ends_with( (string) ( $i['original'] ?? $i['filename'] ?? '' ), 'crew.jpg' ) ) );
$expect( 'the design\'s stylesheet points at the hero\'s WebP', 1 === preg_match( '#url\([\'"]?[^)\'"]*/hero[^)\'"/]*\.webp#', $css ), $css );
$expect( 'the image map gives the WebP for crew.jpg', isset( $crew[0]['url'] ) && str_ends_with( (string) $crew[0]['url'], '.webp' ), wp_json_encode( $crew ?: array_slice( $images, 0, 3 ) ) );

echo "\nThe pictures of the plugin's own blocks\n";
$full   = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';
$medium = $id > 0 ? (string) ( wp_get_attachment_image_src( $id, 'medium' )[0] ?? '' ) : '';
$box    = static fn( string $src, string $class, string $extra = '' ): string => '<!-- wp:dxai-ui/box {"tagName":"img","src":"' . esc_url( $src ) . '","className":"' . $class . '"} --><img src="' . esc_url( $src ) . '" alt="" class="' . $class . '"' . $extra . '/><!-- /wp:dxai-ui/box -->';
$scope  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Image sizes fixture Home', 'post_content' => 'x' ) );
$body   = $box( $full, 'w-full hero-shot' ) . $box( $medium, 'w-200px card-shot' ) . $box( 'https://example.test/uploads/elsewhere.jpg', 'w-full away-shot' ) . $box( $full, 'w-full own-set', ' srcset="' . esc_url( $full ) . ' 400w"' );
$sized  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Image sizes fixture', 'post_content' => $body ) );
$plain  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Image sizes fixture (no design)', 'post_content' => $body ) );
foreach ( array( $scope, $sized, $plain ) as $p ) {
	if ( ! is_wp_error( $p ) ) {
		$pages[] = (int) $p;
	}
}
if ( ! is_wp_error( $scope ) && ! is_wp_error( $sized ) ) {
	update_post_meta( (int) $sized, '_dxai_ui_scope_id', (string) $scope );
}
$render = static function ( $page_id ): string {
	if ( is_wp_error( $page_id ) || (int) $page_id < 1 ) {
		return '';
	}
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => (int) $page_id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( (int) $page_id );
	setup_postdata( $GLOBALS['post'] );

	return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', (int) $page_id ) );
};
$img_of = static fn( string $html, string $class ): string => preg_match( '#<img\b[^>]*\b' . preg_quote( $class, '#' ) . '\b[^>]*>#', $html, $m ) ? $m[0] : '';
$html   = $render( $sized );
$hero   = $img_of( $html, 'hero-shot' );
$expect( 'a picture of the plugin\'s block is named after its attachment, and WordPress writes its width, height, srcset and sizes', $id > 0 && str_contains( $hero, 'wp-image-' . $id ) && str_contains( $hero, 'width="400"' ) && str_contains( $hero, 'height="300"' ) && str_contains( $hero, 'srcset=' ) && str_contains( $hero, 'sizes=' ), $hero );
$expect( '…also when it shows one of the attachment\'s sizes', $medium !== '' && str_contains( $img_of( $html, 'card-shot' ), 'wp-image-' . $id ), $img_of( $html, 'card-shot' ) );
$expect( 'a picture from another site is left as it is', '' !== $img_of( $html, 'away-shot' ) && ! str_contains( $img_of( $html, 'away-shot' ), 'wp-image-' ) );
$expect( 'a picture with a srcset of its own is left as it is', '' !== $img_of( $html, 'own-set' ) && ! str_contains( $img_of( $html, 'own-set' ), 'wp-image-' ) );
$expect( 'not on a page that is no design\'s', ! str_contains( $img_of( $render( $plain ), 'hero-shot' ), 'wp-image-' ) );
add_filter( 'dxai_ui_image_sizes', '__return_false' );
$off = $render( $sized );
remove_filter( 'dxai_ui_image_sizes', '__return_false' );
$expect( 'nothing when the filter says no', '' !== $img_of( $off, 'hero-shot' ) && ! str_contains( $img_of( $off, 'hero-shot' ), 'wp-image-' ) );

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
