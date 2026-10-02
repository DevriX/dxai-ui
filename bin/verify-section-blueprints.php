<?php
/**
 * The sections made from what the Home has (Home_Kit, Section_Refill, Section_Blueprints), against sections made for the purpose:
 * which of a Home's sets of cards can be poured into and which cannot; that what is poured keeps the Home's look and nothing else
 * (a class, a CSS rule or an inline style the Home does not use is refused); that each card says its own title, its own words and
 * leads to its own page; that the words the Home had around the cards are gone; and that the list of a site's pages is made the
 * way the page's type asks (Team_Pages::related_rows()).
 *
 *   bash bin/wp-php.sh bin/verify-section-blueprints.php        (or: wp eval-file bin/verify-section-blueprints.php --user=1)
 *
 * Makes nothing in the database. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Home_Kit;
use DXAI_UI\Pages\Section_Blueprints;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Refill;
use DXAI_UI\Pages\Section_Roles;
use DXAI_UI\Pages\Team_Pages;

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

$heading = static fn( string $t, int $l = 2, string $class = '' ): string => '<!-- wp:heading {"level":' . $l . ( $class !== '' ? ',"className":"' . $class . '"' : '' ) . '} --><h' . $l . ' class="wp-block-heading' . ( $class !== '' ? ' ' . $class : '' ) . '">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t, string $class = '' ): string => '<!-- wp:paragraph' . ( $class !== '' ? ' {"className":"' . $class . '"}' : '' ) . ' --><p' . ( $class !== '' ? ' class="' . $class . '"' : '' ) . '>' . $t . '</p><!-- /wp:paragraph -->';
$group   = static fn( string $class, string $inner, string $tag = 'div', string $extra = '' ): string => '<!-- wp:group {"className":"' . $class . '"' . ( $tag !== 'div' ? ',"tagName":"' . $tag . '"' : '' ) . $extra . '} --><' . $tag . ' class="wp-block-group ' . $class . '">' . $inner . '</' . $tag . '><!-- /wp:group -->';
$link    = static fn( string $t, string $url, string $class = '' ): string => '<!-- wp:dxai-ui/link {"url":"' . $url . '","text":"' . $t . '"' . ( $class !== '' ? ',"className":"' . $class . '"' : '' ) . '} --><a class="wp-block-dxai-ui-link' . ( $class !== '' ? ' ' . $class : '' ) . '" href="' . $url . '">' . $t . '</a><!-- /wp:dxai-ui/link -->';
$boxlink = static fn( string $class, string $url, string $inner ): string => '<!-- wp:dxai-ui/link {"url":"' . $url . '","className":"' . $class . '"} --><a class="wp-block-dxai-ui-link ' . $class . '" href="' . $url . '">' . $inner . '</a><!-- /wp:dxai-ui/link -->';
$image   = static fn( string $alt ): string => '<!-- wp:image {"id":9999999} --><figure class="wp-block-image"><img src="http://example.test/a.jpg" alt="' . $alt . '" class="wp-image-9999999"/></figure><!-- /wp:image -->';

/** A section of cards: an eyebrow, a heading, a lead, the cards, a button under them. */
$cards_section = static function ( array $titles, string $card_class = 'card', string $mode = 'link-inside', string $grid = 'cards' ) use ( $heading, $para, $group, $link, $boxlink ): array {
	$cards = '';
	foreach ( $titles as $t ) {
		$inner = $heading( $t, 3 ) . $para( 'What ' . $t . ' means to the Home.' );
		if ( $mode === 'link-inside' ) {
			$inner .= $link( 'Book ' . $t . ' →', '#contact' );
		}
		$cards .= $mode === 'whole-link' ? $boxlink( $card_class, '#contact', $inner ) : $group( $card_class, $inner, 'article' );
	}

	return parse_blocks( $group( 'section', $group( 'wrap', $para( 'EYEBROW', 'eyebrow' ) . $heading( 'What we fix most' ) . $para( 'The Home\'s own lead.', 'lead' ) . $group( $grid, $cards ) . $para( 'A note the Home put under the cards.' ) . $link( 'See everything', '#all', 'btn' ) ), 'section', ',"anchor":"services","metadata":{"name":"Services Section"}' ) )[0];
};
$lib = static function ( array $blocks ): array {
	$out = array();
	foreach ( $blocks as $i => $b ) {
		$out[] = Section_Library::analyze( $b, $i );
	}

	return $out;
};
$titles = static function ( array $block ): array {
	$out  = array();
	$walk = static function ( array $b ) use ( &$walk, &$out ): void {
		if ( ( $b['blockName'] ?? '' ) === 'core/heading' ) {
			$out[] = trim( html_entity_decode( wp_strip_all_tags( (string) $b['innerHTML'] ) ) );
		}
		foreach ( (array) $b['innerBlocks'] as $c ) {
			$walk( (array) $c );
		}
	};
	$walk( $block );

	return $out;
};
$markup = static fn( array $b ): string => serialize_block( $b );
$rows   = static fn( array $names ): array => array_map( static fn( $n ) => array( 'title' => $n, 'text' => 'Words about ' . $n . '.', 'url' => 'http://site.test/' . sanitize_title( $n ) . '/' ), $names );

echo "Which sets of cards can be poured into\n";
$home    = array(
	$cards_section( array( 'Leaks', 'Heaters', 'Drains' ) ),
	parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Questions' ) . $group( 'qa-list', '<!-- wp:dxai-ui/box {"tagName":"details","className":"qa"} --><details class="qa"><!-- wp:dxai-ui/text {"tagName":"summary"} --><summary>One?</summary><!-- /wp:dxai-ui/text -->' . $para( 'Yes.' ) . '</details><!-- /wp:dxai-ui/box --><!-- wp:dxai-ui/box {"tagName":"details","className":"qa"} --><details class="qa"><!-- wp:dxai-ui/text {"tagName":"summary"} --><summary>Two?</summary><!-- /wp:dxai-ui/text -->' . $para( 'No.' ) . '</details><!-- /wp:dxai-ui/box -->' ) ), 'section' ) )[0],
);
$kit = Home_Kit::of( $lib( $home ) );
$expect( 'a section with a heading and three cards (a title, words, a link) is one to pour into', count( $kit['exemplars'] ) === 1 && $kit['exemplars'][0]['index'] === 0 && $kit['exemplars'][0]['count'] === 3, json_encode( $kit['exemplars'] ) );
$expect( 'the questions (a set of disclosures) are not cards', count( array_filter( $kit['exemplars'], static fn( $e ) => $e['index'] === 1 ) ) === 0 );
$expect( 'its cards\' words are known by title, in lower case', ( $kit['texts']['leaks'] ?? '' ) === 'What Leaks means to the Home.', json_encode( $kit['texts'] ) );

$with_pic = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Team' ) . $group( 'cards', $group( 'card', $image( 'a' ) . $heading( 'A', 3 ) . $para( 'a' ) ) . $group( 'card', $image( 'b' ) . $heading( 'B', 3 ) . $para( 'b' ) ) . $group( 'card', $image( 'c' ) . $heading( 'C', 3 ) . $para( 'c' ) ) ) ), 'section' ) )[0];
$expect( 'cards with a picture of their own are not (the picture would stay the Home\'s)', Home_Kit::of( $lib( array( $with_pic ) ) )['exemplars'] === array() );
$two_sets = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Both' ) . $group( 'cards', $group( 'card', $heading( 'A', 3 ) . $para( 'a' ) ) . $group( 'card', $heading( 'B', 3 ) . $para( 'b' ) ) ) . $group( 'more', $group( 'tile', $heading( 'C', 3 ) . $para( 'c' ) ) . $group( 'tile', $heading( 'D', 3 ) . $para( 'd' ) ) ) ), 'section' ) )[0];
$expect( 'a section with two sets of cards is not one section to pour into', Home_Kit::of( $lib( array( $two_sets ) ) )['exemplars'] === array() );
$two_heads = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'One' ) . $heading( 'Two' ) . $group( 'cards', $group( 'card', $heading( 'A', 3 ) . $para( 'a' ) ) . $group( 'card', $heading( 'B', 3 ) . $para( 'b' ) ) ) ), 'section' ) )[0];
$expect( 'a section with two headings outside the cards is not either', Home_Kit::of( $lib( array( $two_heads ) ) )['exemplars'] === array() );
$mobile = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Few' ) . $group( 'cards', '<!-- wp:group {"className":"card","dxaiData":{"data-mob":""}} --><div class="wp-block-group card">' . $heading( 'A', 3 ) . $para( 'a' ) . '</div><!-- /wp:group --><!-- wp:group {"className":"card","dxaiData":{"data-mob":""}} --><div class="wp-block-group card">' . $heading( 'B', 3 ) . $para( 'b' ) . '</div><!-- /wp:group -->' ) ), 'section' ) )[0];
$expect( 'cards the design shows on one size of screen only are not', Home_Kit::of( $lib( array( $mobile ) ) )['exemplars'] === array() );

echo "\nWhat a section may wear\n";
$vocab = $kit['vocab'];
$expect( 'the Home\'s own section wears nothing the Home does not', Home_Kit::foreign( $home[0], $vocab ) === array() );
$bad       = $home[0];
$bad['innerBlocks'][0]['attrs']['className'] .= ' bg-red-500';
$expect( 'a class the Home does not use is found', Home_Kit::foreign( $bad, $vocab ) !== array() && str_contains( implode( ' ', Home_Kit::foreign( $bad, $vocab ) ), 'bg-red-500' ), implode( ' ', Home_Kit::foreign( $bad, $vocab ) ) );
$css = $home[0];
$css['innerBlocks'][0]['attrs']['dxaiCss'] = 'background:#ff0000';
$expect( 'a CSS rule the Home does not have is found', Home_Kit::foreign( $css, $vocab ) !== array() );
$col = $home[0];
$col['innerBlocks'][0]['attrs']['backgroundColor'] = 'vivid-red';
$expect( 'a colour preset the Home does not use is found', Home_Kit::foreign( $col, $vocab ) !== array() );
$inline = $home[0];
$inline['innerBlocks'][0]['innerHTML'] = str_replace( '<div class=', '<div style="color:red" class=', (string) $inline['innerBlocks'][0]['innerHTML'] );
$expect( 'an inline style is found', Home_Kit::foreign( $inline, $vocab ) !== array() );
$styled_home = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'x' ) ), 'section' ) )[0];
$styled_home['innerBlocks'][0]['innerHTML'] = str_replace( '<div class=', '<div style="gap:8px" class=', (string) $styled_home['innerBlocks'][0]['innerHTML'] );
$expect( 'an inline style the Home itself has is the Home\'s look: it is not found when the Home has it', Home_Kit::foreign( $styled_home, Home_Kit::of( $lib( array( $styled_home ) ) )['vocab'] ) === array() );

echo "\nThe cards, poured\n";
$ex    = $lib( $home )[0];
$rep   = $ex['repeats'][0];
$made  = Section_Refill::cards( $ex, $rep, 'Our other services', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ), 'Related pages' );
$expect( 'three rows make a section', $made !== null );
$t = $titles( (array) $made );
$expect( 'the section says the new heading, and each card its title', $t === array( 'Our other services', 'Alpha', 'Beta', 'Gamma' ), json_encode( $t ) );
$out = $markup( (array) $made );
$expect( 'each card says its own words, and the Home\'s words are not left', str_contains( $out, 'Words about Beta.' ) && ! str_contains( $out, 'means to the Home' ) );
$expect( 'what the Home said around the cards is gone: its label, its lead, its note, its button', ! str_contains( $out, 'EYEBROW' ) && ! str_contains( $out, 'own lead' ) && ! str_contains( $out, 'under the cards' ) && ! str_contains( $out, 'See everything' ) );
$expect( 'each card leads to its own page, and says "Learn more" with the Home\'s arrow', str_contains( $out, 'href="http://site.test/gamma/"' ) && substr_count( $out, '>Learn more →</a>' ) === 3 && ! str_contains( $out, '#contact' ), $out );
$expect( 'the section does not repeat the Home\'s anchor, and the editor names it by what it is', ! str_contains( $out, 'id="services"' ) && ( $made['attrs']['metadata']['name'] ?? '' ) === 'Related pages' );
$expect( 'it wears what the Home wears', Home_Kit::foreign( (array) $made, $vocab ) === array() );
$expect( 'and comes back as the same blocks', ! is_wp_error( Block_Tree::serialize_checked( array( $made ) ) ) );
$expect( 'the Home\'s own section is as it was', str_contains( $markup( $home[0] ), 'What we fix most' ) && str_contains( $markup( $home[0] ), 'EYEBROW' ) );

$two = Section_Refill::cards( $ex, $rep, 'Two', $rows( array( 'Alpha', 'Beta' ) ) );
$expect( 'two rows for three cards: the third card goes', $two !== null && $titles( $two ) === array( 'Two', 'Alpha', 'Beta' ), json_encode( $two ? $titles( $two ) : null ) );
$many = Section_Refill::cards( $ex, $rep, 'Many', $rows( array( 'A', 'B', 'C', 'D', 'E' ) ) );
$expect( 'five rows for three cards: the first three', $many !== null && count( $titles( $many ) ) === 4 );
$expect( 'one row is not a set of cards', Section_Refill::cards( $ex, $rep, 'One', $rows( array( 'A' ) ) ) === null );
$expect( 'a section with no heading to say is not made', Section_Refill::cards( $ex, $rep, ' ', $rows( array( 'A', 'B' ) ) ) === null );

$no_text = $rows( array( 'Alpha', 'Beta', 'Gamma' ) );
$no_text[1]['text'] = '';
$m2 = Section_Refill::cards( $ex, $rep, 'Mixed', $no_text );
$o2 = $markup( (array) $m2 );
$expect( 'a row with no words has none: the Home\'s are not left in its place', $m2 !== null && ! str_contains( $o2, 'means to the Home' ) && ! str_contains( $o2, 'Words about Beta' ) && str_contains( $o2, 'Words about Alpha' ) );

$whole = $lib( array( $cards_section( array( 'Leaks', 'Heaters', 'Drains' ), 'tile', 'whole-link', 'tiles' ) ) )[0];
$mw    = Section_Refill::cards( $whole, $whole['repeats'][0], 'Whole', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$ow    = $markup( (array) $mw );
$expect( 'a card that is a link as a whole leads to the page and has no link inside it', $mw !== null && substr_count( $ow, 'href="http://site.test/' ) === 3 && ! str_contains( $ow, '#contact' ) && ! str_contains( $ow, 'Learn more' ), $ow );

$bare = $lib( array( $cards_section( array( 'Leaks', 'Heaters', 'Drains' ), 'card', 'bare' ) ) )[0];
$mb   = Section_Refill::cards( $bare, $bare['repeats'][0], 'Bare', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$ob   = $markup( (array) $mb );
$expect( 'a card with nowhere to go has its title for the link', $mb !== null && str_contains( $ob, '<a href="http://site.test/alpha/">Alpha</a>' ), $ob );

$listed = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Plans' ) . $group( 'cards', str_repeat( $group( 'card', $heading( 'P', 3 ) . '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>one</li><!-- /wp:list-item --><!-- wp:list-item --><li>two</li><!-- /wp:list-item --></ul><!-- /wp:list -->' . $para( 'Words' ) ), 3 ) ) ), 'section' ) )[0];
$le = $lib( array( $listed ) )[0];
$ml = Section_Refill::cards( $le, $le['repeats'][0], 'Plans', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$expect( 'the lists a card had (bullets of the Home\'s) go with their items, and the card still has its words', $ml !== null && ! str_contains( $markup( $ml ), '<li>one</li>' ) && str_contains( $markup( $ml ), 'Words about Gamma' ) && ! is_wp_error( Block_Tree::serialize_checked( array( $ml ) ) ) );

echo "\nThe blueprint\n";
$library = $lib( $home );
$why     = array();
$r1      = Section_Blueprints::related( $kit, $library, $rows( array( 'Alpha', 'Beta', 'Gamma' ) ), 'Our other services', 'seed', $why );
$expect( 'the list of the pages is made in the Home\'s cards', $r1 !== null && $r1['index'] === 0 && str_starts_with( $r1['op'], 'related:' ), json_encode( $r1 ? array( $r1['index'], $r1['op'] ) : null ) );
$r2 = Section_Blueprints::related( $kit, $library, $rows( array( 'Alpha', 'Beta', 'Gamma' ) ), 'Our other services', 'seed' );
$expect( 'the same pages and seed make the same section', $r2 !== null && $markup( $r1['block'] ) === $markup( $r2['block'] ) && $r1['op'] === $r2['op'] );
$r3 = Section_Blueprints::related( $kit, $library, $rows( array( 'Alpha', 'Beta', 'Delta' ) ), 'Our other services', 'seed' );
$expect( 'other pages are another section (the name says which, so two pages are not one section)', $r3 !== null && $r3['op'] !== $r1['op'] );
$expect( 'fewer than two pages make nothing', Section_Blueprints::related( $kit, $library, $rows( array( 'Alpha' ) ), 'x', 'seed' ) === null );
$expect( 'a Home with no cards makes nothing', Section_Blueprints::related( Home_Kit::of( $lib( array( $home[1] ) ) ), $lib( array( $home[1] ) ), $rows( array( 'A', 'B' ) ), 'x', 'seed' ) === null );

$other = array_merge( $home, array( $cards_section( array( 'One', 'Two', 'Three', 'Four' ), 'tile', 'link-inside', 'tiles' ) ) );
$kit2  = Home_Kit::of( $lib( $other ) );
$a     = Section_Blueprints::related( $kit2, $lib( $other ), $rows( array( 'A', 'B', 'C' ) ), 'Pages', 's', $why, array( 0 ) );
$b     = Section_Blueprints::related( $kit2, $lib( $other ), $rows( array( 'A', 'B', 'C' ) ), 'Pages', 's', $why, array( 2 ) );
$expect( 'a section the page needs for something else is the last to be poured into', $a !== null && $b !== null && $a['index'] === 2 && $b['index'] === 0, json_encode( array( $a['index'] ?? null, $b['index'] ?? null ) ) );

$strict = $kit;
$strict['vocab']['className'] = array_diff_key( $strict['vocab']['className'], array( 'card' => true ) );
$why2   = array();
$none   = Section_Blueprints::related( $strict, $library, $rows( array( 'Alpha', 'Beta', 'Gamma' ) ), 'x', 'seed', $why2 );
$expect( 'a section that wears a class the Home does not use is not made, and the reason is kept', $none === null && $why2 !== array() && str_contains( implode( ' ', $why2 ), 'card' ), implode( ' | ', $why2 ) );

echo "\nThe pages of a site, for a page\n";
$rel = new ReflectionMethod( Team_Pages::class, 'related_rows' );
$rel->setAccessible( true );
$links = array(
	'service|leaks'   => array( 'title' => 'Leaks', 'url' => 'http://s.test/leaks/', 'type' => 'service' ),
	'service|heaters' => array( 'title' => 'Heaters', 'url' => 'http://s.test/heaters/', 'type' => 'service' ),
	'services|all'    => array( 'title' => 'Services', 'url' => 'http://s.test/services/', 'type' => 'services' ),
	'about|about'     => array( 'title' => 'About Us', 'url' => 'http://s.test/about/', 'type' => 'about' ),
	'location|a'      => array( 'title' => 'Leaks in Reno, NV', 'url' => 'http://s.test/reno/', 'type' => 'location' ),
	'areas|areas'     => array( 'title' => 'Service Areas', 'url' => 'http://s.test/areas/', 'type' => 'areas' ),
);
$texts = array( 'heaters' => 'Tanks and tankless.' );
$res   = $rel->invoke( null, 'service|leaks', $links, $texts );
$expect( 'a service page lists the other services, then the page of all of them, then About, up to a row of three', array_column( $res['rows'], 'title' ) === array( 'Heaters', 'Services', 'About Us' ), json_encode( array_column( $res['rows'], 'title' ) ) );
$expect( 'each with the words the Home has for it, none made up', $res['rows'][0]['text'] === 'Tanks and tankless.' && $res['rows'][1]['text'] === '' );
$expect( 'and calls the list "our other services"', $res['heading'] === 'Our other services' );
$res = $rel->invoke( null, 'areas|areas', $links, $texts );
$expect( 'the page that lists the places lists the place pages', array_column( $res['rows'], 'title' ) === array( 'Leaks in Reno, NV' ) && $res['heading'] === 'Where we work' );
$res = $rel->invoke( null, 'services|all', $links, $texts );
$expect( 'the page that lists the services lists the services, nothing added', array_column( $res['rows'], 'title' ) === array( 'Leaks', 'Heaters' ) );
$res = $rel->invoke( null, 'about|about', $links, $texts );
$expect( 'About lists the services and the page of them', array_column( $res['rows'], 'title' ) === array( 'Leaks', 'Heaters', 'Services' ) && $res['heading'] === 'Our services' );

echo "\nThe part a section plays\n";
$groups = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'What we fix most' ) . $group( 'cards', str_repeat( $group( 'card', $heading( 'A', 3 ) . $para( 'words of a card' ) ), 3 ) ) ), 'section' ) )[0];
$expect( 'cards written as plain groups, each with a heading, are cards', Section_Roles::of( $groups, false ) === 'cards', Section_Roles::of( $groups, false ) );
$beside = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'About the shop' ) . $image( 'shop' ) . $para( str_repeat( 'Words about the shop. ', 12 ) ) . $group( 'cards', str_repeat( $group( 'card', $heading( 'A', 3 ) . $para( 'words' ) ), 3 ) ) ), 'section' ) )[0];
$expect( 'a section with a picture and long text beside them stays text and picture (two-col)', Section_Roles::of( $beside, false ) === 'two-col', Section_Roles::of( $beside, false ) );
$plain = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'A line' ) . $para( 'Words' ) ), 'section' ) )[0];
$expect( 'a section with no set of cards is not cards', Section_Roles::of( $plain, false ) !== 'cards' );

echo "\nA card that is a link box of the design's\n";
$lbox  = static fn( string $t ): string => '<!-- wp:amr/link-box {"url":"#x","className":"tile"} --><a class="wp-block-amr-link-box amr-link-box tile" href="#x">' . $heading( $t, 3 ) . $para( 'Words of ' . $t ) . '</a><!-- /wp:amr/link-box -->';
$lb_s  = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Services' ) . $group( 'tiles', $lbox( 'A' ) . $lbox( 'B' ) . $lbox( 'C' ) ) ), 'section' ) )[0];
$lbc   = $lib( array( $lb_s ) )[0];
$mlb   = Section_Refill::cards( $lbc, $lbc['repeats'][0], 'Pages', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$olb   = $markup( (array) $mlb );
$expect( 'a link box is the link as a whole: its title is not a link inside it (a link in a link is broken apart by the browser)', $mlb !== null && substr_count( $olb, '<a ' ) === 3 && ! preg_match( '/<h3[^>]*><a /', $olb ) && str_contains( $olb, 'href="http://site.test/beta/"' ), $olb );

echo "\nA list of pages (more cards than the Home's section has)\n";
$ex_l = $lib( $home )[0];
$ml   = Section_Refill::cards( $ex_l, $ex_l['repeats'][0], 'All', $rows( array( 'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7' ) ), '', 12 );
$ol   = $markup( (array) $ml );
$expect( 'a list shows all its pages: seven cards from a section of three, the Home\'s cards copied in turn', $ml !== null && count( array_filter( $titles( $ml ), static fn( $t ) => preg_match( '/^A\d$/', $t ) === 1 ) ) === 7 && str_contains( $ol, 'Words about A7' ) && ! str_contains( $ol, 'means to the Home' ), json_encode( $ml ? $titles( $ml ) : null ) );
$ml2  = Section_Refill::cards( $ex_l, $ex_l['repeats'][0], 'All', $rows( array( 'B1', 'B2', 'B3', 'B4', 'B5' ) ), '', 4 );
$expect( 'and no more than it is told (4 of 5)', $ml2 !== null && count( array_filter( $titles( $ml2 ), static fn( $t ) => preg_match( '/^B\d$/', $t ) === 1 ) ) === 4 );
$ml3  = Section_Refill::cards( $ex_l, $ex_l['repeats'][0], 'Some', $rows( array( 'C1', 'C2' ) ), '', 12 );
$expect( 'a list of two pages has two cards (the third goes)', $ml3 !== null && count( array_filter( $titles( $ml3 ), static fn( $t ) => preg_match( '/^C\d$/', $t ) === 1 ) ) === 2 );

echo "\nA card of the Home's for a page that is one\n";
$hm = $lib( array( $cards_section( array( 'Leaks', 'Heaters', 'Drains' ) ) ) )[0];
$mh = Section_Refill::cards( $hm, $hm['repeats'][0], 'Pages', array( array( 'title' => 'Drains', 'text' => 'The drains words.', 'url' => 'http://s.test/d/' ), array( 'title' => 'Other', 'text' => '', 'url' => 'http://s.test/o/' ), array( 'title' => 'Leaks', 'text' => 'The leaks words.', 'url' => 'http://s.test/l/' ) ) );
$oh = $markup( (array) $mh );
$expect( 'a row whose title is a card of the Home\'s takes that card (the others take the cards that are left, in turn)', $mh !== null && $titles( $mh ) === array( 'Pages', 'Drains', 'Other', 'Leaks' ) && str_contains( $oh, 'The drains words.' ) && ! str_contains( $oh, 'means to the Home' ), json_encode( $mh ? $titles( $mh ) : null ) );

echo "\nA section with a \"show more\" button\n";
$hid   = '<!-- wp:group {"className":"dxai-on-more hidden"} --><div class="wp-block-group dxai-on-more hidden">' . $group( 'card', $heading( 'Extra One', 3 ) . $para( 'extra words one' ) ) . $group( 'card', $heading( 'Extra Two', 3 ) . $para( 'extra words two' ) ) . '</div><!-- /wp:group -->';
$tog   = '<!-- wp:group {"className":"mt-4"} --><div class="wp-block-group mt-4"><!-- wp:dxai-ui/box {"tagName":"button","className":"dxai-toggle-more"} --><button class="dxai-toggle-more"><!-- wp:dxai-ui/html --><span>Show more</span><!-- /wp:dxai-ui/html --></button><!-- /wp:dxai-ui/box --></div><!-- /wp:group -->';
$sm_s  = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'What we do' ) . $group( 'cards', $group( 'card', $heading( 'S1', 3 ) . $para( 'words s1' ) ) . $group( 'card', $heading( 'S2', 3 ) . $para( 'words s2' ) ) . $group( 'card', $heading( 'S3', 3 ) . $para( 'words s3' ) ) ) . $hid . $tog ), 'section' ) )[0];
$smc   = $lib( array( $sm_s ) )[0];
$expect( 'a section whose "show more" button reveals more cards is one to pour into (the button and what it reveals are the section\'s machinery, not words)', $smc['controls'] !== array() && Home_Kit::of( array( $smc ) )['exemplars'] !== array(), json_encode( Home_Kit::of( array( $smc ) )['exemplars'] ) );
$msm   = Section_Refill::cards( $smc, $smc['repeats'][0], 'Pages', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$osm   = $markup( (array) $msm );
$expect( 'the poured section has no button and nothing hidden, and shows its three cards', $msm !== null && ! str_contains( $osm, 'dxai-on-' ) && ! str_contains( $osm, 'dxai-toggle-' ) && ! str_contains( $osm, 'Extra One' ) && ! str_contains( $osm, 'Show more' ) && str_contains( $osm, 'Words about Gamma' ), $osm );
$ml_sm = Section_Refill::cards( $smc, $smc['repeats'][0], 'All', $rows( array( 'P1', 'P2', 'P3', 'P4', 'P5' ) ), '', 12 );
$expect( 'and as a list it shows all five', $ml_sm !== null && count( array_filter( $titles( $ml_sm ), static fn( $t ) => preg_match( '/^P\d$/', $t ) === 1 ) ) === 5 );
$play = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Video' ) . $group( 'cards', str_repeat( $group( 'card', $heading( 'V', 3 ) . $para( 'words' ) ), 3 ) ) . '<!-- wp:dxai-ui/html --><button class="play">Play</button><!-- /wp:dxai-ui/html -->' ), 'section' ) )[0];
$expect( 'any other control (a play button) stays the Home\'s: that section is not poured into', Home_Kit::of( $lib( array( $play ) ) )['exemplars'] === array() );
$icon_card = static fn( string $t ): string => $group( 'card', '<!-- wp:image {"id":9999998,"className":"dxai-part-img dxai-icon"} --><figure class="wp-block-image dxai-part-img dxai-icon"><img src="http://example.test/i.svg" alt="" class="wp-image-9999998"/></figure><!-- /wp:image -->' . $heading( $t, 3 ) . $para( 'Words of ' . $t ) );
$ic_s = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Services' ) . $group( 'cards', $icon_card( 'Leaks' ) . $icon_card( 'Heaters' ) . $icon_card( 'Drains' ) ) ), 'section' ) )[0];
$expect( 'cards with an icon of the design\'s (a small file of its own) are cards to pour into: the icon is not a picture about the card', Home_Kit::of( $lib( array( $ic_s ) ) )['exemplars'] !== array() );

echo "\nA label before the title\n";
$labelled = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Plans' ) . $group( 'cards', str_repeat( $group( 'card', $para( '01', 'num' ) . $heading( 'P', 3 ) . $para( 'The Home\'s words.' ) ), 3 ) ) ), 'section' ) )[0];
$lc = $lib( array( $labelled ) )[0];
$ml2 = Section_Refill::cards( $lc, $lc['repeats'][0], 'Plans', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$ol  = $markup( (array) $ml2 );
$expect( 'a line before the title (a number, a tag) is the Home\'s label and goes; the words go under the title', $ml2 !== null && ! str_contains( $ol, '>01<' ) && ! str_contains( $ol, 'The Home\'s words' ) && preg_match( '/<h3[^>]*>(?:<a[^>]*>)?Alpha(?:<\/a>)?<\/h3>.*?<p>Words about Alpha\.<\/p>/s', $ol ) === 1, $ol );

echo "\nA smaller heading under the cards\n";
$noted = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'What we fix' ) . $group( 'cards', $group( 'card', $heading( 'A', 3 ) . $para( 'words a' ) ) . $group( 'card', $heading( 'B', 3 ) . $para( 'words b' ) ) . $group( 'card', $heading( 'C', 3 ) . $para( 'words c' ) ) ) . $heading( 'Also available', 4 ) . $para( 'A note about other things.' ) ), 'section' ) )[0];
$nc    = $lib( array( $noted ) )[0];
$expect( 'a section with a smaller heading after its cards (a note) is one to pour into', Home_Kit::of( array( $nc ) )['exemplars'] !== array(), json_encode( array_column( $nc['panels'], 'level' ) ) );
$mn  = Section_Refill::cards( $nc, $nc['repeats'][0], 'Pages', $rows( array( 'Alpha', 'Beta', 'Gamma' ) ) );
$on  = $markup( (array) $mn );
$expect( 'and the note goes, with its heading', $mn !== null && ! str_contains( $on, 'Also available' ) && ! str_contains( $on, 'A note about other things' ) && str_contains( $on, 'Words about Gamma' ), $on );
$expect( 'a second heading as big as the first (a second section) is still not', Home_Kit::of( $lib( array( $two_heads ) ) )['exemplars'] === array() );

echo "\nThe cards of a Home's own list, when it is not poured into\n";
$fr = new ReflectionMethod( Team_Pages::class, 'fill_related' );
$fr->setAccessible( true );
$fr_c = $lib( array( $cards_section( array( 'Leaks', 'Heaters', 'Drains' ), 'card', 'bare' ) ) )[0];
$sib  = array(
	array( 'title' => 'Heaters', 'url' => 'http://s.test/heaters/', 'type' => 'service' ),
	array( 'title' => 'Drains', 'url' => 'http://s.test/drains/', 'type' => 'service' ),
	array( 'title' => 'Unknown', 'url' => 'http://s.test/unknown/', 'type' => 'service' ),
);
$fb   = $fr->invoke( null, $fr_c['block'], $fr_c, $sib, 'Leaks', array( 'heaters' => 'The heaters words.', 'drains' => 'The drains words.' ) );
$ofb  = $markup( (array) $fb );
$expect( 'a card has the words the Home has for its title, and a card the Home has no words for has none (not the words of the card that was there)', str_contains( $ofb, 'The heaters words.' ) && str_contains( $ofb, 'The drains words.' ) && ! str_contains( $ofb, 'means to the Home' ) && str_contains( $ofb, 'Unknown' ), $ofb );
$pic_c = $lib( array( $with_pic ) )[0];
$fp    = $fr->invoke( null, $pic_c['block'], $pic_c, $sib, 'A', array() );
$expect( 'cards with a picture of their own are left as the Home has them (a picture would stay under the wrong title)', $markup( (array) $fp ) === $markup( $pic_c['block'] ) );

echo "\nA card with a button, a card with no address\n";
$btn_card = static fn( string $t ): string => $group( 'card', $heading( $t, 3 ) . $para( 'Words of ' . $t ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Book it</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' );
$btn_sec  = parse_blocks( $group( 'section', $group( 'wrap', $heading( 'Services' ) . $group( 'cards', $btn_card( 'A' ) . $btn_card( 'B' ) . $btn_card( 'C' ) ) ), 'section' ) )[0];
$bc       = $lib( array( $btn_sec ) )[0];
$mbtn     = Section_Refill::cards( $bc, $bc['repeats'][0], 'Pages', array( array( 'title' => 'One', 'text' => 'one', 'url' => 'http://s.test/one/', 'label' => 'Call now' ), array( 'title' => 'Two', 'text' => 'two', 'url' => 'http://s.test/two/', 'label' => '' ) ) );
$obtn     = $markup( (array) $mbtn );
$expect( 'a button\'s link keeps its link when it is given a page and words (it once came out as plain text)', $mbtn !== null && substr_count( $obtn, '<a class="wp-block-button__link' ) === 2 && str_contains( $obtn, 'href="http://s.test/one/">Call now</a>' ) && str_contains( $obtn, 'href="http://s.test/two/">Learn more</a>' ), $obtn );
$none = Section_Refill::cards( $bc, $bc['repeats'][0], 'Where', array( array( 'title' => 'Visit us', 'text' => '1 Main St', 'url' => '', 'label' => '' ), array( 'title' => 'Call us', 'text' => '555', 'url' => 'tel:555', 'label' => 'Call' ) ) );
$onone = $markup( (array) $none );
$expect( 'a card with no address has no link, and its neighbour keeps its own', $none !== null && substr_count( $onone, '<a class="wp-block-button__link' ) === 1 && str_contains( $onone, 'href="tel:555"' ) && str_contains( $onone, '1 Main St' ), $onone );
$whole2 = Section_Refill::cards( $whole, $whole['repeats'][0], 'x', array( array( 'title' => 'Visit us', 'text' => '1 Main St', 'url' => '' ), array( 'title' => 'B', 'text' => 'b', 'url' => 'http://s.test/b/' ) ) );
$expect( 'a card that is a link as a whole cannot lead nowhere: that set is not used for it', $whole2 === null );

echo "\nWhat the Home states about reaching it\n";
$f = Home_Kit::facts_of( '<a href="tel:5035550142">Call (503) 555-0142</a> <a class="btn" href="tel:5035550142">Call now</a> <a href="tel:+18005550100">Other</a> <a href="mailto:hi@harbor.test">Mail</a> <footer><p>1180 SE Water Ave, Portland, OR</p><ul><li>Services</li><li>Questions</li></ul></footer>' );
$expect( 'the phone linked most, as the Home writes it', ( $f['phone']['text'] ?? '' ) === '(503) 555-0142' && ( $f['phone']['url'] ?? '' ) === 'tel:5035550142', json_encode( $f['phone'] ) );
$expect( 'the e-mail it links', $f['email'] === 'hi@harbor.test' );
$expect( 'the street address, and not the words after it', $f['address'] === '1180 SE Water Ave, Portland, OR', $f['address'] );
$f2 = Home_Kit::facts_of( '<p>Call us any time. Since 1998 we have served 24 towns.</p><a href="tel:12">x</a>' );
$expect( 'what is not stated is empty: no phone from a short number, no address from a date, no e-mail', $f2 === array( 'phone' => null, 'email' => '', 'address' => '' ), json_encode( $f2 ) );
$f3 = Home_Kit::facts_of( '<a href="tel:5035550142">Call now</a>' );
$expect( 'a phone link that says "Call now" is given its number', ( $f3['phone']['text'] ?? '' ) === '(503) 555-0142', json_encode( $f3['phone'] ) );
$f4 = Home_Kit::facts_of( '<p>497 Lendall Ln, Suite 105</p> <p>7 Franklin St</p>' );
$expect( 'an address with a suite; the first address is the Home\'s', $f4['address'] === '497 Lendall Ln, Suite 105', $f4['address'] );
$cr = Section_Blueprints::contact_rows( $f );
$expect( 'the rows: call, e-mail, visit — in that order, each with what the Home said', array_column( $cr, 'title' ) === array( 'Call us', 'Email us', 'Visit us' ) && $cr[0]['url'] === 'tel:5035550142' && $cr[1]['url'] === 'mailto:hi@harbor.test' && $cr[2]['url'] === '' && $cr[2]['text'] === '1180 SE Water Ave, Portland, OR' );
$expect( 'nothing stated, no rows', Section_Blueprints::contact_rows( array( 'phone' => null, 'email' => '', 'address' => '' ) ) === array() );

echo "\nA bar of badges\n";
$bar   = parse_blocks( $group( 'trust', '<!-- wp:dxai-ui/text {"tagName":"div","className":"wrap trust-row"} --><div class="wrap trust-row"><span>4.9 ★ from 1,240 reviews</span><span>Licensed CCB #218840</span><span>Upfront pricing</span><span>Same-day service</span></div><!-- /wp:dxai-ui/text -->', 'section' ) )[0];
$bar_c = Section_Library::analyze( $bar, 0 );
$expect( 'a row of short claims with no heading and no link is a section (badges), not nothing', $bar_c['kind'] === 'badges', $bar_c['kind'] );
$plain_tw = parse_blocks( $group( 'section', '<!-- wp:dxai-ui/text {"tagName":"div","className":"flex"} --><div class="flex"><span>One</span><span>Two</span><span>Three</span></div><!-- /wp:dxai-ui/text -->', 'section' ) )[0];
$expect( 'and a design that does not name it still has it as the part of trust (the role is read from what it holds)', Section_Roles::of( $plain_tw, false ) === 'trust', Section_Roles::of( $plain_tw, false ) );
$long = parse_blocks( $group( 'section', $heading( 'Why' ) . $para( str_repeat( 'Long words. ', 30 ) ), 'section' ) )[0];
$expect( 'text with a heading is not badges', Section_Library::analyze( $long, 0 )['kind'] !== 'badges' );
$pool_m = new ReflectionMethod( Team_Pages::class, 'pool' );
$pool_m->setAccessible( true );
$roles_m = new ReflectionMethod( Team_Pages::class, 'home_roles' );
$roles_m->setAccessible( true );
$lib2   = array( Section_Library::analyze( parse_blocks( $group( 'section', $group( 'x', $heading( 'Hello', 1 ) ), 'section' ) )[0], 0 ), $bar_c );
$lib2[1]['index'] = 1;
$r2 = $roles_m->invoke( null, $lib2 );
$expect( 'a Home with no reviews that says it is rated (4.9 from 1,240 reviews) has that to show for them', array_keys( $pool_m->invoke( null, $lib2, $r2, 'reviews' ) ) === array( 1 ), json_encode( $pool_m->invoke( null, $lib2, $r2, 'reviews' ) ) );
$bar2 = parse_blocks( $group( 'trust', '<!-- wp:dxai-ui/text {"tagName":"div"} --><div><span>Licensed</span><span>Insured</span><span>Same-day service</span></div><!-- /wp:dxai-ui/text -->', 'section' ) )[0];
$lib3 = array( $lib2[0], Section_Library::analyze( $bar2, 1 ) );
$r3   = $roles_m->invoke( null, $lib3 );
$expect( 'a bar that says nothing of reviews is not reviews', $pool_m->invoke( null, $lib3, $r3, 'reviews' ) === array() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
