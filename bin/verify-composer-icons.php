<?php
/**
 * A page composed from the Home's sections keeps the Home's icons.
 *
 *   bash bin/wp-php.sh bin/verify-composer-icons.php        (or: wp eval-file bin/verify-composer-icons.php --user=1)
 *
 * The live-site crawl composes a page from a Home's sections and the old page's words and pictures (Page_Composer). A section's icon is
 * an image block of the Home's (an SVG file, class `dxai-icon`); it was taken for a picture slot, so a photograph of the old page was
 * put where the icon was, with the icon's own CSS (`max-width:none`, no size): the photograph at its natural size, thousands of pixels
 * wide. The icon stays; a picture slot beside it is filled as before. Reads nothing from the site and writes nothing. Exit code 1 when
 * a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Page_Composer;

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

$composer = new Page_Composer(
	array(),
	static fn( string $src ): array => array( 'url' => 'http://example.test/uploads/' . basename( $src ), 'id' => 0 )
);
$pool = new ReflectionProperty( $composer, 'pool' );
$pool->setAccessible( true );
$pool->setValue( $composer, array( 'p1' => array( 'src' => 'http://old.test/mold.webp', 'alt' => 'A photograph', 'ref' => 'p1' ) ) );
$fill = new ReflectionMethod( $composer, 'image' );
$fill->setAccessible( true );

$icon  = array(
	'blockName'    => 'core/image',
	'attrs'        => array( 'className' => 'dxai-part-img dxai-icon', 'dxaiCss' => 'display:inline;margin-bottom:14px;max-width:none', 'url' => 'http://example.test/icons/a.svg' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<figure class="wp-block-image dxai-part-img dxai-icon"><img src="http://example.test/icons/a.svg" alt=""/></figure>',
	'innerContent' => array( '<figure class="wp-block-image dxai-part-img dxai-icon"><img src="http://example.test/icons/a.svg" alt=""/></figure>' ),
);
$photo = array(
	'blockName'    => 'core/image',
	'attrs'        => array( 'className' => 'w-full', 'url' => 'http://example.test/uploads/home.jpg' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<figure class="wp-block-image w-full"><img src="http://example.test/uploads/home.jpg" alt=""/></figure>',
	'innerContent' => array( '<figure class="wp-block-image w-full"><img src="http://example.test/uploads/home.jpg" alt=""/></figure>' ),
);

echo "A section's icon\n";
$section = array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( $icon, $photo ), 'innerHTML' => '', 'innerContent' => array( null, null ) );
$fill->invokeArgs( $composer, array( &$section, array( 0 ), 'p1' ) );
$expect( 'an old photograph is not put in its place', ( $section['innerBlocks'][0]['attrs']['url'] ?? '' ) === 'http://example.test/icons/a.svg' && empty( $section['innerBlocks'][0]['attrs']['__dxai_image'] ) );
$expect( '…and the icon is not dropped for having no picture to show', empty( $section['innerBlocks'][0]['attrs']['__dxai_drop'] ) );
$fill->invokeArgs( $composer, array( &$section, array( 0 ), '' ) );
$expect( '…not even when the old page has none', empty( $section['innerBlocks'][0]['attrs']['__dxai_drop'] ) );

echo "\nA picture slot beside it\n";
$fill->invokeArgs( $composer, array( &$section, array( 1 ), 'p1' ) );
$expect( 'is filled as before', ( $section['innerBlocks'][1]['attrs']['url'] ?? '' ) === 'http://example.test/uploads/mold.webp' && ! empty( $section['innerBlocks'][1]['attrs']['__dxai_image'] ), wp_json_encode( $section['innerBlocks'][1]['attrs'] ?? null ) );
$fill->invokeArgs( $composer, array( &$section, array( 1 ), '' ) );
$expect( 'and goes when the old page has no picture for it', ! empty( $section['innerBlocks'][1]['attrs']['__dxai_drop'] ) );

echo "A rating's stars drawn before a line (H2O Away's call band, 2026-10-23)\n";
// The Home's line: its stars as drawing (aria-hidden), then the words. The old page's words go in its place, line by line.
$set = new ReflectionMethod( $composer, 'set' );
$set->setAccessible( true );
$line  = static function (): array {
	$html = '<p class="m-0 text-17 fw-600"><span aria-hidden="true" class="dxs-2qibk1">★★★★★</span> 4.9 rating · 800+ Google reviews</p>';
	return array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
};
$put   = static function ( string $words ) use ( $set, $composer, $line ): string {
	$b = $line();
	$set->invokeArgs( $composer, array( &$b, array( 0 ), $words ) );
	return (string) $b['innerBlocks'][0]['innerHTML'];
};
$other = $put( 'Skip the form. Call and a live person will dispatch the nearest crew.' );
$expect( 'before words that are not about the rating, the stars go', ! str_contains( $other, '★' ) && str_contains( $other, '<p class="m-0 text-17 fw-600">Skip the form.' ), $other );
$rated = $put( 'Rated 4.8 by 300 neighbours' );
$expect( 'before a line about the rating, they stay', str_contains( $rated, '<span aria-hidden="true" class="dxs-2qibk1">★★★★★</span> Rated 4.8' ), $rated );
$twice = $put( '★★★★★ Rated 5.0 in 300 Google reviews' );
$expect( 'before words with stars of their own, they go (no ★★★★★ ★★★★★)', substr_count( $twice, '★★★★★' ) === 1, $twice );
$tick = array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p><span aria-hidden="true" class="tick">✓</span> Licensed and insured</p>', 'innerContent' => array( '<p><span aria-hidden="true" class="tick">✓</span> Licensed and insured</p>' ) ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
$set->invokeArgs( $composer, array( &$tick, array( 0 ), 'IICRC certified crews' ) );
$expect( 'an icon that is not stars (a tick) stays before any words', str_contains( (string) $tick['innerBlocks'][0]['innerHTML'], '<span aria-hidden="true" class="tick">✓</span> IICRC certified crews' ), (string) $tick['innerBlocks'][0]['innerHTML'] );

echo "A hero like H2O Away's, filled with an old page's (docs/PLAN-NETWORK-PAGES.md, 11, defect 2)\n";
// Its title, a line under it, a list of claims each with its tick, and a row of the company's figures beside them.
$fig  = static fn( string $n, string $l ): string => '<!-- wp:group {"className":"fig"} --><div class="wp-block-group fig"><!-- wp:paragraph {"className":"num"} --><p class="num">' . $n . '</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"lab"} --><p class="lab">' . $l . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$hero = parse_blocks(
	'<!-- wp:group {"tagName":"section","className":"hero"} --><section class="wp-block-group hero"><!-- wp:group {"className":"copy"} --><div class="wp-block-group copy">'
	. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Water, Fire &amp; Mold Damage Restoration in Olympia, WA</h1><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Third-generation insurance contractors serving Thurston County. On site in about 60 minutes.</p><!-- /wp:paragraph -->'
	. '<!-- wp:dxai-ui/text {"tagName":"div","className":"checks"} --><div class="checks"><span class="row"><span aria-hidden="true" class="tick">✓</span>4.9-star Google rating from 800+ local reviews</span><span class="row"><span aria-hidden="true" class="tick">✓</span>We work with all insurance companies</span></div><!-- /wp:dxai-ui/text -->'
	. '</div><!-- /wp:group --><!-- wp:group {"className":"figures"} --><div class="wp-block-group figures">' . $fig( '4.9 ★', 'Google reviews' ) . $fig( '~60 min', 'On site, day or night' ) . $fig( '800+', 'Homes restored' ) . '</div><!-- /wp:group --></section><!-- /wp:group -->'
)[0];
$blank = new ReflectionMethod( Page_Composer::class, 'blank' );
$blank->setAccessible( true );
$fill_section = new ReflectionMethod( Page_Composer::class, 'fill' );
$fill_section->setAccessible( true );
$unit = array_merge(
	$blank->invoke( null ),
	array(
		'kind'    => 'hero',
		'heading' => 'Emergency Fire &amp; Smoke Damage Restoration',
		'paras'   => array( 'Fire damage can affect far more than the areas touched directly by flames.', 'Answered live, day or night', 'Free inspections', 'We bill your insurer directly' ),
	)
);
$hero_out = $fill_section->invoke( new Page_Composer( array(), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ), DXAI_UI\Pages\Section_Library::analyze( $hero, 0 ), 0, $unit );
$hero_html = is_array( $hero_out ) ? serialize_block( $hero_out ) : '';
$expect( 'the old page\'s title and its first paragraph take the title and the line under it', str_contains( $hero_html, '>Emergency Fire &amp; Smoke Damage Restoration</h1>' ) && str_contains( $hero_html, '<p>Fire damage can affect far more' ), $hero_html );
$expect( 'its short lines are the list\'s claims, one each with its tick — not a copy of the list for every line', substr_count( $hero_html, '<span class="row"><span aria-hidden="true" class="tick">✓</span>' ) === 3 && str_contains( $hero_html, '✓</span>Free inspections</span>' ) && substr_count( $hero_html, 'class="checks"' ) === 1, $hero_html );
$expect( 'the Home\'s row of figures, the company\'s on every page, stays with its words', str_contains( $hero_html, '>~60 min<' ) && str_contains( $hero_html, '>On site, day or night<' ) && str_contains( $hero_html, '>Homes restored<' ) );
$long = $unit;
$long['paras'] = array( 'Fire damage can affect far more than the areas touched directly by flames.', str_repeat( 'Smoke and soot travel through a home and settle on every surface they reach, ', 3 ) );
$long_html = serialize_block( (array) $fill_section->invoke( new Page_Composer( array(), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ), DXAI_UI\Pages\Section_Library::analyze( $hero, 0 ), 0, $long ) );
$expect( 'a long paragraph is not made a claim with a tick', ! str_contains( $long_html, '✓</span>Smoke and soot' ), $long_html );
$two = static fn( string $second ): array => array( 'blockName' => 'dxai-ui/text', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<div class="checks"><span class="row"><i aria-hidden="true">✓</i>One</span>' . $second . '</div>' );
$expect( 'a list of claims is rows of one kind, each with its drawing', Block_Tree::rows( $two( '<span class="row"><i aria-hidden="true">✓</i>Two</span>' ) ) !== null && Block_Tree::rows( $two( '<em class="note"><i aria-hidden="true">✓</i>Two</em>' ) ) === null && Block_Tree::rows( $two( '<span class="row">Two</span>' ) ) === null );

echo "A call button's drawing (its pulse, its pictograph)\n";
$call = static fn(): array => array(
	'blockName'    => 'dxai-ui/text',
	'attrs'        => array( 'tagName' => 'a', 'url' => 'tel:+18555607463' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<a href="tel:+18555607463"><span aria-hidden="" class="pulse"></span>📞 Call Now: <span class="sc-interp">(855) 560-7463</span></a>',
	'innerContent' => array( '<a href="tel:+18555607463"><span aria-hidden="" class="pulse"></span>📞 Call Now: <span class="sc-interp">(855) 560-7463</span></a>' ),
);
$b = $call();
Block_Tree::set_link( $b, 'tel:+18557758614', 'Call Now: (855) 775-8614' );
$expect( 'a call to another number keeps its pulse (aria-hidden written bare) and its 📞', $b['innerHTML'] === '<a href="tel:+18557758614"><span aria-hidden="" class="pulse"></span>📞 Call Now: (855) 775-8614</a>', $b['innerHTML'] );
$b = $call();
Block_Tree::set_link( $b, 'https://example.test/contact/', 'Request a Callback' );
$expect( 'a link that no longer calls drops the 📞', ! str_contains( $b['innerHTML'], '📞' ) && str_contains( $b['innerHTML'], 'Request a Callback</a>' ), $b['innerHTML'] );
$b = $call();
Block_Tree::set_link( $b, 'tel:+18557758614', '☎ Call us' );
$expect( '…and words that bring a pictograph of their own get no second one', ! str_contains( $b['innerHTML'], '📞' ) && str_contains( $b['innerHTML'], '☎ Call us</a>' ), $b['innerHTML'] );

echo "No two sections in a row on one ground (docs/PLAN-NETWORK-PAGES.md, 11.3)\n";
// The Home's grounds: white (none), a light tint, a dark band. A page whose sections stand white, white, light, light, dark, dark.
$on = static function ( string $class, string $words ): array {
	$attrs = $class !== '' ? '{"tagName":"section","className":"' . $class . '"}' : '{"tagName":"section"}';
	$tag   = $class !== '' ? '<section class="wp-block-group ' . $class . '">' : '<section class="wp-block-group">';
	return parse_blocks( '<!-- wp:group ' . $attrs . ' -->' . $tag . '<!-- wp:paragraph --><p>' . $words . '</p><!-- /wp:paragraph --></section><!-- /wp:group -->' )[0];
};
$page_sections = array( $on( '', 'One' ), $on( '', 'Two' ), $on( 'bg-light-tint', 'Three' ), $on( 'bg-light-tint', 'Four' ), $on( 'bg-ink', 'Five' ), $on( 'bg-ink', 'Six' ) );
$alternate     = new ReflectionMethod( Page_Composer::class, 'alternate_grounds' );
$alternate->setAccessible( true );
$grounded = ( new Page_Composer( array(), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )->grounds(
	array(
		'light' => array( 'class' => array( 'bg-light-tint' ) ),
		'dark'  => array( 'class' => array( 'bg-ink' ) ),
	)
);
$out_sections = $alternate->invoke( $grounded, $page_sections );
$ground_names = array_map( static fn( array $s ): string => str_contains( ' ' . (string) ( $s['attrs']['className'] ?? '' ) . ' ', ' bg-light-tint ' ) ? 'light' : ( str_contains( (string) ( $s['attrs']['className'] ?? '' ), 'bg-ink' ) ? 'dark' : 'white' ), $out_sections );
$expect( 'the second of two white sections takes the light ground, the second of two light ones goes white; dark bands are left', $ground_names === array( 'white', 'light', 'white', 'light', 'dark', 'dark' ), implode( ' ', $ground_names ) );
$expect( '…in the section\'s tag as in its settings', str_contains( (string) $out_sections[1]['innerHTML'], '<section class="wp-block-group bg-light-tint">' ) && str_contains( (string) $out_sections[2]['innerHTML'], '<section class="wp-block-group">' ) && ! isset( $out_sections[2]['attrs']['className'] ) );
$expect( 'without the Home\'s grounds nothing changes', $alternate->invoke( new Page_Composer( array(), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ), $page_sections ) === $page_sections );

// …and on a page composed from an old one: two texts in a row, the Home's text sections both white.
$text_section = static fn( string $h ): array => parse_blocks( '<!-- wp:group {"tagName":"section","className":"txt"} --><section class="wp-block-group txt"><!-- wp:heading --><h2 class="wp-block-heading">' . $h . '</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Every job is handled by our own trained crews, from the first call to the last repair, with photos for your insurer.</p><!-- /wp:paragraph --></section><!-- /wp:group -->' )[0];
$library      = array( DXAI_UI\Pages\Section_Library::analyze( $text_section( 'Our crews' ), 0 ), DXAI_UI\Pages\Section_Library::analyze( $text_section( 'Why us' ), 1 ) );
$old_page     = '<html><body><main><section><h2>Fire damage cleanup</h2><p>A fire can cause devastating damage to a home or commercial property, and even rooms the flames never reached carry smoke and soot.</p></section>'
	. '<section><h2>Smoke and soot</h2><p>Smoke and soot travel through a property and settle on surfaces that were never touched by the fire itself, so every room is cleaned.</p></section></main></body></html>';
$composed     = ( new Page_Composer( $library, static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )
	->grounds( array( 'light' => array( 'class' => array( 'bg-light-tint' ) ) ) )
	->compose( ( new DXAI_UI\Pages\Content_Extractor() )->extract( $old_page, 'https://old.example.test/fire/' ) );
$tints        = array_map( static fn( array $s ): bool => str_contains( (string) ( $s['attrs']['className'] ?? '' ), 'bg-light-tint' ), $composed['sections'] );
$expect( 'a page composed of two text sections has the second on the Home\'s light ground', $tints === array( false, true ), wp_json_encode( $tints ) . ' ' . implode( ' | ', $composed['log'] ) );

// A Home that writes its white with a class of its own: white and light trade places by their classes.
$classed   = ( new Page_Composer( array(), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )->grounds(
	array(
		'light' => array( 'class' => array( 'bg-light-tint' ) ),
		'dark'  => array( 'class' => array( 'bg-ink' ) ),
		'white' => array( 'class' => array( 'bg-paper' ) ),
	)
);
$traded    = $alternate->invoke( $classed, array( $on( 'bg-paper', 'One' ), $on( 'bg-paper', 'Two' ), $on( 'bg-light-tint', 'Three' ), $on( 'bg-light-tint', 'Four' ) ) );
$trade_cls = array_map( static fn( array $s ): string => (string) ( $s['attrs']['className'] ?? '' ), $traded );
$expect( '…and a Home that writes its white with a class: the class goes when the light ground comes, and comes back when it goes', $trade_cls === array( 'bg-paper', 'bg-light-tint', 'bg-paper', 'bg-light-tint' ), implode( ' | ', $trade_cls ) );
// Two dark text sections of the Home and a white one; three texts in a row on the old page: no two dark ones in a row.
$text_on    = static fn( string $class, string $h ): array => parse_blocks( '<!-- wp:group {"tagName":"section","className":"txt ' . $class . '"} --><section class="wp-block-group txt ' . $class . '"><!-- wp:heading --><h2 class="wp-block-heading">' . $h . '</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Every job is handled by our own trained crews, from the first call to the last repair, with photos for your insurer.</p><!-- /wp:paragraph --></section><!-- /wp:group -->' )[0];
$dark_lib   = array( DXAI_UI\Pages\Section_Library::analyze( $text_on( 'bg-ink', 'Night crews' ), 0 ), DXAI_UI\Pages\Section_Library::analyze( $text_on( 'bg-ink', 'Our trucks' ), 1 ), DXAI_UI\Pages\Section_Library::analyze( $text_on( 'bg-paper', 'Why us' ), 2 ) );
$three_text = '<html><body><main>' . implode( '', array_map( static fn( string $h ): string => '<section><h2>' . $h . '</h2><p>A fire can cause devastating damage to a home or commercial property, and even rooms the flames never reached carry smoke and soot.</p></section>', array( 'Fire damage cleanup', 'Smoke and soot', 'Odor removal' ) ) ) . '</main></body></html>';
$dark_page  = ( new Page_Composer( $dark_lib, static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )
	->grounds(
		array(
			'light' => array( 'class' => array( 'bg-light-tint' ) ),
			'dark'  => array( 'class' => array( 'bg-ink' ) ),
			'white' => array( 'class' => array( 'bg-paper' ) ),
		)
	)
	->compose( ( new DXAI_UI\Pages\Content_Extractor() )->extract( $three_text, 'https://old.example.test/fire/' ) );
$darks      = array_map( static fn( array $s ): bool => str_contains( (string) ( $s['attrs']['className'] ?? '' ), 'bg-ink' ), $dark_page['sections'] );
$two_dark   = false;
foreach ( $darks as $k => $is_dark ) {
	$two_dark = $two_dark || ( $k > 0 && $is_dark && $darks[ $k - 1 ] );
}
$expect( 'of the Home\'s sections that fit as well, the next is one on another ground: no two dark bands in a row', count( $darks ) === 3 && ! $two_dark, wp_json_encode( $darks ) . ' ' . implode( ' | ', $dark_page['log'] ) );

echo "A part of the old page with the heading of one of the Home's sections (H2O Away's Services page, 2026-10-26)\n";
// The Home: a text section, and a text section beside a photograph under "Built in the South Puget Sound". The old page has that part,
// with no picture of its own: it goes into the Home's own section for it, and the photograph the designer chose for it stays.
$why   = parse_blocks( '<!-- wp:group {"tagName":"section","className":"why"} --><section class="wp-block-group why"><!-- wp:heading --><h2 class="wp-block-heading">Why Olympia calls us</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Our crews answer at night and reach you within the hour, every day of the year.</p><!-- /wp:paragraph --></section><!-- /wp:group -->' )[0];
$built = parse_blocks(
	'<!-- wp:group {"tagName":"section","className":"built"} --><section class="wp-block-group built"><!-- wp:group {"className":"cols"} --><div class="wp-block-group cols">'
	. '<!-- wp:image {"className":"photo"} --><figure class="wp-block-image photo"><img src="https://example.test/crew-truck.jpg" alt="Our crew beside the truck"/></figure><!-- /wp:image -->'
	. '<!-- wp:group {"className":"copy"} --><div class="wp-block-group copy"><!-- wp:heading --><h2 class="wp-block-heading">Built in the South Puget Sound, For the South Puget Sound</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Third-generation contractors who live where they work.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '</div><!-- /wp:group --></section><!-- /wp:group -->'
)[0];
$built_old  = '<html><body><main><section><h1>Restoration services</h1><p>Water, fire and mold restoration for homes and businesses across Thurston County, day or night.</p></section><section><h2>Built in the South Puget Sound, for the South Puget Sound</h2><p>H2O Away was founded in Tumwater by a family of contractors, and every crew still lives within an hour of the homes it restores.</p></section></main></body></html>';
$built_page = ( new Page_Composer( array( DXAI_UI\Pages\Section_Library::analyze( $why, 0 ), DXAI_UI\Pages\Section_Library::analyze( $why, 1 ), DXAI_UI\Pages\Section_Library::analyze( $built, 2 ) ), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )
	->compose( ( new DXAI_UI\Pages\Content_Extractor() )->extract( $built_old, 'https://old.example.test/services/' ) );
$built_html = implode( '', array_map( 'serialize_block', $built_page['sections'] ) );
$expect( 'it goes into the Home\'s section under the same heading, not the first one of its kind', str_contains( $built_html, 'class="wp-block-group built"' ) && str_contains( $built_html, 'every crew still lives within an hour' ), implode( ' | ', $built_page['log'] ) );
$expect( '…and the photograph the Home shows there stays (the old page has none for it): its two columns stay two', str_contains( $built_html, 'crew-truck.jpg' ), $built_html );
$other_old  = '<html><body><main><section><h1>About us</h1><p>Water, fire and mold restoration for homes and businesses across Thurston County, day or night.</p></section><section><h2>Our history in Thurston County</h2><p>H2O Away was founded in Tumwater by a family of contractors, and every crew still lives within an hour of the homes it restores.</p></section></main></body></html>';
$other_page = ( new Page_Composer( array( DXAI_UI\Pages\Section_Library::analyze( $why, 0 ), DXAI_UI\Pages\Section_Library::analyze( $built, 1 ) ), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )
	->compose( ( new DXAI_UI\Pages\Content_Extractor() )->extract( $other_old, 'https://old.example.test/about/' ) );
$expect( 'a part under another heading does not take the Home\'s photograph', ! str_contains( wp_json_encode( $other_page['sections'] ), 'crew-truck.jpg' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
