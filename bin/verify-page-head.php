<?php
/**
 * What a design's page says in its head: its description for search engines, and the picture it opens on, asked for first.
 *
 *   bash bin/wp-php.sh bin/verify-page-head.php        (or: wp eval-file bin/verify-page-head.php --user=1)
 *
 * Every page of H2O Away scored 92 in Lighthouse's SEO audit for want of a description (docs/PLAN-PERFECT-PAGES.md, T5.4). A page in a
 * design's scope (its Home, the pages from its menu and its old site) gets one: its excerpt, else its first paragraph of twelve words
 * or more, cut at a word to 155 characters. Not on a page that is no design's, not when an SEO plugin writes it, not when the filter
 * says ''.
 *
 * The picture behind a design's first section (its own CSS's background-image, a cover's, a group's) is the Largest Contentful
 * Paint of a phone on slow 4G, found only once the stylesheet is read (H2O Away: 5.1–9.6 s, T5.1): the page preloads it with
 * fetchpriority high. Not a header's picture, not a later section's, not on a page that is no design's.
 *
 * Below that first screen, pictures and frames load lazily and none has the high priority: those of the sections after the first,
 * of a panel the page's runtime opens (a header's dropdown: H2O's five icons, 231 KiB), and of the footer the DX template draws.
 *
 * The plugin's small stylesheets say where their file is, so WordPress writes them into the page (wp_maybe_inline_styles, smallest
 * first, within its size limit) instead of four requests the first paint waited for (T5.3): the plugin's own only, with what they need.
 *
 * Makes the pages and deletes them, also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Media\Image_Loading;
use DXAI_UI\Media\Inline_Styles;
use DXAI_UI\Media\Lcp_Preload;
use DXAI_UI\Seo\Page_Meta;
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

$long = 'Our crew reaches flooded homes in under an hour, day or night, and we dry, clean and rebuild what the water took — then we deal with your insurance company so you do not have to, from the first call to the last repair.';
$page = static function ( string $title, string $content, string $excerpt = '', int $scope = 0 ) use ( &$made ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	$made[] = (int) $id;
	if ( $scope > 0 ) {
		update_post_meta( (int) $id, Page_Scope::META, (string) $scope );
	}

	return (int) $id;
};
// What the page prints in its head, as WordPress shows it.
$head = static function ( int $id ): string {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $id );
	ob_start();
	Page_Meta::print_description();
	Lcp_Preload::print_preload();

	return (string) ob_get_clean();
};

echo "What a page says it is about\n";
$expect( 'the first paragraph that says something: short lines and a phone number are passed over', $long === Page_Meta::lead( '<!-- wp:paragraph --><p>Call (555) 010-0100</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Family run.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph -->' ) );
$expect( 'words are counted by the spaces between them, so a page in another script counts too', '' !== Page_Meta::lead( '<p>Нашият екип идва до наводнения дом за по-малко от час, денем и нощем, и изсушава, почиства и възстановява.</p>' ) );
$cut = Page_Meta::cut( $long );
$expect( 'cut at a word to 155 characters, with an ellipsis', mb_strlen( $cut ) <= Page_Meta::MAX && str_ends_with( $cut, '…' ) && str_starts_with( $long, rtrim( $cut, '…' ) ), $cut );
$expect( 'a short text is left as it is', 'Water damage restoration in Olympia.' === Page_Meta::cut( 'Water damage restoration in Olympia.' ) );

$home = $page( 'Page meta fixture Home', '<!-- wp:paragraph --><p>Home.</p><!-- /wp:paragraph -->' );
$with = $page( 'Page meta fixture (lead)', '<!-- wp:heading --><h2 class="wp-block-heading">Water Damage</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph -->', '', $home );
$exc  = $page( 'Page meta fixture (excerpt)', '<!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph -->', 'Water damage restoration in Olympia, around the clock.', $home );
$none = $page( 'Page meta fixture (no design)', '<!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph -->' );
if ( ! $home || ! $with || ! $exc || ! $none ) {
	echo "SKIP: could not make the fixture pages\n";
	exit( 77 );
}
$expect( 'the page\'s excerpt, when it has one', 'Water damage restoration in Olympia, around the clock.' === Page_Meta::description( $exc ) );
$expect( 'else its lead paragraph, cut', $cut === Page_Meta::description( $with ) );

echo "\nIn the page's head\n";
$out = $head( $with );
$expect( 'a page of a design (in its scope) prints the description', str_contains( $out, '<meta name="description" content="' . esc_attr( $cut ) . '" />' ), $out );
$expect( 'a page that is no design\'s prints none', '' === $head( $none ) );
add_filter( 'dxai_ui_seo_plugin', '__return_true' );
$expect( 'none when an SEO plugin writes the descriptions', '' === $head( $with ) );
remove_filter( 'dxai_ui_seo_plugin', '__return_true' );
add_filter( 'dxai_ui_meta_description', '__return_empty_string' );
$expect( 'none when the filter says \'\'', '' === $head( $with ) );
remove_filter( 'dxai_ui_meta_description', '__return_empty_string' );
$expect( 'the plugin prints it in the head (wp_head)', false !== has_action( 'wp_head', array( Page_Meta::class, 'print_description' ) ) );

echo "\nThe picture a page opens on\n";
$pic   = 'https://example.test/uploads/hero-photo.jpg';
$hero  = '<!-- wp:group {"tagName":"section","dxaiCss":"background-image:url(\'' . $pic . '\');background-size:cover"} --><section class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Water Damage</h1><!-- /wp:heading --><!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph --></section><!-- /wp:group -->';
$later = '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph --></section><!-- /wp:group --><!-- wp:group {"tagName":"section","dxaiCss":"background-image:url(https://example.test/uploads/later.jpg)"} --><section class="wp-block-group"><!-- wp:paragraph --><p>Later.</p><!-- /wp:paragraph --></section><!-- /wp:group -->';
$header = '<!-- wp:group {"tagName":"header","dxaiCss":"background-image:url(https://example.test/uploads/header-band.jpg)"} --><header class="wp-block-group"><!-- wp:paragraph --><p>Logo</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$expect( 'the picture behind the first section (its own CSS\'s background-image)', $pic === Lcp_Preload::hero_picture( $hero ) );
$expect( 'a header\'s picture is not the one the page opens on', $pic === Lcp_Preload::hero_picture( $header . $hero ) );
$expect( 'a later section\'s picture is not either: a page that opens on text asks for none', '' === Lcp_Preload::hero_picture( $later ) );
$expect( 'a cover\'s picture counts', 'https://example.test/uploads/cover.jpg' === Lcp_Preload::hero_picture( '<!-- wp:cover {"url":"https://example.test/uploads/cover.jpg","dimRatio":50} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' ) );
$heroed = $page( 'Page head fixture (hero)', $hero, '', $home );
$plain  = $page( 'Page head fixture (no design, hero)', $hero );
$out    = $heroed ? $head( $heroed ) : '';
$expect( 'a design\'s page preloads it, fetchpriority high', str_contains( $out, '<link rel="preload" as="image" href="' . esc_url( $pic ) . '" fetchpriority="high" />' ), $out );
$expect( 'a page that is no design\'s preloads nothing', $plain && ! str_contains( $head( $plain ), 'rel="preload"' ) );
add_filter( 'dxai_ui_lcp_preload', '__return_empty_string' );
$expect( 'none when the filter says \'\'', ! str_contains( $head( (int) $heroed ), 'rel="preload"' ) );
remove_filter( 'dxai_ui_lcp_preload', '__return_empty_string' );
$expect( 'the plugin prints it in the head, before the rest (wp_head, priority 1)', 1 === has_action( 'wp_head', array( Lcp_Preload::class, 'print_preload' ) ) );

echo "\nPictures below the first screen\n";
$img  = static fn( string $n, string $extra = '' ): string => '<!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.test/uploads/' . $n . '.jpg" alt="' . $n . '"' . $extra . '/></figure><!-- /wp:image -->';
$sect = static fn( string $inner, string $tag = 'section' ): string => '<!-- wp:group {"tagName":"' . $tag . '"} --><' . $tag . ' class="wp-block-group">' . $inner . '</' . $tag . '><!-- /wp:group -->';
$map  = '<!-- wp:html --><iframe src="https://www.google.com/maps/embed?pb=x" title="Map"></iframe><!-- /wp:html -->';
$panel = static fn( string $cls, string $inner ): string => '<!-- wp:group {"className":"' . $cls . '"} --><div class="wp-block-group ' . $cls . '">' . $inner . '</div><!-- /wp:group -->';
$spacer = '<!-- wp:group {"className":"h-104px"} --><div class="wp-block-group h-104px"></div><!-- /wp:group -->';
$body  = $spacer . $sect( $img( 'logo' ) . $panel( 'dxai-on-menu--services hidden', $img( 'icon' ) ), 'header' ) . $sect( $panel( 'hidden', $img( 'wide' ) ) . $img( 'hero' ) ) . $sect( $img( 'team', ' fetchpriority="high"' ) . $map ) . $sect( $img( 'van', ' loading="eager"' ) );
$lazy = $page( 'Page head fixture (pictures)', $body, '', $home );
$html = '';
if ( $lazy ) {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $lazy ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $lazy );
	setup_postdata( $GLOBALS['post'] );
	$html = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $lazy ) );
}
$tag_of = static function ( string $html, string $needle ): string {
	return preg_match( '#<(?:img|iframe)\b[^>]*' . preg_quote( $needle, '#' ) . '[^>]*>#i', $html, $m ) ? $m[0] : '';
};
$expect( 'the header\'s picture and the first section\'s are left to WordPress (not made lazy here), after a fixed header\'s spacer too', ! str_contains( $tag_of( $html, 'logo.jpg' ), 'loading="lazy"' ) && ! str_contains( $tag_of( $html, 'hero.jpg' ), 'loading="lazy"' ), $tag_of( $html, 'logo.jpg' ) . ' | ' . $tag_of( $html, 'hero.jpg' ) );
$expect( 'a picture below the first screen loads lazily and loses the high priority', str_contains( $tag_of( $html, 'team.jpg' ), 'loading="lazy"' ) && ! str_contains( $tag_of( $html, 'team.jpg' ), 'fetchpriority' ), $tag_of( $html, 'team.jpg' ) );
$expect( 'a map\'s frame below the first screen loads lazily', str_contains( $tag_of( $html, 'google.com/maps' ), 'loading="lazy"' ), $tag_of( $html, 'google.com/maps' ) );
$expect( 'a picture the design marked eager stays eager', str_contains( $tag_of( $html, 'van.jpg' ), 'loading="eager"' ), $tag_of( $html, 'van.jpg' ) );
$expect( 'the mark used while rendering is not left in the page', ! str_contains( $html, '__dxai_below' ) );
$expect( 'a picture in a panel the page\'s runtime opens (hidden, dxai-on-) loads lazily, in the header too', str_contains( $tag_of( $html, 'icon.jpg' ), 'loading="lazy"' ), $tag_of( $html, 'icon.jpg' ) );
$expect( '`hidden` alone is no such panel (a wider screen may show it): left to WordPress', '' !== $tag_of( $html, 'wide.jpg' ) && ! str_contains( $tag_of( $html, 'wide.jpg' ), 'loading="lazy"' ), $tag_of( $html, 'wide.jpg' ) );
// A page that opens on the site's header block and a synced pattern: blocks that render blocks themselves, with no parent.
$pattern = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Page head fixture (pattern)', 'post_content' => $sect( $img( 'pattern-hero' ) ) ), true );
$pattern = is_wp_error( $pattern ) ? 0 : (int) $pattern;
if ( $pattern ) {
	$made[] = $pattern;
}
// A block of this run only that renders blocks itself, as the site's header and footer blocks do: WordPress gives those no parent.
register_block_type( 'dxai-test/renders-blocks', array( 'render_callback' => static fn(): string => do_blocks( $sect( $img( 'own-blocks' ) ) ) ) );
$nested = $pattern ? $page( 'Page head fixture (site header, pattern)', '<!-- wp:dxai-ui/site-header /-->' . '<!-- wp:block {"ref":' . $pattern . '} /-->' . $sect( $img( 'after' ) ), '', $home ) : 0;
$owner  = $page( 'Page head fixture (block that renders blocks)', $sect( '<!-- wp:dxai-test/renders-blocks /-->' ) . $sect( $img( 'second' ) ), '', $home );
$nhtml  = '';
if ( $nested ) {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $nested ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $nested );
	setup_postdata( $GLOBALS['post'] );
	$nhtml = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $nested ) );
}
$expect( 'a synced pattern the page opens on (after the site\'s header) keeps its picture: its blocks are the pattern\'s, not the page\'s', '' !== $tag_of( $nhtml, 'pattern-hero.jpg' ) && ! str_contains( $tag_of( $nhtml, 'pattern-hero.jpg' ), 'loading="lazy"' ), $tag_of( $nhtml, 'pattern-hero.jpg' ) ?: substr( $nhtml, 0, 300 ) );
$expect( '…and the section after it loads lazily', str_contains( $tag_of( $nhtml, 'after.jpg' ), 'loading="lazy"' ), $tag_of( $nhtml, 'after.jpg' ) );
$ohtml = '';
if ( $owner ) {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $owner ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $owner );
	setup_postdata( $GLOBALS['post'] );
	$ohtml = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $owner ) );
}
unregister_block_type( 'dxai-test/renders-blocks' );
$expect( 'a block in the first section that renders blocks itself keeps their picture (they have no parent, and are still not the page\'s)', '' !== $tag_of( $ohtml, 'own-blocks.jpg' ) && ! str_contains( $tag_of( $ohtml, 'own-blocks.jpg' ), 'loading="lazy"' ), $tag_of( $ohtml, 'own-blocks.jpg' ) ?: substr( $ohtml, 0, 300 ) );
$expect( '…and the page\'s next section loads lazily', str_contains( $tag_of( $ohtml, 'second.jpg' ), 'loading="lazy"' ), $tag_of( $ohtml, 'second.jpg' ) );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $lazy ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
// The footer the DX template draws after the page, from a stored footer given for this run only (no option is written).
$foot = static fn(): array => array(
	'template' => '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group"><!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/uploads/footer-logo.jpg" alt="Footer logo"/></figure><!-- /wp:image --></footer><!-- /wp:group -->',
	'slots'    => array(),
	'scope'    => 0,
);
add_filter( 'pre_option_' . \DXAI_UI\Chrome\Footer_Template::OPTION, $foot );
$footer = \DXAI_UI\Chrome\Template_Chrome::site_footer();
add_filter( 'dxai_ui_lazy_below', '__return_false' );
$footer_off = \DXAI_UI\Chrome\Template_Chrome::site_footer();
remove_filter( 'dxai_ui_lazy_below', '__return_false' );
remove_filter( 'pre_option_' . \DXAI_UI\Chrome\Footer_Template::OPTION, $foot );
$expect( 'the footer the template draws after the page has its pictures lazy', str_contains( $tag_of( $footer, 'footer-logo.jpg' ), 'loading="lazy"' ), $tag_of( $footer, 'footer-logo.jpg' ) ?: substr( $footer, 0, 200 ) );
$expect( '…and not when the filter turns it off', '' !== $tag_of( $footer_off, 'footer-logo.jpg' ) && ! str_contains( $tag_of( $footer_off, 'footer-logo.jpg' ), 'loading="lazy"' ), $tag_of( $footer_off, 'footer-logo.jpg' ) );
add_filter( 'dxai_ui_lazy_below', '__return_false' );
$off = $lazy ? (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $lazy ) ) : '';
remove_filter( 'dxai_ui_lazy_below', '__return_false' );
$expect( 'none of it when the filter turns it off', str_contains( $tag_of( $off, 'team.jpg' ), 'fetchpriority="high"' ) || ! str_contains( $tag_of( $off, 'team.jpg' ), 'loading="lazy"' ), $tag_of( $off, 'team.jpg' ) );
$two = Image_Loading::lazy( '<img src="a.jpg" loading="eager"><img src="b.jpg" fetchpriority="high">' );
$expect( 'Image_Loading::lazy() keeps a picture\'s own loading and drops the high priority', 1 === preg_match( '#<img[^>]*src="a\.jpg"[^>]*>#', $two, $a ) && str_contains( $a[0], 'loading="eager"' ) && ! str_contains( $a[0], 'lazy' ) && 1 === preg_match( '#<img[^>]*src="b\.jpg"[^>]*>#', $two, $b ) && str_contains( $b[0], 'loading="lazy"' ) && ! str_contains( $b[0], 'fetchpriority' ), $two );

echo "\nThe plugin's small stylesheets in the page\n";
wp_register_style( 'dxai-ui-test-dep', DXAI_UI_URL . 'assets/css/blank-canvas.css', array(), '1' );
wp_register_style( 'dxai-ui-test-sheet', DXAI_UI_URL . 'assets/css/dynamic.css?ver=1', array( 'dxai-ui-test-dep' ), null );
wp_register_style( 'dxai-ui-test-print', DXAI_UI_URL . 'assets/css/dynamic.css', array(), '1', 'print' );
wp_register_style( 'dxai-ui-test-php', DXAI_UI_URL . 'dxai-ui.php', array(), '1' );
// A stylesheet that exists, reached from the plugin's address by climbing out of it: WordPress's own dashicons.css.
wp_register_style( 'dxai-ui-test-up', DXAI_UI_URL . 'assets/css/../../../../../wp-includes/css/dashicons.css', array(), '1' );
wp_register_style( 'test-theme-sheet', get_stylesheet_directory_uri() . '/style.css', array(), '1' );
foreach ( array( 'dxai-ui-test-sheet', 'dxai-ui-test-print', 'dxai-ui-test-php', 'dxai-ui-test-up', 'test-theme-sheet' ) as $h ) {
	wp_enqueue_style( $h );
}
$styles = wp_styles();
$path   = static fn( string $h ) => (string) $styles->get_data( $h, 'path' );
add_filter( 'dxai_ui_inline_styles', '__return_false' );
Inline_Styles::mark();
remove_filter( 'dxai_ui_inline_styles', '__return_false' );
$expect( 'nothing when the filter says no', '' === $path( 'dxai-ui-test-sheet' ) );
Inline_Styles::mark();
$expect( 'a stylesheet of the plugin says where its file is (its query left off)', realpath( DXAI_UI_DIR . 'assets/css/dynamic.css' ) === $path( 'dxai-ui-test-sheet' ), $path( 'dxai-ui-test-sheet' ) );
$expect( '…and so does the one it needs, which is queued so WordPress looks at it', realpath( DXAI_UI_DIR . 'assets/css/blank-canvas.css' ) === $path( 'dxai-ui-test-dep' ) && in_array( 'dxai-ui-test-dep', $styles->queue, true ) );
$expect( 'not the theme\'s stylesheet', '' === $path( 'test-theme-sheet' ) );
$expect( 'not a stylesheet for print', '' === $path( 'dxai-ui-test-print' ) );
$expect( 'not a file that is no stylesheet, nor one outside the plugin\'s folders', '' === $path( 'dxai-ui-test-php' ) && '' === $path( 'dxai-ui-test-up' ) );
wp_maybe_inline_styles();
$after = (array) ( $styles->registered['dxai-ui-test-sheet']->extra['after'] ?? array() );
$expect( 'WordPress writes it into the page: no file to request, its rules inline', false === $styles->registered['dxai-ui-test-sheet']->src && str_contains( implode( '', $after ), (string) substr( trim( (string) file_get_contents( DXAI_UI_DIR . 'assets/css/dynamic.css' ) ), 0, 40 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expect( 'the theme\'s stays a file', is_string( $styles->registered['test-theme-sheet']->src ) && '' !== $styles->registered['test-theme-sheet']->src );
$head = $GLOBALS['wp_filter']['wp_head']->callbacks[1] ?? array();
$keys = array_keys( $head );
$at   = static fn( string $fn ) => array_search( $fn, $keys, true );
$expect( 'it is marked at the end of wp_enqueue_scripts, which WordPress runs in wp_head before it decides (both at priority 1)', PHP_INT_MAX === has_action( 'wp_enqueue_scripts', array( Inline_Styles::class, 'mark' ) ) && false !== $at( 'wp_enqueue_scripts' ) && false !== $at( 'wp_maybe_inline_styles' ) && $at( 'wp_enqueue_scripts' ) < $at( 'wp_maybe_inline_styles' ), wp_json_encode( $keys ) );
foreach ( array( 'dxai-ui-test-sheet', 'dxai-ui-test-dep', 'dxai-ui-test-print', 'dxai-ui-test-php', 'dxai-ui-test-up', 'test-theme-sheet' ) as $h ) {
	wp_dequeue_style( $h );
	wp_deregister_style( $h );
}

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
