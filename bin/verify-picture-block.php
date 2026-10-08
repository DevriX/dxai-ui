<?php
/**
 * An image of a design is the team's DX Picture block (dx/picture) where the site has it, and core's Image where it has not: the converter
 * (Native\Picture), what it declines, the fallback, and that the team's page tools know the block.
 *
 *   bash bin/wp-php.sh bin/verify-picture-block.php        (or: wp eval-file bin/verify-picture-block.php --user=1)
 *
 * Makes three attachments (a picture, a second one, an SVG) and removes them again. Where the active theme does not register dx/picture a
 * stand-in is registered for the run. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Blocks\Native\Picture;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Variants;

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

$reg      = WP_Block_Type_Registry::get_instance();
$stand_in = false;
if ( ! $reg->is_registered( 'dx/picture' ) ) {
	// The theme's block is not here: a server-rendered stand-in with the attributes the converter writes.
	register_block_type(
		'dx/picture',
		array(
			'attributes'      => array(
				'imageId'       => array( 'type' => 'integer', 'default' => 0 ),
				'imageUrl'      => array( 'type' => 'string', 'default' => '' ),
				'imageAlt'      => array( 'type' => 'string', 'default' => '' ),
				'imageWidth'    => array( 'type' => 'integer', 'default' => 0 ),
				'imageHeight'   => array( 'type' => 'integer', 'default' => 0 ),
				'mobileSize'    => array( 'type' => 'string', 'default' => 'dx_pic_mobile_4x5' ),
				'desktopSize'   => array( 'type' => 'string', 'default' => 'dx_pic_desktop_hero' ),
				'priority'      => array( 'type' => 'boolean', 'default' => false ),
			),
			'render_callback' => static fn( $a ) => '<figure class="dx-picture"><picture><img src="' . esc_url( (string) $a['imageUrl'] ) . '"/></picture></figure>',
		)
	);
	$stand_in = true;
}

// Attachments to point at.
$dir = wp_upload_dir();
wp_mkdir_p( $dir['basedir'] . '/dxai-picture-test' );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><circle cx="12" cy="12" r="10"/></svg>';
$made = array();
$mk   = static function ( string $name, string $body, string $mime, array $meta ) use ( $dir, &$made ): int {
	$path = $dir['basedir'] . '/dxai-picture-test/' . $name;
	file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
	$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( (int) $id, $meta + array( 'file' => 'dxai-picture-test/' . $name ) );
	$made[] = (int) $id;

	return (int) $id;
};
$a   = $mk( 'a.png', (string) $png, 'image/png', array( 'width' => 1200, 'height' => 630 ) );
$b   = $mk( 'b.png', (string) $png, 'image/png', array( 'width' => 800, 'height' => 800 ) );
$s   = $mk( 's.svg', $svg, 'image/svg+xml', array() );
$url = static fn( int $id ): string => (string) wp_get_attachment_url( $id );

$home_id = 0;
$fin     = static function () use ( &$made, $stand_in, $dir, &$home_id ): void {
	if ( $home_id > 0 ) {
		wp_delete_post( (int) $home_id, true );
	}
	foreach ( $made as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	@rmdir( $dir['basedir'] . '/dxai-picture-test' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	if ( $stand_in ) {
		unregister_block_type( 'dx/picture' );
	}
};

/** The plugin's own image block, as Html_To_Blocks writes it. */
$own = static function ( array $attrs, string $class_in_html = '' ): string {
	$img = '<img';
	foreach ( array( 'url' => 'src', 'alt' => 'alt', 'className' => 'class', 'loading' => 'loading', 'decoding' => 'decoding', 'width' => 'width', 'height' => 'height', 'srcset' => 'srcset', 'sizes' => 'sizes' ) as $key => $html ) {
		if ( isset( $attrs[ $key ] ) && (string) $attrs[ $key ] !== '' ) {
			$img .= ' ' . $html . '="' . ( $html === 'src' ? esc_url( (string) $attrs[ $key ] ) : esc_attr( (string) $attrs[ $key ] ) ) . '"';
		}
	}

	return '<!-- wp:dxai-ui/image ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' -->' . $img . '/><!-- /wp:dxai-ui/image -->';
};
$wrap     = static fn( string $inner, string $class = '' ): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $inner . '</div><!-- /wp:group -->';
// A design's Home: the converters read its stylesheet (none here: nothing addresses a picture by position).
$home_id  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Picture Home', 'post_content' => '' ) );
$convert  = static function ( string $content ) use ( $home_id ): array {
	return Native_Blocks::convert_content( $content, array( 'home' => (int) $home_id, 'post' => 0 ) );
};
$names    = static function ( string $content ): array {
	$out  = array();
	$walk = static function ( array $bs ) use ( &$walk, &$out ): void {
		foreach ( $bs as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$out[] = (string) $b['blockName'];
			}
			$walk( (array) $b['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $content ) );

	return $out;
};
$first_attrs = static function ( string $content, string $name ): array {
	$find = static function ( array $bs ) use ( &$find, $name ): ?array {
		foreach ( $bs as $b ) {
			if ( ( $b['blockName'] ?? '' ) === $name ) {
				return (array) $b['attrs'];
			}
			$f = $find( (array) $b['innerBlocks'] );
			if ( $f !== null ) {
				return $f;
			}
		}

		return null;
	};

	return $find( parse_blocks( $content ) ) ?? array();
};

echo "The converter\n";
$conv = null;
foreach ( Native_Blocks::converters() as $c ) {
	if ( $c->id() === 'picture' ) {
		$conv = $c;
	}
}
$ids = array_map( static fn( $c ) => $c->id(), Native_Blocks::converters() );
$expect( 'on a site that has the block, pictures are one of the converters, after the core Image', $conv instanceof Picture && array_search( 'picture', $ids, true ) > array_search( 'image', $ids, true ), implode( ',', $ids ) );
add_filter( 'dxai_ui_use_dx_picture', '__return_false' );
$expect( 'and one that can be turned off', ! in_array( 'picture', array_map( static fn( $c ) => $c->id(), Native_Blocks::converters() ), true ) );
remove_all_filters( 'dxai_ui_use_dx_picture' );

echo "\nThe plugin's own image\n";
$attrs   = array( 'url' => $url( $a ), 'alt' => 'The team at work', 'className' => 'w-full rounded-xl object-cover', 'width' => '1200', 'height' => '630', 'loading' => 'lazy', 'decoding' => 'async' );
$content = $wrap( $own( $attrs ) );
$out     = $convert( $content );
$p       = $first_attrs( $out['content'], 'dx/picture' );
$expect( 'becomes DX Picture, self-closing, as the team writes it', str_contains( $out['content'], '<!-- wp:dx/picture {' ) && str_contains( $out['content'], '} /-->' ) && ! str_contains( $out['content'], 'wp:dxai-ui/image' ) && ! str_contains( $out['content'], 'wp:image' ), $out['content'] );
$expect( 'with the attachment, its address, the alt and the size the design gave', ( $p['imageId'] ?? 0 ) === $a && ( $p['imageUrl'] ?? '' ) === $url( $a ) && ( $p['imageAlt'] ?? '' ) === 'The team at work' && ( $p['imageWidth'] ?? 0 ) === 1200 && ( $p['imageHeight'] ?? 0 ) === 630, json_encode( $p ) );
$expect( 'the design\'s classes on it, marked so the rules written for the image reach the image inside', ( $p['className'] ?? '' ) === 'dxai-part-img w-full rounded-xl object-cover', (string) ( $p['className'] ?? '' ) );
$expect( 'both screens get the uncropped size: the block\'s default for a phone is a 4:5 crop that would change the picture\'s shape', ( $p['mobileSize'] ?? '' ) === 'dx_pic_desktop_hero' && ! isset( $p['desktopSize'] ) );
$expect( 'lazy loading is what WordPress writes anyway: no priority', ! isset( $p['priority'] ) );
$expect( 'what the page holds is as the serializer writes it', trim( serialize_blocks( parse_blocks( $out['content'] ) ) ) === trim( $out['content'] ) );
$expect( 'the summary counts it', ( $out['counts']['picture'] ?? 0 ) === 1, json_encode( $out['counts'] ) );

$eager = $convert( $wrap( $own( array( 'url' => $url( $a ), 'alt' => '', 'className' => 'hero-img', 'loading' => 'eager' ) ) ) );
$pe    = $first_attrs( $eager['content'], 'dx/picture' );
$expect( 'a picture the design pinned as eager (its LCP image) is the block\'s priority one', ( $pe['priority'] ?? false ) === true && ! isset( $pe['imageAlt'] ) && isset( $pe['imageWidth'] ), json_encode( $pe ) );

$sized = $convert( $wrap( $own( array( 'url' => $url( $b ), 'alt' => 'x', 'srcset' => $url( $b ) . ' 800w', 'sizes' => '100vw' ) ) ) );
$ps    = $first_attrs( $sized['content'], 'dx/picture' );
$expect( 'a srcset and sizes are WordPress\'s to write: they go, the size comes from the file', ( $ps['imageId'] ?? 0 ) === $b && ( $ps['imageWidth'] ?? 0 ) === 800 && ( $ps['imageHeight'] ?? 0 ) === 800 );

echo "\nThe core Image the plugin made\n";
$core    = '<!-- wp:image {"id":' . $a . ',"sizeSlug":"full","linkDestination":"none","className":"dxai-part-img card-img"} --><figure class="wp-block-image size-full dxai-part-img card-img"><img src="' . esc_url( $url( $a ) ) . '" alt="A card" class="wp-image-' . $a . '"/></figure><!-- /wp:image -->';
$cc      = $convert( $wrap( $core ) );
$pc      = $first_attrs( $cc['content'], 'dx/picture' );
$expect( 'is DX Picture too (a page imported before, converted again)', ( $pc['imageId'] ?? 0 ) === $a && ( $pc['imageAlt'] ?? '' ) === 'A card' && ( $pc['className'] ?? '' ) === 'dxai-part-img card-img' && ! str_contains( $cc['content'], 'wp:image' ), json_encode( $pc ) );
$person  = str_replace( 'dxai-part-img ', '', str_replace( '"className":"dxai-part-img card-img"', '"className":"card-img"', $core ) );
$expect( 'an image a person put in (not the plugin\'s: no marker) is not touched', ! str_contains( $convert( $wrap( $person ) )['content'], 'dx/picture' ) );
$caption = str_replace( '</figure>', '<figcaption class="wp-element-caption">Words</figcaption></figure>', $core );
$expect( 'a core Image with a caption is left as it is', ! str_contains( $convert( $wrap( $caption ) )['content'], 'dx/picture' ) );
$linked  = str_replace( '"linkDestination":"none"', '"linkDestination":"custom","href":"https://example.com/"', $core );
$expect( 'and one with a link', ! str_contains( $convert( $wrap( $linked ) )['content'], 'dx/picture' ) );
$shaped  = str_replace( '"sizeSlug":"full"', '"sizeSlug":"full","aspectRatio":"16/9","scale":"cover"', $core );
$expect( 'and one with a shape of its own (an aspect ratio the block has no attribute for)', ! str_contains( $convert( $wrap( $shaped ) )['content'], 'dx/picture' ) );

echo "\nWhat stays an image\n";
$ext = $convert( $wrap( $own( array( 'url' => 'https://elsewhere.test/pic.jpg', 'alt' => 'Far away', 'className' => 'x' ) ) ) );
$expect( 'a picture that is not in the media library is core\'s Image: the block prints nothing without an attachment', ! str_contains( $ext['content'], 'dx/picture' ) && str_contains( $ext['content'], 'wp:image' ) );
$sv = $convert( $wrap( $own( array( 'url' => $url( $s ), 'alt' => 'Icon', 'className' => 'icon' ) ) ) );
$expect( 'an SVG is not served in sizes: it stays an image', ! str_contains( $sv['content'], 'dx/picture' ) );
$css = $convert( $wrap( $own( array( 'url' => $url( $a ), 'alt' => 'x', 'className' => 'a', 'dxaiCss' => 'border-radius:12px' ) ) ) );
$expect( 'a picture with CSS of its own (a dxs- rule the server-rendered block cannot carry) stays an image', ! str_contains( $css['content'], 'dx/picture' ) );
$sp = $convert( $wrap( $own( array( 'url' => $url( $a ), 'alt' => 'x' ) ), 'space-y-4 stack' ) );
$expect( 'a picture in a parent that spaces its children by position stays an image', ! str_contains( $sp['content'], 'dx/picture' ) );
$len = $convert( $wrap( $own( array( 'url' => $url( $a ), 'alt' => 'x', 'width' => '100%', 'height' => 'auto' ) ) ) );
$expect( 'a size given as a length ("100%") is the design\'s own: the block\'s are numbers', ! str_contains( $len['content'], 'dx/picture' ) );

echo "\nOn a site without the block\n";
$type = $reg->get_registered( 'dx/picture' );
unregister_block_type( 'dx/picture' );
// A picture with only what core's Image can keep (an explicit size stays the plugin's own block: core writes it as a CSS length).
$plain = $wrap( $own( array( 'url' => $url( $a ), 'alt' => 'The team at work', 'className' => 'w-full rounded-xl' ) ) );
$no    = $convert( $plain );
$expect( 'the same image is core\'s Image, with its attachment', str_contains( $no['content'], 'wp:image' ) && ! str_contains( $no['content'], 'dx/picture' ) && str_contains( $no['content'], '"id":' . $a ) && ! in_array( 'picture', array_map( static fn( $c ) => $c->id(), Native_Blocks::converters() ), true ), $no['content'] );
$reg->register( $type );

echo "\nThe sizes\n";
add_filter( 'dxai_ui_picture_sizes', static fn( $sizes ) => array( 'desktop' => 'large', 'mobile' => 'dx_pic_mobile_4x5' ) );
$sz = $first_attrs( $convert( $content )['content'], 'dx/picture' );
$expect( 'are the site\'s to choose: another desktop size is written, the block\'s own default for a phone is not', ( $sz['desktopSize'] ?? '' ) === 'large' && ! isset( $sz['mobileSize'] ), json_encode( $sz ) );
remove_all_filters( 'dxai_ui_picture_sizes' );

echo "\nThe team's tools\n";
$block = parse_blocks( '<!-- wp:group {"tagName":"section","className":"hero"} --><section class="wp-block-group hero"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Title</h1><!-- /wp:heading --><!-- wp:dx/picture {"imageId":' . $a . ',"imageUrl":"' . $url( $a ) . '","imageAlt":"Old","imageWidth":1200,"imageHeight":630,"mobileImageId":' . $b . '} /--></section><!-- /wp:group -->' )[0];
$comp  = Section_Library::analyze( $block, 0 );
$expect( 'a section holding a DX Picture has an image slot', count( (array) $comp['images'] ) === 1 && Block_Tree::image_id( Block_Tree::at( $block, (array) $comp['images'][0]['path'] ) ) === $a );
$fresh = parse_blocks( '<!-- wp:dx/picture {"imageId":1} /-->' )[0];
$expect( 'the attachment of an image block is core\'s id or the block\'s imageId', Block_Tree::image_id( $fresh ) === 1 && Block_Tree::image_id( array( 'attrs' => array( 'id' => 7 ) ) ) === 7 && Block_Tree::image_id( array( 'attrs' => array() ) ) === 0 );
$tgt = &Block_Tree::at( $block, (array) $comp['images'][0]['path'] );
Block_Tree::set_image( $tgt, $url( $b ), 'New', $b );
unset( $tgt );
$after = Block_Tree::at( $block, (array) $comp['images'][0]['path'] );
$expect( 'another picture in it is another attachment, with its address, alt and size; the phone\'s own picture was for the old one', $after['attrs']['imageId'] === $b && $after['attrs']['imageUrl'] === $url( $b ) && $after['attrs']['imageAlt'] === 'New' && $after['attrs']['imageWidth'] === 800 && $after['attrs']['imageHeight'] === 800 && ! isset( $after['attrs']['mobileImageId'] ), json_encode( $after['attrs'] ) );
$none = Section_Library::analyze( parse_blocks( '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:dx/picture {"imageId":' . $a . ',"imageUrl":"' . $url( $a ) . '"} /--></section><!-- /wp:group -->' )[0], 0 );
$pool = Section_Variants::pool( array( $none ) );
$expect( 'the Home\'s pictures a page may show instead are found in DX Pictures too (a photograph, not a mark)', count( $pool ) === 1 && $pool[0]['id'] === $a && $pool[0]['url'] === $url( $a ), json_encode( $pool ) );

echo "\nThe rules for the figure\n";
$m = new ReflectionMethod( \DXAI_UI\Blocks\Style_Rules::class, 'part_base_css' );
$m->setAccessible( true );
$base = (string) $m->invoke( null, array( 'dxai-part-img' => true ) );
$expect( 'the figure and the picture of a marked DX Picture take no box, as a marked Image\'s figure does', str_contains( $base, '.wp-block-dx-picture.dxai-part-img{display:contents !important}' ) || str_contains( $base, '.wp-block-dx-picture.dxai-part-img{display:contents' ) && str_contains( $base, '.dx-picture__picture{display:contents}' ), $base );
$expect( 'and the image in it keeps the block display the theme gives it (what the design\'s own reset gives its images; an inline image has a gap under it that the design\'s did not: Clean Joe\'s footer logo grew 4px), on the design\'s baseline', ! str_contains( $base, '.dx-picture__img{display:inline}' ) && str_contains( $base, 'vertical-align:baseline' ) );
$expect( 'the picture\'s <source> takes no box: in a flex row or a grid the figure and the picture take none, so a source would be an empty item of its own and double the gap (found on H2O Away\'s badges)', str_contains( $base, '.dx-picture__picture source{display:none}' ) );
$expect( 'a page without a marked picture gets none of this', (string) $m->invoke( null, array() ) === '' );

echo "\nExport and import\n";
$expect( 'a package carries the attachments of a DX Picture and renumbers them on the other site, like those of core\'s Image', in_array( 'imageId', \DXAI_UI\Transfer\Page_Export::media_id_attrs()['dx/picture'] ?? array(), true ) && in_array( 'mobileImageId', \DXAI_UI\Transfer\Page_Export::media_id_attrs()['dx/picture'] ?? array(), true ) );

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
