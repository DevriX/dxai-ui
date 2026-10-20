<?php
/**
 * What makes a converted Elementor site's page heavy, and what the plugin does about it.
 *
 *   bash bin/wp-php.sh bin/verify-page-weight.php        (or: wp eval-file bin/verify-page-weight.php --user=1)
 *
 * Gemini Restoration — an Elementor site rebuilt as a Claude Design package — imported at Lighthouse 61 on a phone:
 *
 *  - Page_Css_Trim. Every page was compiled against the design's whole stylesheet: 4.4 MB on each of its 13 pages, 263 KiB over
 *    the wire, render-blocking. A page keeps only the rules its markup (its content, header and footer, the classes in its
 *    blocks' attributes and its data attributes) can match, keeping what a script adds and whatever it cannot read; @font-face
 *    of a subset the page never shows (Google's cyrillic, greek, vietnamese… faces: 830 of them) go, a face declared twice is
 *    one. Only on a sheet over a megabyte; never when a regex gave up (that reads as "no classes" and would empty the sheet).
 *  - Lcp_Preload. The hero's picture was `background-image` in the page's stylesheet, by class; the page opened on a skip link,
 *    a <main> and Elementor's page <div> before its first section. Both are read now, per screen width when the sheet changes it.
 *  - Image_Loading. The same wrapper kept every section in "the first screen": nothing below it loaded lazily.
 *  - Webp. Its team photograph (the service pages' hero) is a palette PNG, which GD writes no WebP from: 783 KiB.
 *  - Token_Styles. Its amber `accent` pointed at the `accent` preset of the theme, American Restoration's orange.
 *
 * Makes the pages and deletes them, also when a check dies half-way. Exit code 1 when a check does not hold.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Page_Css_Trim;
use DXAI_UI\Media\Lcp_Preload;
use DXAI_UI\Media\Webp;
use DXAI_UI\Structures\Page_Scope;

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

$made  = array();
$clean = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $clean );

echo "A page's stylesheet, what its markup can use\n";
$markup = '<div class="hero-block gem-element-1"><p class="lead" id="intro" data-dxai-on-menu="dxai-dc-5--menu-on">Café – “x”</p><a href="#a" class="md:flex">a</a></div>';
$tokens = Page_Css_Trim::tokens( $markup );
$keeps  = static fn( string $css ): bool => trim( Page_Css_Trim::trim( $css, $tokens ) ) !== '';
$expect( 'a rule whose classes are all in the markup stays', $keeps( '.hero-block .lead{color:red}' ) );
$expect( 'a rule naming a class of another page goes', ! $keeps( '.hero-block .other-page{color:red}' ) );
$expect( 'one selector of the list that can match keeps the rule', $keeps( '.nope, .lead{color:red}' ) );
$expect( 'an id and an attribute name are required too', $keeps( '#intro[data-dxai-on-menu]{x:1}' ) && ! $keeps( '#outro{x:1}' ) && ! $keeps( '[data-missing]{x:1}' ) );
$expect( 'an attribute\'s value is not a class (`[href$=".pdf"]`)', $keeps( 'a[href$=".pdf"]{x:1}' ) );
$expect( 'an escaped colon is part of the class (`.md\:flex`)', $keeps( '.md\:flex{display:flex}' ) && ! $keeps( '.lg\:flex{display:flex}' ) );
$expect( 'a class the runtime adds (is-in, dxai-…, swiper-…) is taken as there', $keeps( '.lead.is-in{opacity:1}' ) && $keeps( '.dxai-toggle-x .lead{x:1}' ) );
$expect( 'a word of a data attribute counts (the runtime\'s projection classes)', $keeps( '.dxai-dc-5--menu-on{x:1}' ) );
$expect( ':where()/:is() need one of their selectors (a rule of another page: `:where(.dxg-page-about) .lead`)', ! $keeps( ':where(.dxg-page-about) .lead{x:1}' ) && $keeps( ':where(.dxg-page-about,.hero-block) .lead{x:1}' ) && $keeps( '.lead:is(p){x:1}' ) );
$expect( ':not() asks nothing of the markup', $keeps( '.lead:not(.other-page){x:1}' ) && $keeps( 'p:where(:not(.dxai-not-p)){x:1}' ) );
$expect( 'pseudo-classes and pseudo-elements say when, not which', $keeps( '.lead:hover::before{x:1}' ) );
$expect( 'a rule of tags only stays (html, body, *)', $keeps( 'body{margin:0}' ) && $keeps( '*,::before{box-sizing:border-box}' ) );
$out = Page_Css_Trim::trim( '@media (max-width:767px){.other-page{x:1}}@media (max-width:767px){.lead{x:2}}@keyframes fadeInUp{from{opacity:0}to{opacity:1}}@supports (display:grid){.gone{x:1}}', $tokens );
$expect( 'an @media left empty goes, one with a rule stays, @keyframes stay whole', ! str_contains( $out, 'other-page' ) && str_contains( $out, '@media (max-width:767px){.lead{x:2}}' ) && str_contains( $out, '@keyframes fadeInUp{from{opacity:0}to{opacity:1}}' ) && ! str_contains( $out, '@supports' ), $out );
$out = Page_Css_Trim::trim( '.lead{content:"a{b}c"}/* .x{} */.gone{y:1}', $tokens );
$expect( 'braces in a string and comments do not cut a rule', '.lead{content:"a{b}c"}' === $out, $out );
$faces = '@font-face{font-family:R;unicode-range:U+0460-052F}@font-face{font-family:R;unicode-range:U+0000-00FF,U+0131}@font-face{font-family:R;unicode-range:U+2010-2027}@font-face{font-family:R;unicode-range:U+1F00-1FFF}@font-face{font-family:I;src:url(i.woff2)}@font-face{font-family:I;src:url(i.woff2)}';
$out   = Page_Css_Trim::trim( $faces, $tokens );
$expect( 'a @font-face of a subset the page never shows goes (cyrillic, greek); ASCII\'s and a character the page shows (–) stay', ! str_contains( $out, 'U+0460' ) && ! str_contains( $out, 'U+1F00' ) && str_contains( $out, 'U+0000-00FF' ) && str_contains( $out, 'U+2010-2027' ), $out );
$expect( 'a face declared twice is written once; a face with no range stays', 1 === substr_count( $out, 'font-family:I' ) );
$expect( 'a character the CSS writes itself (`content:"\1F0A"`) keeps its subset', str_contains( Page_Css_Trim::trim( $faces . '.lead::before{content:"\1F0A"}', $tokens ), 'U+1F00-1FFF' ) );
$block = '<!-- wp:dxai-ui/box {"className":"from-json","tagName":"section","html":"\u003cspan class=\u0022in-attr\u0022\u003e"} /-->';
$bt    = Page_Css_Trim::tokens( $block );
$expect( 'a dynamic block\'s attributes count: its className, and the classes in HTML it holds, JSON-escaped', isset( $bt['class']['from-json'], $bt['class']['in-attr'] ), wp_json_encode( array_keys( $bt['class'] ) ) );
$expect( 'the header and the footer of the design\'s source are read with the page', str_contains( Page_Css_Trim::chrome( '<header class="h">x</header><main class="m">y</main><footer class="f">z</footer>' ), 'class="f"' ) && ! str_contains( Page_Css_Trim::chrome( '<header class="h">x</header><main class="m">y</main><footer class="f">z</footer>' ), 'class="m"' ) );
$rules = Page_Css_Trim::rules( '.a{x:1}@media (max-width:767px){.b{y:2}}' );
$expect( 'Page_Css_Trim::rules() gives each rule with its @media', 2 === count( $rules ) && array() === $rules[0]['at'] && array( '@media (max-width:767px)' ) === $rules[1]['at'] && '.b' === $rules[1]['selector'] );

$big   = str_repeat( '.other-page-rule{color:red}', 40000 ) . '.lead{color:blue}';
$whole = Page_Css_Trim::apply( '.other{x:1}.lead{x:2}', '', $markup, 1 );
$expect( 'a sheet under a megabyte is left whole', '.other{x:1}.lead{x:2}' === $whole );
$expect( 'a sheet over it keeps what the page uses', '.lead{color:blue}' === Page_Css_Trim::apply( $big, '', $markup, 1 ) );
add_filter( 'dxai_ui_page_css_trim', '__return_false' );
$expect( 'the filter turns it off', $big === Page_Css_Trim::apply( $big, '', $markup, 1 ) );
remove_filter( 'dxai_ui_page_css_trim', '__return_false' );
$limit = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '5' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- a regex that gives up, on purpose.
$gave_up = Page_Css_Trim::apply( $big, '', '<div class="' . str_repeat( 'x', 200 ) . ' lead">', 1 );
ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
$expect( 'a regex that gave up (pcre.backtrack_limit) keeps the whole sheet rather than emptying it', $big === $gave_up, (string) strlen( $gave_up ) );

echo "\nThe picture the page opens on, from its stylesheet\n";
$skip    = '<!-- wp:dxai-ui/link {"className":"skip-link screen-reader-text"} --><a class="wp-block-dxai-ui-link skip-link screen-reader-text" href="#content">Skip to content</a><!-- /wp:dxai-ui/link -->';
$section = static fn( string $cls, string $inner = '' ): string => '<!-- wp:group {"className":"' . $cls . '"} --><div class="wp-block-group ' . $cls . '"><!-- wp:paragraph --><p>' . $cls . $inner . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$wrap    = static fn( string $inner, string $cls = 'gem gem-138' ): string => '<!-- wp:group {"className":"' . $cls . '"} --><div class="wp-block-group ' . $cls . '">' . $inner . '</div><!-- /wp:group -->';
$elementor = $skip . '<!-- wp:group {"tagName":"main","className":"site-main"} --><main class="wp-block-group site-main">' . $wrap( $section( 'e-con e-parent gem-element-hero hero-block' ) . $section( 'e-con e-parent gem-element-2' ) . $section( 'e-con e-parent gem-element-3' ) ) . '</main><!-- /wp:group -->';
$sections  = Lcp_Preload::sections( parse_blocks( $elementor ) );
$expect( 'past a skip link, inside a <main> and the page <div> it holds: the first section is the hero', isset( $sections[0] ) && str_contains( (string) ( $sections[0]['attrs']['className'] ?? '' ), 'hero-block' ) && 3 === count( $sections ), wp_json_encode( array_map( static fn( $b ) => $b['attrs']['className'] ?? $b['blockName'], $sections ) ) );
$own  = array( 'class' => array( 'wp-block-group', 'gem-element', 'e-con', 'e-parent', 'gem-element-hero', 'hero-block' ), 'id' => 'hero' );
$rest = array_slice( $sections, 1 );
$pics = static fn( string $css ): array => Lcp_Preload::from_sheet( $css, $own, $rest );
$one  = $pics( '.dxai-ui .gem-138 .gem-element.gem-element-hero:not(.gem-motion-effects-element-type-background){background-color:#000;background-image:url("https://example.test/hero.jpg")}' );
$expect( 'its own class\'s rule in the sheet (through :not()) names it, for every width', array( array( 'url' => 'https://example.test/hero.jpg', 'media' => '' ) ) === $one, wp_json_encode( $one ) );
$sized = $pics( '.gem-element-hero{background-image:url(https://example.test/wide.jpg)}@media (max-width:1024px){.gem-element-hero{background-image:url(https://example.test/tablet.jpg)}}@media (max-width:767px){.gem-element-hero{background-image:url(https://example.test/phone.jpg)}}' );
$expect( 'another picture per screen width, each with the range it shows in', array( array( 'url' => 'https://example.test/phone.jpg', 'media' => '(max-width: 767px)' ), array( 'url' => 'https://example.test/tablet.jpg', 'media' => '(min-width: 767.02px) and (max-width: 1024px)' ), array( 'url' => 'https://example.test/wide.jpg', 'media' => '(min-width: 1024.02px)' ) ) === $sized, wp_json_encode( $sized ) );
$expect( 'a width where it shows none asks for nothing there', array( array( 'url' => 'https://example.test/wide.jpg', 'media' => '(min-width: 767.02px)' ) ) === $pics( '.gem-element-hero{background-image:url(https://example.test/wide.jpg)}@media (max-width:767px){.hero-block{background-image:none}}' ) );
$expect( 'a condition it cannot place (orientation, min-width) means no preload rather than a wrong one', array() === $pics( '.gem-element-hero{background-image:url(https://example.test/wide.jpg)}@media (orientation:portrait){.gem-element-hero{background-image:url(https://example.test/tall.jpg)}}' ) );
$expect( 'not a class every section has (e-con), not a hover, a ::before, nor a rule asking for a class it lacks', array() === $pics( '.e-con{background-image:url(https://example.test/all.jpg)}.gem-element-hero:hover{background-image:url(https://example.test/h.jpg)}.gem-element-hero::before{background-image:url(https://example.test/b.jpg)}.gem-element-hero.e-con-full{background-image:url(https://example.test/f.jpg)}' ) );
$expect( 'its id names it too', array( array( 'url' => 'https://example.test/id.jpg', 'media' => '' ) ) === $pics( '#hero{background:#000 url(https://example.test/id.jpg) center/cover}' ) );

$home  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page weight fixture Home', 'post_content' => '<!-- wp:paragraph --><p>Home.</p><!-- /wp:paragraph -->' ), true );
$hero  = is_wp_error( $home ) ? 0 : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page weight fixture (Elementor)', 'post_content' => $elementor ), true );
if ( ! $home || ! $hero || is_wp_error( $hero ) ) {
	echo "SKIP: could not make the fixture pages\n";
	exit( 77 );
}
$made[] = (int) $home;
$made[] = (int) $hero;
update_post_meta( (int) $hero, Page_Scope::META, (string) $home );
$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'dxai-ui';
wp_mkdir_p( $dir );
$sheet = $dir . '/page-weight-fixture-' . $hero . '.css';
file_put_contents( $sheet, '.gem-138 .gem-element-hero{background-image:url(https://example.test/sheet-hero.jpg)}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
register_shutdown_function( static fn() => is_file( $sheet ) && unlink( $sheet ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
update_post_meta( (int) $hero, '_dxai_ui_css_url', 'dxai-ui/' . basename( $sheet ) );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $hero ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['post']         = get_post( $hero );
ob_start();
Lcp_Preload::print_preload();
$head = (string) ob_get_clean();
$expect( 'a design\'s page preloads the picture its sheet paints behind the hero', str_contains( $head, '<link rel="preload" as="image" href="https://example.test/sheet-hero.jpg" fetchpriority="high" />' ), $head );
$expect( '…and keeps what it read with the sheet it read it from', is_array( get_post_meta( (int) $hero, Lcp_Preload::SHEET_META, true ) ) );

echo "\nPictures below the first screen of an Elementor page\n";
$img     = static fn( string $n ): string => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/uploads/' . $n . '.jpg" alt="' . $n . '"/></figure><!-- /wp:image -->';
$with    = $skip . '<!-- wp:group {"tagName":"main","className":"site-main"} --><main class="wp-block-group site-main">' . $wrap( $wrap( $img( 'el-hero' ), 'e-con hero' ) . $wrap( $img( 'el-second' ), 'e-con two' ) . $wrap( $img( 'el-third' ), 'e-con three' ) ) . '</main><!-- /wp:group -->';
$lazy_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page weight fixture (pictures)', 'post_content' => $with ), true );
$html    = '';
if ( ! is_wp_error( $lazy_id ) ) {
	$made[] = (int) $lazy_id;
	update_post_meta( (int) $lazy_id, Page_Scope::META, (string) $home );
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $lazy_id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $lazy_id );
	setup_postdata( $GLOBALS['post'] );
	// WordPress's own lazy loading off (outside a theme's loop, 7.1 makes every picture lazy): what is lazy here is the plugin's.
	add_filter( 'wp_lazy_loading_enabled', '__return_false', PHP_INT_MAX );
	$html = (string) apply_filters( 'the_content', $with );
	remove_filter( 'wp_lazy_loading_enabled', '__return_false', PHP_INT_MAX );
}
$tag_of = static fn( string $n ): string => preg_match( '#<img\b[^>]*' . preg_quote( $n, '#' ) . '[^>]*>#', $html, $m ) ? $m[0] : '';
$expect( 'a <main> around the page <div> around the sections: the hero\'s picture is left to WordPress', '' !== $tag_of( 'el-hero.jpg' ) && ! str_contains( $tag_of( 'el-hero.jpg' ), 'loading="lazy"' ), $tag_of( 'el-hero.jpg' ) );
$expect( '…and the sections after it load lazily', str_contains( $tag_of( 'el-second.jpg' ), 'loading="lazy"' ) && str_contains( $tag_of( 'el-third.jpg' ), 'loading="lazy"' ), $tag_of( 'el-second.jpg' ) . ' | ' . $tag_of( 'el-third.jpg' ) );

echo "\nA palette PNG as WebP\n";
if ( ! function_exists( 'imagecreate' ) || ! function_exists( 'imagewebp' ) ) {
	echo "  (no GD with WebP here: not checked)\n";
} else {
	$png = get_temp_dir() . 'dxai-page-weight-palette.png';
	$im  = imagecreatetruecolor( 400, 300 );
	for ( $y = 0; $y < 300; $y++ ) {
		for ( $x = 0; $x < 400; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, ( $x * 7 + $y ) % 256, ( $y * 3 ) % 256, ( $x + $y * 5 ) % 256 ) );
		}
	}
	imagetruecolortopalette( $im, true, 256 );
	imagepng( $im, $png, 9 );
	imagedestroy( $im );
	$made_webp = Webp::wanted( $png, 'team-photo.png' ) ? Webp::make( $png, 'team-photo.png' ) : null;
	$is_webp   = is_array( $made_webp ) && is_file( $made_webp['path'] ) && 'RIFF' === (string) file_get_contents( $made_webp['path'], false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$expect( 'a palette PNG is written as WebP through a true-colour copy (GD writes none from a palette)', $is_webp && 'team-photo.webp' === $made_webp['filename'], wp_json_encode( $made_webp ) );
	$expect( '…and the copy is not left in the temporary folder', array() === glob( get_temp_dir() . 'dxai-rgb-*.png' ) );
	if ( is_array( $made_webp ) && is_file( $made_webp['path'] ) ) {
		unlink( $made_webp['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
	unlink( $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
}

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
