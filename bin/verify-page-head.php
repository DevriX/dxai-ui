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
// With an SEO plugin on: the head as it is written, what the SEO plugin printed in it, then ours only where it gave none.
$seo_head = static function ( int $id, string $theirs ): string {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $id );
	ob_start();
	Page_Meta::listen();
	echo $theirs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixture.
	Page_Meta::print_description();
	echo '<link rel="canonical" href="https://example.test/water/" />';
	Page_Meta::after_seo();

	return (string) ob_get_clean();
};
add_filter( 'dxai_ui_seo_plugin', '__return_true' );
$out = $seo_head( $with, '<!-- Search Engine Optimization by Rank Math --><meta name="description" content="Theirs."/>' );
$expect( 'an SEO plugin that writes the description: its own, and not a second', 1 === substr_count( $out, 'name="description"' ) && str_contains( $out, 'content="Theirs."' ), $out );
$out = $seo_head( $with, "<meta name='description' content='Theirs, quoted another way.' />" );
$expect( '…however it quotes it', 1 === preg_match_all( '/name=["\']description/', $out ), $out );
$out = $seo_head( $with, '<meta property="og:title" content="Water" />' );
$expect( 'an SEO plugin that writes none (Rank Math on the team\'s network): the page\'s, once, after the rest of the head', 1 === substr_count( $out, 'name="description"' ) && str_contains( $out, 'content="' . esc_attr( $cut ) . '"' ) && strpos( $out, 'og:title' ) < strpos( $out, 'name="description"' ) && str_contains( $out, 'rel="canonical"' ), $out );
$expect( '…and on a page that is no design\'s, the head is left as it was', '<meta property="og:title" content="Water" /><link rel="canonical" href="https://example.test/water/" />' === $seo_head( $none, '<meta property="og:title" content="Water" />' ) );
remove_filter( 'dxai_ui_seo_plugin', '__return_true' );
$expect( 'no SEO plugin: the head is not read, the description is printed in its place', '' === (string) ( static function () use ( $with ): string { $GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $with ) ); ob_start(); Page_Meta::listen(); Page_Meta::after_seo(); return (string) ob_get_clean(); } )() && str_contains( $head( $with ), 'name="description"' ) );
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
$film = '<!-- wp:html --><iframe src="https://player.example.test/film/1" title="Film"></iframe><!-- /wp:html -->';
$panel = static fn( string $cls, string $inner ): string => '<!-- wp:group {"className":"' . $cls . '"} --><div class="wp-block-group ' . $cls . '">' . $inner . '</div><!-- /wp:group -->';
$spacer = '<!-- wp:group {"className":"h-104px"} --><div class="wp-block-group h-104px"></div><!-- /wp:group -->';
$body  = $spacer . $sect( $img( 'logo' ) . $panel( 'dxai-on-menu--services hidden', $img( 'icon' ) ), 'header' ) . $sect( $panel( 'hidden', $img( 'wide' ) ) . $img( 'hero' ) ) . $sect( $img( 'team', ' fetchpriority="high"' ) . $map . $film ) . $sect( $img( 'van', ' loading="eager"' ) );
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
$expect( '…and waits for the screen: its address kept aside (Chrome loads a lazy frame 1250 px early)', 0 === preg_match( '#\ssrc=#', $tag_of( $html, 'google.com/maps' ) ) && str_contains( $tag_of( $html, 'google.com/maps' ), 'data-dxai-src="https://www.google.com/maps/embed?pb=x"' ), $tag_of( $html, 'google.com/maps' ) );
$expect( 'another frame keeps its address (only lazy)', str_contains( $tag_of( $html, 'player.example.test' ), 'src="https://player.example.test/film/1"' ) && str_contains( $tag_of( $html, 'player.example.test' ), 'loading="lazy"' ), $tag_of( $html, 'player.example.test' ) );
ob_start();
Image_Loading::print_loader();
$loader = (string) ob_get_clean();
ob_start();
Image_Loading::print_loader();
$again = (string) ob_get_clean();
$expect( 'the page ends with the lines that load it near the screen, once', str_contains( $loader, 'id="dxai-ui-frames"' ) && str_contains( $loader, "rootMargin:'" . Image_Loading::FRAME_MARGIN . " 0px'" ) && str_contains( $loader, 'data-dxai-src' ) && '' === $again, $loader );
$expect( 'Google maps are told apart from other frames', Image_Loading::is_map( 'https://maps.google.com/maps?q=Olympia&output=embed' ) && Image_Loading::is_map( 'https://www.google.com/maps/embed?pb=x' ) && Image_Loading::is_map( 'https://www.google.co.uk/maps/embed?pb=x' ) && ! Image_Loading::is_map( 'https://www.google.com/search?q=maps' ) && ! Image_Loading::is_map( 'https://www.youtube.com/embed/x' ) && ! Image_Loading::is_map( 'https://evil.example/www.google.com/maps' ) );
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
// Two shapes of the corpus. Semper Dry: its header inside a group with no tag of its own, then the hero. Clean Joe: one group around
// the whole page (a skip link, a sticky group with the header, a <main> of sections, a section after it, the footer).
$render = static function ( int $id ): string {
	if ( $id < 1 ) {
		return '';
	}
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $id );
	setup_postdata( $GLOBALS['post'] );

	return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
};
$group   = static fn( string $inner, string $class = 'x' ): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $inner . '</div><!-- /wp:group -->';
$h1      = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Dry in a day</h1><!-- /wp:heading -->';
$nav     = '<!-- wp:group {"tagName":"nav"} --><nav class="wp-block-group"><!-- wp:paragraph --><p>Services</p><!-- /wp:paragraph --></nav><!-- /wp:group -->';
// …and its skip link, a link block to #main with none of the usual classes.
$skip    = '<!-- wp:dxai-ui/link {"url":"#main","className":"dxai-sh-1 absolute top-0"} --><a class="wp-block-dxai-ui-link dxai-sh-1 absolute top-0" href="#main">Skip to content</a><!-- /wp:dxai-ui/link -->';
$semper  = $page( 'Page head fixture (header in a group)', $group( $sect( $img( 'sd-logo' ), 'header' ) . $group( $nav, 'phone-menu' ), 'sticky top-0' ) . $skip . $spacer . $sect( $h1 . $img( 'sd-hero' ) ) . $sect( $img( 'sd-next' ) ), '', $home );
$sd_html = $render( (int) $semper );
$expect( 'a header inside a group (Semper Dry) is the header: the hero after it is the first content, its picture left to WordPress', '' !== $tag_of( $sd_html, 'sd-hero.jpg' ) && ! str_contains( $tag_of( $sd_html, 'sd-hero.jpg' ), 'loading="lazy"' ) && ! str_contains( $tag_of( $sd_html, 'sd-logo.jpg' ), 'loading="lazy"' ), $tag_of( $sd_html, 'sd-logo.jpg' ) . ' | ' . $tag_of( $sd_html, 'sd-hero.jpg' ) );
$expect( '…and the section after the hero loads lazily', str_contains( $tag_of( $sd_html, 'sd-next.jpg' ), 'loading="lazy"' ), $tag_of( $sd_html, 'sd-next.jpg' ) );
$cj_main = '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group">' . $sect( $h1 . $img( 'cj-hero' ) ) . $sect( $img( 'cj-second' ) ) . '</main><!-- /wp:group -->';
$cj_foot = '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">' . $img( 'cj-foot' ) . '</footer><!-- /wp:group -->';
$cleanjoe = $page( 'Page head fixture (one group around the page)', $group( '<!-- wp:paragraph {"className":"skip-link"} --><p class="skip-link"><a href="#main">Skip to content</a></p><!-- /wp:paragraph -->' . $group( $sect( $img( 'cj-logo' ), 'header' ), 'sticky top-0' ) . $cj_main . $sect( $img( 'cj-after' ) ) . $cj_foot, 'max-w-full' ), '', $home );
$cj_html  = $render( (int) $cleanjoe );
$expect( 'a group around the whole page (Clean Joe) is opened: its header and its first section are left to WordPress', '' !== $tag_of( $cj_html, 'cj-hero.jpg' ) && ! str_contains( $tag_of( $cj_html, 'cj-hero.jpg' ), 'loading="lazy"' ) && ! str_contains( $tag_of( $cj_html, 'cj-logo.jpg' ), 'loading="lazy"' ), $tag_of( $cj_html, 'cj-logo.jpg' ) . ' | ' . $tag_of( $cj_html, 'cj-hero.jpg' ) );
$expect( '…and the sections after it, in its <main> and after it, and its footer load lazily', str_contains( $tag_of( $cj_html, 'cj-second.jpg' ), 'loading="lazy"' ) && str_contains( $tag_of( $cj_html, 'cj-after.jpg' ), 'loading="lazy"' ) && str_contains( $tag_of( $cj_html, 'cj-foot.jpg' ), 'loading="lazy"' ), $tag_of( $cj_html, 'cj-second.jpg' ) . ' | ' . $tag_of( $cj_html, 'cj-after.jpg' ) . ' | ' . $tag_of( $cj_html, 'cj-foot.jpg' ) );
$expect( '…with no mark of it left in the page', ! str_contains( $cj_html . $sd_html, '__dxai_' ) );
$wrapped = $page( 'Page head fixture (a plain group around the header)', $group( $sect( $img( 'pg-logo' ), 'header' ), 'top-wrap' ) . $sect( $h1 . $img( 'pg-hero' ) ) . $sect( $img( 'pg-next' ) ), '', $home );
$pg_html = $render( (int) $wrapped );
$expect( 'a plain group around a <header> (no menu, nothing stuck) is the header too: the hero after it is left to WordPress', '' !== $tag_of( $pg_html, 'pg-hero.jpg' ) && ! str_contains( $tag_of( $pg_html, 'pg-hero.jpg' ), 'loading="lazy"' ) && str_contains( $tag_of( $pg_html, 'pg-next.jpg' ), 'loading="lazy"' ), $tag_of( $pg_html, 'pg-hero.jpg' ) . ' | ' . $tag_of( $pg_html, 'pg-next.jpg' ) );
$navhero = $page( 'Page head fixture (a hero with a menu)', $group( $nav . $h1 . $img( 'nh-hero' ), 'hero-with-menu' ) . $sect( $img( 'nh-next' ) ), '', $home );
$nh_html = $render( (int) $navhero );
$expect( 'a hero group with its own menu and the main heading is content, not a header: the section after it loads lazily', str_contains( $tag_of( $nh_html, 'nh-next.jpg' ), 'loading="lazy"' ) && ! str_contains( $tag_of( $nh_html, 'nh-hero.jpg' ), 'loading="lazy"' ), $tag_of( $nh_html, 'nh-hero.jpg' ) . ' | ' . $tag_of( $nh_html, 'nh-next.jpg' ) );
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
$expect( 'none of it when the filter turns it off', ( str_contains( $tag_of( $off, 'team.jpg' ), 'fetchpriority="high"' ) || ! str_contains( $tag_of( $off, 'team.jpg' ), 'loading="lazy"' ) ) && str_contains( $tag_of( $off, 'google.com/maps' ), 'src="https://www.google.com/maps/embed?pb=x"' ), $tag_of( $off, 'team.jpg' ) . ' | ' . $tag_of( $off, 'google.com/maps' ) );

echo "\nBackgrounds below the first screen\n";
// A block whose own CSS paints a picture: in the hero, in a later section (a card inside it, and the section itself), and one
// painted with a data: picture written into the CSS.
$bg      = static fn( string $name, string $css, string $inner = '' ): string => '<!-- wp:group {"className":"' . $name . '","dxaiCss":"' . $css . '"} --><div class="wp-block-group ' . $name . '">' . $inner . '</div><!-- /wp:group -->';
$bg_hero = '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group">' . $bg( 'bg-hero', 'background-image:url(https://example.test/uploads/hero-bg.jpg);background-size:cover' ) . '</section><!-- /wp:group -->';
$bg_late = '<!-- wp:group {"tagName":"section","className":"bg-section","dxaiCss":"background:#000 url(https://example.test/uploads/section-bg.jpg) center/cover"} --><section class="wp-block-group bg-section">' . $bg( 'bg-card', 'background-image:url(https://example.test/uploads/card-bg.jpg)' ) . $bg( 'bg-dot', 'background-image:url(data:image/svg+xml;utf8,x)' ) . $bg( 'bg-plain', 'color:red' ) . '</section><!-- /wp:group -->';
$bg_page = $page( 'Page head fixture (backgrounds)', $bg_hero . $sect( '<!-- wp:paragraph --><p>Next</p><!-- /wp:paragraph -->' ) . $bg_late, '', $home );
$bg_html = '';
$render  = static function ( int $id ): string {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $id );
	setup_postdata( $GLOBALS['post'] );

	return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
};
$bg_html = $bg_page ? $render( $bg_page ) : '';
$el_of   = static fn( string $html, string $cls ): string => preg_match( '#<(?:div|section)\b[^>]*class="[^"]*\b' . preg_quote( $cls, '#' ) . '\b[^"]*"[^>]*>#', $html, $m ) ? $m[0] : '';
$waits   = static fn( string $html, string $cls ): bool => str_contains( $el_of( $html, $cls ), Image_Loading::BG_CLASS );
$expect( 'a picture behind a block below the first screen waits: the block is marked (a card in the section, and the section itself)', $waits( $bg_html, 'bg-card' ) && $waits( $bg_html, 'bg-section' ), $el_of( $bg_html, 'bg-card' ) . ' | ' . $el_of( $bg_html, 'bg-section' ) );
$expect( 'not the picture behind the block the page opens on (the hero)', '' !== $el_of( $bg_html, 'bg-hero' ) && ! $waits( $bg_html, 'bg-hero' ), $el_of( $bg_html, 'bg-hero' ) );
$expect( 'not a data: picture written into the CSS (no request to wait for), nor a block whose CSS paints none', '' !== $el_of( $bg_html, 'bg-dot' ) && ! $waits( $bg_html, 'bg-dot' ) && ! $waits( $bg_html, 'bg-plain' ), $el_of( $bg_html, 'bg-dot' ) );
ob_start();
Image_Loading::print_loader();
$bg_loader = (string) ob_get_clean();
ob_start();
Image_Loading::print_loader();
$bg_again = (string) ob_get_clean();
$expect( 'the page ends with the lines that paint it 600 px from the screen, once', str_contains( $bg_loader, 'id="dxai-ui-backgrounds"' ) && str_contains( $bg_loader, "rootMargin:'" . Image_Loading::BG_MARGIN . " 0px'" ) && str_contains( $bg_loader, "classList.remove('" . Image_Loading::BG_CLASS . "')" ) && '' === $bg_again, $bg_loader );
// The head: <html> marked while scripts run, and the rule.
$bg_head = static function ( int $id ): array {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	foreach ( array( 'dxai-ui-lazy-bg' ) as $h ) {
		wp_dequeue_style( $h );
		wp_deregister_style( $h );
	}
	wp_dequeue_script( 'dxai-ui-js' );
	wp_deregister_script( 'dxai-ui-js' );
	Image_Loading::enqueue_background_rule();
	$css = wp_style_is( 'dxai-ui-lazy-bg', 'enqueued' ) ? implode( '', (array) wp_styles()->get_data( 'dxai-ui-lazy-bg', 'after' ) ) : '';
	$js  = wp_script_is( 'dxai-ui-js', 'enqueued' ) ? implode( '', (array) wp_scripts()->get_data( 'dxai-ui-js', 'after' ) ) : '';

	return array( $css, $js );
};
list( $bg_css, $bg_js ) = $bg_page ? $bg_head( $bg_page ) : array( '', '' );
$expect( 'a design\'s page marks <html> in its head while scripts run, and hides a waiting background with the precedence of four ids (a block\'s own rule has three)', str_contains( $bg_js, 'document.documentElement.classList.add("dxai-js")' ) && str_starts_with( $bg_css, '.dxai-js :is(.' . Image_Loading::BG_CLASS . ',#dxai-h)' ) && 4 === substr_count( $bg_css, '#dxai-h' ) && 3 === substr_count( \DXAI_UI\Blocks\Style_Rules::rule( 'dxs-abc123', 'background-image:url(x.jpg)' ), '#dxai-h' ) && str_contains( $bg_css, '{background-image:none}' ), $bg_css . ' | ' . $bg_js );
$bg_none = $page( 'Page head fixture (no design)', '<!-- wp:paragraph --><p>url(x)</p><!-- /wp:paragraph -->' );
list( $none_css, $none_js ) = $bg_none ? $bg_head( $bg_none ) : array( 'x', 'x' );
$expect( 'not on a page that is no design\'s', '' === $none_css && '' === $none_js );
add_filter( 'dxai_ui_lazy_below', '__return_false' );
$bg_off                   = $bg_page ? $render( $bg_page ) : '';
list( $off_css, $off_js ) = $bg_page ? $bg_head( $bg_page ) : array( 'x', 'x' );
remove_filter( 'dxai_ui_lazy_below', '__return_false' );
ob_start();
Image_Loading::print_loader();
$off_loader = (string) ob_get_clean();
$expect( 'none of it when the filter turns it off (no mark, no rule, no lines)', '' !== $el_of( $bg_off, 'bg-card' ) && ! $waits( $bg_off, 'bg-card' ) && '' === $off_css && '' === $off_js && ! str_contains( $off_loader, 'dxai-ui-backgrounds' ) );
$expect( '…and no mark of the render left in the page', ! str_contains( $bg_html, '__dxai_' ) );

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
