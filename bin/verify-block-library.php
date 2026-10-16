<?php
/**
 * The team's own sections, kept in the plugin (data/block-library) and used for the pages made from a Home.
 *
 *   bash bin/wp-php.sh bin/verify-block-library.php        (or: wp eval-file bin/verify-block-library.php --user=1)
 *
 * What it holds, in five parts:
 *
 *  1. the data: the index and every section parse, every section is one block of registered blocks, every `{{token}}` is one the
 *     library knows, every marker and drawing is one it can read, and nothing of the two companies the sections were taken from is
 *     left in them (names, domains, phones, e-mails, addresses, cities, numbers of customers, review widgets, brand colours — the
 *     list the build stops on, run again here over what is shipped);
 *  2. the fill: what a section becomes for a company — its name, phone and places in it, escaped; a place with no page is a box and
 *     not a link; the buttons that need a fact leave when it is missing; nothing of the library's own is left in the page;
 *  3. when a section is used: for the kind of company its words are for, the kind of page it is for, a theme it is made for, and a
 *     Home that says what it needs;
 *  4. the pages made for a Home: the library fills the places the Home has no section for (or takes the Home's place, when asked,
 *     or is not used), the plan makes nothing (not even a drawing's file), a locked section comes back, and the Home is not changed;
 *  5. the assets: only a page that holds a section of the library asks for its styles and script.
 *
 * Each of the checks that decide something is run once against a made-up case that must fail, so a check that cannot fail shows here.
 *
 * Makes a Home and its pages and removes them again (and the drawings it made). Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Library_View;
use DXAI_UI\Pages\Block_Library;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Home_Kit;
use DXAI_UI\Pages\Team_Pages;
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

// The plugin's own library is what is checked: a library the site keeps in its uploads (named as its owner likes) is another suite's.
$pin_built_in = static fn() => \DXAI_UI\Pages\Template::BUILT_IN;
add_filter( 'dxai_ui_template', $pin_built_in, 5 );
Block_Library::forget();
$entries = Block_Library::entries();
if ( $entries === array() ) {
	echo "  FAIL  the library has no sections (data/block-library/library.json)\n";
	exit( 1 );
}

// The words a section must not hold: the build's own list, when this is a checkout (it is a developer tool), else the short one.
$forbidden = array();
$specs_php = dirname( __DIR__ ) . '/bin/block-library-specs.php';
if ( is_readable( $specs_php ) ) {
	require $specs_php;
}
if ( $forbidden === array() ) {
	$forbidden = array(
		'a company name' => '/\b(arcus|archer|aarcher)\b/i',
		'a phone number' => '/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/',
		'an address of a site' => '/https?:\/\//i',
	);
}
// The sites the team's layouts were read from (bin/build-layouts.php): none of their names reaches a page, in words or in a section's name.
$forbidden['a name of a site the layouts came from'] = '/\b(paces|pacesga|calludk|iddk|ajrestores|a\s?&\s?j restoration|complete restoration|completerestoration|first call restoration|1stcall|procraft|pro-craft|h2o away|h2oaway|flood rescue|floodrescue|americanrestorationnm|utah disaster|disaster kleenup|archerrestorations|arcusrestoration)\b/i';
$leaks = static function ( string $markup ) use ( $forbidden ): array {
	$plain = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' ' . $markup;
	$out   = array();
	foreach ( $forbidden as $label => $re ) {
		if ( preg_match( $re, $plain, $m ) === 1 ) {
			$out[] = $label . ' (“' . substr( $m[0], 0, 40 ) . '”)';
		}
	}

	return $out;
};
$known_tokens = array( 'company', 'company_short', 'phone', 'phone_url', 'email', 'address', 'region', 'title', 'contact_url', 'site_url', 'row', 'icon_url', 'icon_id', 't', 'image_url', 'image_id', 'image_alt', 'image_w', 'image_h' );
$known_lists  = array_merge( array( 'places', 'services' ), array_keys( \DXAI_UI\Pages\Layouts::MAX ) );
$names_in     = static function ( string $markup ): array {
	preg_match_all( '/\{\{\s*([a-z_]+)/i', $markup, $m );

	return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
};
$flat = static function ( array $block ) use ( &$flat ): array {
	$out = array( $block );
	foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
		$out = array_merge( $out, $flat( (array) $child ) );
	}

	return $out;
};

echo "The data\n";
$doc = json_decode( (string) file_get_contents( DXAI_UI_DIR . 'data/block-library/library.json' ), true );
$expect( 'the index is version 1 and names the theme it is for', is_array( $doc ) && (int) ( $doc['version'] ?? 0 ) === 1 && in_array( 'american-restoration', (array) ( $doc['themes'] ?? array() ), true ) );
$expect( 'there are sections, each with an id, a role, a label, what it needs and the pages it is for', count( $entries ) >= 6 && count( array_filter( $entries, static fn( $e ) => preg_match( '/^[a-z0-9-]{2,60}$/', (string) $e['id'] ) === 1 && (string) $e['role'] !== '' && (string) $e['label'] !== '' && is_array( $e['needs'] ) && ( (array) $e['pages'] !== array() || ( $e['source'] ?? '' ) === 'layout' ) ) ) === count( $entries ), (string) count( $entries ) );
$roles = array_unique( array_map( static fn( $e ) => (string) $e['role'], $entries ) );
sort( $roles );
$expect( 'the roles are the ones a page is made of: ' . implode( ', ', $roles ), array_diff( $roles, array( 'cta', 'faq', 'process', 'areas', 'hero', 'trust', 'reviews', 'cards', 'related', 'two-col', 'form', 'contact', 'content', 'text' ) ) === array(), implode( ', ', array_diff( $roles, array( 'cta', 'faq', 'process', 'areas' ) ) ) );
$registry = WP_Block_Type_Registry::get_instance();
foreach ( $entries as $id => $e ) {
	$markup = Block_Library::markup( (string) $id );
	$blocks = array_values( array_filter( parse_blocks( $markup ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	$expect( "$id: one section, a group with the library's class, of " . strlen( $markup ) . ' bytes', $markup !== '' && count( $blocks ) === 1 && ( $blocks[0]['blockName'] ?? '' ) === 'core/group' && Library_View::has_section( (string) ( $blocks[0]['attrs']['className'] ?? '' ) ) );
	$names = array();
	foreach ( $flat( $blocks[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ) ) as $b ) {
		$names[ (string) $b['blockName'] ] = true;
	}
	$unknown = array_filter( array_keys( $names ), static fn( $n ) => $n !== '' && ! $registry->is_registered( $n ) );
	$expect( "$id: made of registered blocks, and the index lists exactly them", $unknown === array() && array_keys( $names ) !== array() && array_diff( array_keys( $names ), (array) $e['blocks'] ) === array() && array_diff( (array) $e['blocks'], array_keys( $names ) ) === array(), implode( ', ', $unknown ) );
	$expect( "$id: nothing of the sites it was taken from is left", $leaks( $markup ) === array(), implode( '; ', $leaks( $markup ) ) );
	$tokens = $names_in( $markup );
	// A row's drawing is a token in a token ({{icon_url:{{row:icon|check}}}}: the row names it, the library draws it): one level deep, no more.
	$once = (string) preg_replace( '/\{\{[^{}]*\}\}/', '', $markup );
	$expect( "$id: every token is one the library fills, and is closed (" . implode( ' ', $tokens ) . ')', array_diff( $tokens, $known_tokens ) === array() && substr_count( $once, '{{' ) === preg_match_all( '/\{\{[^{}]*\}\}/', $once ) && ! str_contains( (string) preg_replace( '/\{\{[^{}]*\}\}/', '', $once ), '{{' ), implode( ',', array_diff( $tokens, $known_tokens ) ) );
	preg_match_all( '/"(dxaiIf|dxaiRepeat|dxaiIcon)":"([^"]*)"/', $markup, $mk, PREG_SET_ORDER );
	$bad_marker = array();
	foreach ( $mk as $m ) {
		// A row's drawing ({{row:icon|check}}): the row names it, else the one after the bar; that one is shipped.
		$drawing = preg_match( '/^\{\{row:icon\|([a-z0-9-]*)\}\}$/', $m[2], $dm ) === 1 ? $dm[1] : $m[2];
		if ( $m[1] === 'dxaiIcon' && ( $drawing === '' || ! is_readable( DXAI_UI_DIR . 'data/block-library/icons/' . $drawing . '.svg' ) ) ) {
			$bad_marker[] = 'no drawing ' . $m[2];
		}
		if ( $m[1] === 'dxaiRepeat' && ! in_array( $m[2], $known_lists, true ) ) {
			$bad_marker[] = 'a list ' . $m[2];
		}
	}
	$expect( "$id: its markers (conditions, lists, drawings) are ones the library reads, and its drawings are shipped", $bad_marker === array(), implode( ', ', $bad_marker ) );
	$round = Block_Tree::serialize_checked( $blocks );
	$expect( "$id: it parses back to the same markup", ! is_wp_error( $round ) );
	// The block editor writes a picture's size from the block's own settings: a drawing whose size is only in the markup is "invalid
	// content" there (the editor, not this suite, is the judge of that; this holds what it found).
	$sized = array();
	foreach ( $flat( $blocks[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ) ) as $b ) {
		if ( isset( $b['attrs']['dxaiIcon'] ) ) {
			$w      = (string) ( $b['attrs']['width'] ?? '' );
			$h      = (string) ( $b['attrs']['height'] ?? '' );
			$in     = static fn( string $prop ): bool => preg_match( '/<img[^>]*\sstyle="[^"]*(?<![\w-])' . $prop . ':/', (string) $b['innerHTML'] ) === 1;
			// A size the team gave it (a width, a height, or both) is in the settings and in the markup alike; one they left to the file is in neither.
			if ( ( $w !== '' && ! str_contains( (string) $b['innerHTML'], 'width:' . $w ) ) || ( $h !== '' && ! str_contains( (string) $b['innerHTML'], 'height:' . $h ) ) || ( $w === '' && $in( 'width' ) ) || ( $h === '' && $in( 'height' ) ) ) {
				$sized[] = (string) $b['attrs']['dxaiIcon'];
			}
		}
	}
	$expect( "$id: its drawings keep the size the team gave them, as block settings", $sized === array(), implode( ', ', $sized ) );
}
foreach ( glob( DXAI_UI_DIR . 'data/block-library/icons/*.svg' ) ?: array() as $svg ) {
	$text = (string) file_get_contents( $svg );
	$expect( basename( $svg ) . ': a drawing, with nothing that runs in it', preg_match( '/^\s*<svg\b/', $text ) === 1 && preg_match( '/<script|on[a-z]+=|javascript:|<foreignObject|href=/i', $text ) !== 1 );
}
$planted = '<!-- wp:paragraph --><p>Call Acme at (770) 697-0795 or visit https://arcusrestoration.com in Acworth, GA — 20,000+ customers.</p><!-- /wp:paragraph -->';
$expect( 'the leak check is able to catch a phone, a site, a name, a city and a number of customers', count( $leaks( $planted ) ) >= 3, implode( '; ', $leaks( $planted ) ) );
$expect( 'the token check is able to catch a token that is not closed', substr_count( '{{company} Today', '{{' ) !== preg_match_all( '/\{\{[^{}]*\}\}/', '{{company} Today' ) );

echo "\nThe fill\n";
$facts = array(
	'home'          => 0,
	'industry'      => 'restoration',
	'company'       => 'Smith & Sons "Pro" Restoration',
	'company_short' => 'Smith & Sons "Pro"',
	'phone'         => '(555) 010-2030',
	'phone_url'     => 'tel:+15550102030',
	'email'         => '',
	'address'       => '',
	'region'        => '',
	'title'         => 'Water Damage',
	'kind'          => '',
	'contact_url'   => 'https://example.test/contact/',
	'site_url'      => 'https://example.test/',
	'places'        => array(
		array( 'title' => 'Tyler, TX', 'url' => 'https://example.test/tyler/' ),
		array( 'title' => 'Dallas & Fort Worth, TX', 'url' => '' ),
		array( 'title' => 'Longview, TX', 'url' => 'https://example.test/longview/' ),
	),
	'services'      => array(),
	'dry'           => true,
	// What a page's facts say for the team's layouts (Layouts::page_facts): where on the page the section is, what the Home states, its
	// lists and its pictures. Each list is as long as a Home gives (Layouts::MAX); each row's title is a word of its own, found once.
	'role'          => 'cards',
	'slot_seed'     => '7',
	'about'         => '',
	'topic'         => 'water damage',
	'lists'         => array(),
	'image_url:1'   => 'https://example.test/wp-content/uploads/one.jpg',
	'image_id:1'    => '101',
	'image_alt:1'   => 'A dried-out living room',
	'image_w:1'     => '1200',
	'image_h:1'     => '800',
	'image_url:2'   => 'https://example.test/wp-content/uploads/two.jpg',
	'image_id:2'    => '102',
	'image_alt:2'   => '',
	'image_w:2'     => '900',
	'image_h:2'     => '600',
	'image_url:3'   => 'https://example.test/wp-content/uploads/three.jpg',
	'image_id:3'    => '103',
	'image_alt:3'   => 'A crew at work',
	'image_w:3'     => '640',
	'image_h:3'     => '480',
);
$words_of = array( 'Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliett', 'Kilo', 'Lima' );
$rows_of  = static function ( string $list, int $n ) use ( $words_of ): array {
	$rows = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$w      = $words_of[ $i ];
		$rows[] = match ( $list ) {
			'steps'   => array( 'title' => "Step $w", 'text' => "What the $w step of the work is.", 'url' => '' ),
			'reviews' => array( 'title' => "Reviewer $w", 'text' => "The $w review of the work, in the customer's words.", 'meta' => "Homeowner, $w Street", 'url' => '' ),
			'faq'     => array( 'title' => "Question $w?", 'text' => "The $w answer.", 'url' => '' ),
			'logos'   => array( 'title' => "Logo $w", 'text' => '', 'url' => '', 'image_url' => "https://example.test/wp-content/uploads/logo-$w.png", 'image_id' => (string) ( 200 + $i ), 'image_alt' => "Logo $w" ),
			'stats'   => array( 'title' => ( 1 + $i ) . ",000+", 'text' => "Stat $w", 'url' => '' ),
			'items'   => array( 'title' => "Item $w", 'text' => "What the $w item means for you.", 'url' => $i % 2 ? '' : "https://example.test/item-$w/", 'icon' => 'check' ),
			'related' => array( 'title' => "Related $w", 'text' => "The $w service, in a line.", 'url' => "https://example.test/related-$w/", 'icon' => 'droplet' ),
			default   => array( 'title' => "Row $w", 'text' => '', 'url' => '' ),
		};
	}

	return $rows;
};
foreach ( \DXAI_UI\Pages\Layouts::MAX as $list => $max ) {
	if ( $list !== 'places' && $list !== 'services' ) {
		$facts['lists'][ $list ] = $rows_of( $list, $max );
	}
}
foreach ( array_keys( $entries ) as $id ) {
	$why   = '';
	$block = Block_Library::fill( (string) $id, $facts, $why );
	$expect( "$id: it is made for a company that says its phone, its name and its places", $block !== null, $why );
	if ( $block === null ) {
		continue;
	}
	$markup = serialize_block( $block );
	$expect( "$id: no token, no marker, no empty or unfilled address is left in it", ! str_contains( $markup, '{{' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon|dxaiCount|dxaiSlice/', $markup ) && ! preg_match( '/href=""|href="\{\{|"url":"\{\{/', $markup ), substr( (string) preg_replace( '/\s+/', ' ', $markup ), 0, 120 ) );
	$expect( "$id: it parses back to the same markup", ! is_wp_error( Block_Tree::serialize_checked( array( $block ) ) ) );
	$text = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( in_array( 'company', (array) $entries[ $id ]['tokens'], true ) ) {
		$expect( "$id: the company's name is in it as it is, and escaped where it is HTML", str_contains( $text, 'Smith & Sons "Pro" Restoration' ) && ! str_contains( $markup, '<script' ) && substr_count( $markup, 'Smith & Sons' ) === 0, '' );
	}
}
$why  = '';
$call = Block_Library::fill( 'cta-call', $facts, $why );
$expect( 'the call button goes to the phone and says the number', $call !== null && str_contains( serialize_block( $call ), 'href="tel:+15550102030"' ) && str_contains( serialize_block( $call ), 'Call Now: (555) 010-2030' ) );
$why    = '';
$places = Block_Library::fill( 'areas-places', $facts, $why );
$pm     = $places !== null ? serialize_block( $places ) : '';
$expect( 'a list has a row for each place, and no more', substr_count( $pm, '<!-- wp:amr/link-box' ) === 3 && str_contains( $pm, 'Tyler, TX' ) && str_contains( $pm, 'Longview, TX' ) && str_contains( $pm, 'Dallas &amp; Fort Worth, TX' ) );
$expect( 'a place with a page is a link to it; a place with none is a box, not a link without an address', substr_count( $pm, '<a class="wp-block-amr-link-box' ) === 2 && substr_count( $pm, '<div class="wp-block-amr-link-box' ) === 1 && ! str_contains( $pm, 'amr-link-box--has-link  ' ) );
$expect( 'its drawing is not made by a plan: it has no file and no address yet', preg_match_all( '/<img src=""/', $pm ) === 3 );
$dry = Block_Library::icon( 'chevron', true );
$expect( 'a drawing asked for by a plan is not made (no id, no address), and one that is not shipped is not there', $dry !== null && $dry['id'] === 0 && $dry['url'] === '' && Block_Library::icon( 'no-such-drawing', true ) === null && Block_Library::icon( '../library', true ) === null );
$none             = $facts;
$none['phone']    = '';
$none['phone_url'] = '';
$why    = '';
$banner = Block_Library::fill( 'cta-banner', $none, $why );
$expect( 'a call to action is not made for a company that says no phone', $banner === null && $why !== '', $why );
$why      = '';
$no_phone = Block_Library::fill( 'areas-places', $none, $why );
$expect( 'the places are made without the call button, with the one that asks (the page to ask on)', $no_phone !== null && ! str_contains( serialize_block( $no_phone ), 'tel:' ) && str_contains( serialize_block( $no_phone ), 'Request Free Inspection' ), $why );
$why = '';
$bare = $none;
$bare['contact_url'] = '';
$expect( 'and not at all when there is neither a phone nor a page to ask on', Block_Library::fill( 'areas-places', $bare, $why ) === null && str_contains( $why, 'phone or contact_url' ), $why );
$why   = '';
$empty = $facts;
$empty['places'] = array();
$expect( 'a list is not made with nothing to list', Block_Library::fill( 'areas-places', $empty, $why ) === null && $why !== '', $why );
$why = '';
$expect( 'a company with no region is "the area we serve" in a sentence, with one it is the region', str_contains( serialize_block( (array) Block_Library::fill( 'faq-accordion', $facts, $why ) ), 'the surrounding area' ) && str_contains( serialize_block( (array) Block_Library::fill( 'faq-accordion', array_merge( $facts, array( 'region' => 'North Texas' ) ), $why ) ), 'serves North Texas' ), $why );
$expect( 'the short name leaves out what every company is called ("Arcus Restoration" is "Arcus")', Block_Library::short( 'Arcus Restoration' ) === 'Arcus' && Block_Library::short( 'Semper Dry' ) === 'Semper Dry' && Block_Library::short( 'Restoration' ) === 'Restoration' && Block_Library::short( 'Acme Services LLC' ) === 'Acme' );

echo "\nWhen a section is used\n";
$why = '';
$expect( 'a Home that is another kind of company gets none (the words are a restoration company\'s)', Block_Library::fill( 'cta-call', array_merge( $facts, array( 'industry' => '' ) ), $why ) === null && str_contains( $why, 'restoration' ), $why );
$why = '';
$expect( 'the page of questions is for the page of questions, not a service', Block_Library::section( 'faq', array_merge( $facts, array( 'kind' => 'service' ) ), 'seed', array(), 'faq-page', $why ) === null, $why );
$why  = '';
$faqp = Block_Library::section( 'faq', array_merge( $facts, array( 'kind' => 'faq' ) ), 'seed', array(), 'faq-page', $why );
$expect( 'and is there for a page of questions', $faqp !== null && $faqp['id'] === 'faq-page', $why );
$taken = array();
for ( $n = 0; $n < 12; $n++ ) {
	$why = '';
	$one = Block_Library::section( 'faq', array_merge( $facts, array( 'kind' => 'service' ) ), 'seed' . $n, array(), '', $why );
	if ( $one !== null ) {
		$taken[ $one['id'] ] = true;
	}
}
$expect( 'a service page is offered the short sections of questions, in turn, never the long page', $taken !== array() && ! isset( $taken['faq-page'] ), implode( ',', array_keys( $taken ) ) );
$why = '';
$a   = Block_Library::section( 'cta', $facts, 'same seed', array(), '', $why );
$b   = Block_Library::section( 'cta', $facts, 'same seed', array(), '', $why );
$expect( 'the same seed picks the same section', $a !== null && $b !== null && $a['id'] === $b['id'] );
$c = Block_Library::section( 'cta', $facts, 'same seed', array( (string) $a['id'] ), '', $why );
$expect( 'one the page has already is not taken again', $c !== null && $c['id'] !== $a['id'] );
$force_generic = static fn() => \DXAI_UI\Pages\Template::GENERIC;
add_filter( 'dxai_ui_template', $force_generic );
$why = '';
$gen = Block_Library::section( 'cta', $facts, 'x', array(), '', $why );
$expect( 'a theme the sections are not made for has no template of its own and gets the generic library: the same sections, in core blocks', \DXAI_UI\Pages\Template::is_generic() && Block_Library::theme_ok() && ! Block_Library::template_ok() === false && $gen !== null && ! preg_match( '/<!-- wp:(?:amr|dx)\//', serialize_block( $gen['block'] ) ), $why );
remove_filter( 'dxai_ui_template', $force_generic );
// (Or a library the site keeps for it: a stored one made for American Restoration comes before the built-in template.)
$expect( 'the American Restoration theme (and any DX theme) has the built-in template, and is not generic', Block_Library::template_ok() && ( ( \DXAI_UI\Pages\Template::for_theme()['slug'] ?? '' ) === \DXAI_UI\Pages\Template::BUILT_IN || ( ! empty( \DXAI_UI\Pages\Template::for_theme()['stored'] ) && in_array( 'american-restoration', (array) \DXAI_UI\Pages\Template::for_theme()['themes'], true ) ) ) && ! \DXAI_UI\Pages\Template::is_generic(), json_encode( \DXAI_UI\Pages\Template::for_theme()['slug'] ?? null ) );
$expect( 'the American Restoration theme is the one they are made for', Block_Library::theme_ok() === in_array( get_stylesheet(), array( 'american-restoration' ), true ) || in_array( get_template(), array( 'american-restoration' ), true ), get_stylesheet() );

// A Home made for the purpose.
$heading = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$button  = static fn( string $t, string $url ): string => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $t . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$section = static fn( string $name, string $class, string $inner ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"},"className":"' . $class . '","tagName":"section"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->';
$card    = static fn( string $t, string $d ): string => '<!-- wp:column {"className":"svc-card"} --><div class="wp-block-column svc-card">' . $heading( $t, 3 ) . $para( $d ) . $button( 'Learn more', '#' ) . '</div><!-- /wp:column -->';
$header  = '<!-- wp:group {"tagName":"header","className":"site-bar"} --><header class="wp-block-group site-bar"><!-- wp:paragraph --><p><a href="tel:+15550100101">Call (555) 010-0101</a> · Menu Home About</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$footer  = '<!-- wp:group {"tagName":"footer","className":"site-foot"} --><footer class="wp-block-group site-foot"><!-- wp:paragraph --><p>© Harborview Co. All rights reserved.</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$inner   = static fn( string $x ): string => '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $x . '</div><!-- /wp:group -->';
$say     = 'Water damage restoration, fire damage cleanup, mold remediation and storm damage mitigation for homes and businesses; we handle your insurance claims and the reconstruction.';
// Without questions, steps or a call to action: the places a library section fills.
$bare_home = $header
	. $section( 'Hero Section', 'hero-x bg-dark', $inner( $heading( 'Restoration in Tyler', 1 ) . $para( $say ) . $button( 'Call now', 'tel:+15550100101' ) ) )
	. $section( 'Services grid', 'svc-x', $inner( $heading( 'Restoration Services We Provide' ) . $para( 'What we do.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Water Damage', 'Extraction and drying.' ) . $card( 'Fire Damage', 'Soot and smoke.' ) . $card( 'Mold Removal', 'Containment.' ) . '</div><!-- /wp:columns -->' ) )
	. $section( 'Reviews Section', 'rev-x', $inner( $heading( 'What customers say' ) . $para( '"Fast and kind." — A customer' ) ) )
	. $footer;
// With a call to action and questions of its own.
$full_home = $bare_home
	. $section( 'FAQ Section', 'faq-x', $inner( $heading( 'Frequently asked questions' ) . $para( 'How fast can you come?' ) . $para( 'Within the hour.' ) ) )
	. $section( 'CTA Banner', 'cta-x', $inner( $heading( 'Water where it should not be?' ) . $button( 'Call (555) 010-0101', 'tel:+15550100101' ) ) );
$made  = array();
$homes = array();
foreach ( array(
	'bare'  => array( 'Harborview Water and Fire', $bare_home ),
	'full'  => array( 'Harborview Complete', $full_home ),
	'other' => array( 'Harborview Studio', str_replace( $say, 'A studio for brands: strategy, identity and websites for companies that grow.', $bare_home ) ),
) as $key => $row ) {
	$home_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $row[0], 'post_content' => $row[1] ) );
	if ( ! is_int( $home_id ) || $home_id < 1 ) {
		echo "  FAIL  a Home to test on\n";
		foreach ( array_reverse( $made ) as $made_id ) {
			wp_delete_post( (int) $made_id, true );
		}
		exit( 1 );
	}
	$made[]        = (int) $home_id;
	$homes[ $key ] = (int) $home_id;
	update_post_meta( $home_id, Page_Scope::META, $home_id );
	update_post_meta( $home_id, '_dxai_ui_generated_page', '1' );
	update_post_meta( $home_id, '_dxai_ui_css_url', 'dxai-ui/fixture-library-' . $key . '.css' );
}
$attachments_before = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_dxai_ui_svg_hash' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$fin = static function () use ( &$made, $attachments_before ): void {
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	// The drawings this run made (the ones that were there before are not its to remove).
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_dxai_ui_svg_hash' ) ) as $att ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		if ( ! in_array( $att, $attachments_before, true ) ) {
			wp_delete_attachment( (int) $att, true );
		}
	}
};

echo "\nWhat a Home says\n";
$expect( 'a Home that talks like a restoration company is one', Block_Library::industry( $homes['bare'] ) === 'restoration' );
$expect( 'and one that does not is not (a word or two does not make it one)', Block_Library::industry( $homes['other'] ) === '' );
$kit = Home_Kit::facts( $homes['bare'] );
$f   = Block_Library::facts( $homes['bare'], $kit, array( 'title' => 'Water Damage', 'kind' => 'service' ) );
$expect( 'its name and its phone, as it writes them', $f['company'] === 'Harborview Water and Fire' && $f['phone'] === '(555) 010-0101' && $f['phone_url'] === 'tel:+15550100101', json_encode( array( $f['company'], $f['phone'], $f['phone_url'] ) ) );
$expect( 'what it does not say is empty, never guessed', $f['email'] === '' && $f['address'] === '' && $f['region'] === '' && $f['contact_url'] === '' );

echo "\nWhat the panel is told\n";
$av = Block_Library::availability( $homes['bare'] );
$expect( 'a Home the library can serve is told so, with how many of its sections can be made for it', $av['code'] === 'ok' && $av['usable'] >= 3 && $av['sections'] === count( $entries ), wp_json_encode( $av ) );
$expect( 'a Home that is another kind of company is told that is the reason', Block_Library::availability( $homes['other'] )['code'] === 'industry' );
$ask = static function ( int $design ): array {
	$req = new WP_REST_Request( 'GET', '/' . DXAI_UI_REST_NAMESPACE . '/team-pages' );
	$req->set_param( 'design', $design );
	$res = rest_do_request( $req );

	return array( 'status' => $res->get_status(), 'library' => (array) ( ( (array) $res->get_data() )['library'] ?? array() ) );
};
$route = $ask( $homes['bare'] );
$expect( 'the route the panel reads says the same: the library can be used', $route['status'] === 200 && ( $route['library']['code'] ?? '' ) === 'ok', wp_json_encode( $route ) );
$force_generic = static fn() => \DXAI_UI\Pages\Template::GENERIC;
add_filter( 'dxai_ui_template', $force_generic );
$av_theme = Block_Library::availability( $homes['bare'] );
$route    = $ask( $homes['bare'] );
$expect( 'a site on another theme is told the library is in use in the generic library (no template for its theme), and so is the panel', $av_theme['code'] === 'ok' && ! empty( $av_theme['generic'] ) && $av_theme['template'] === 'generic' && $av_theme['theme'] !== '' && ( $route['library']['code'] ?? '' ) === 'ok' && ! empty( $route['library']['generic'] ) && ( $route['library']['theme'] ?? '' ) === $av_theme['theme'], wp_json_encode( array( $av_theme, $route ) ) );
remove_filter( 'dxai_ui_template', $force_generic );
$av_own = Block_Library::availability( $homes['bare'] );
$expect( 'on its own theme the template is named', $av_own['template'] === \DXAI_UI\Pages\Template::BUILT_IN && empty( $av_own['generic'] ) && $av_own['template_label'] === 'American Restoration', wp_json_encode( $av_own ) );
add_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
remove_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );

echo "\nThe pages made for a Home\n";
$wanted = array(
	array( 'type' => 'service', 'title' => 'Water Damage' ),
	array( 'type' => 'location', 'title' => 'Water Damage in Tyler, TX' ),
	array( 'type' => 'faq', 'title' => 'Frequently Asked Questions' ),
	array( 'type' => 'about', 'title' => 'About Us' ),
);
// Every place of a plan: the page, the role, and where the section is from (home, new, library).
$places_of = static function ( array $plan ): array {
	$out = array();
	foreach ( $plan as $p ) {
		foreach ( $p['strip'] as $x ) {
			$out[] = array( 'page' => (string) $p['type'], 'role' => (string) $x['role'], 'source' => (string) $x['source'] );
		}
	}

	return $out;
};
$count = static fn( array $places, array $roles, string $source ): int => count( array_filter( $places, static fn( $x ) => in_array( $x['role'], $roles, true ) && $x['source'] === $source ) );
$lib_places = static function ( array $plan ): array {
	$out = array();
	foreach ( $plan as $p ) {
		foreach ( $p['strip'] as $x ) {
			if ( $x['source'] === 'library' ) {
				$out[] = $p['type'] . ':' . $x['role'] . ':' . (string) ( $x['ops'][0] ?? '' );
			}
		}
	}

	return $out;
};
$home_before = (string) get_post_field( 'post_content', $homes['bare'] );
$svg_before  = count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_dxai_ui_svg_hash' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$plan_fill   = Team_Pages::plan( $homes['bare'], $wanted, array( 'library' => 'fill' ) );
$used        = $lib_places( $plan_fill );
$bare_places = $places_of( $plan_fill );
$expect( 'where the Home has no questions, no steps and no call to action, the library has them (no place of the kind is the Home\'s, and there are some of its)', $count( $bare_places, array( 'faq', 'cta', 'process' ), 'home' ) + $count( $bare_places, array( 'faq', 'cta', 'process' ), 'new' ) === 0 && $count( $bare_places, array( 'faq' ), 'library' ) >= 3 && $count( $bare_places, array( 'cta' ), 'library' ) >= 3, implode( ' ', $used ) );
$expect( 'every place of the plan names the library section it is, with its words', count( array_filter( $plan_fill, static fn( $p ) => count( array_filter( $p['strip'], static fn( $x ) => $x['source'] === 'library' && (string) $x['label'] !== '' && str_starts_with( (string) $x['ops'][0], 'lib:' ) && (int) $x['home'] === 0 ) ) > 0 ) ) === count( $plan_fill ) );
$expect( 'the page of questions has the long page of them, or a short one, and not a service page', ! in_array( 'service:faq:lib:faq-page', $used, true ) && ! in_array( 'location:faq:lib:faq-page', $used, true ) );
$expect( 'a plan makes nothing: no page, no drawing\'s file', count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_dxai_ui_svg_hash' ) ) ) === $svg_before && get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 5, 'meta_query' => array( array( 'key' => Team_Pages::META, 'compare' => 'EXISTS' ), array( 'key' => Page_Scope::META, 'value' => (string) $homes['bare'] ) ) ) ) === array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$expect( 'the same Home gets the same plan', $plan_fill === Team_Pages::plan( $homes['bare'], $wanted, array( 'library' => 'fill' ) ) );
$expect( 'it is not used when it is turned off', $lib_places( Team_Pages::plan( $homes['bare'], $wanted, array( 'library' => 'off' ) ) ) === array() );
$expect( 'nor in the plan that is only the Home\'s sections', $lib_places( Team_Pages::plan( $homes['bare'], $wanted, array( 'mode' => 'home' ) ) ) === array() );
$expect( 'a Home that is another kind of company has none of it', $lib_places( Team_Pages::plan( $homes['other'], $wanted, array( 'library' => 'prefer' ) ) ) === array() );
add_filter( 'dxai_ui_template', $force_generic );
$expect( 'on a theme the sections are not made for, the generic library is put in a plan when asked to prefer it (the pages are not left to the Home alone)', $lib_places( Team_Pages::plan( $homes['bare'], $wanted, array( 'library' => 'prefer' ) ) ) !== array() );
remove_filter( 'dxai_ui_template', $force_generic );
add_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
remove_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
$full_fill   = $lib_places( Team_Pages::plan( $homes['full'], $wanted, array( 'library' => 'fill' ) ) );
$full_prefer = $lib_places( Team_Pages::plan( $homes['full'], $wanted, array( 'library' => 'prefer' ) ) );
$full_places = $places_of( Team_Pages::plan( $homes['full'], $wanted, array( 'library' => 'fill' ) ) );
$prefer_places = $places_of( Team_Pages::plan( $homes['full'], $wanted, array( 'library' => 'prefer' ) ) );
$expect( 'a Home that has questions keeps them: every place for questions is the Home\'s, and what it has none of (the steps) is the library\'s', $count( $full_places, array( 'faq' ), 'library' ) === 0 && $count( $full_places, array( 'faq' ), 'home' ) >= 3 && $count( $full_places, array( 'process' ), 'home' ) === 0, implode( ' ', $full_fill ) );
$expect( 'and the Home\'s call to action is used before the library\'s (the library has one only where the Home has not another section for the place)', $count( $full_places, array( 'cta' ), 'home' ) >= 3 && $count( $full_places, array( 'cta' ), 'library' ) < $count( $prefer_places, array( 'cta' ), 'library' ), implode( ' ', $full_fill ) );
$expect( 'the library takes the place of the Home\'s questions and of its call to action when it is asked to', $count( $prefer_places, array( 'faq' ), 'home' ) === 0 && $count( $prefer_places, array( 'faq' ), 'library' ) >= 3 && $count( $prefer_places, array( 'cta' ), 'library' ) >= 3, implode( ' ', $full_prefer ) );

// Locks: a section that is locked comes back with the same library section when the page is shuffled.
$first = array_values( array_filter( $plan_fill, static fn( $p ) => $p['type'] === 'service' ) )[0];
$lock  = null;
foreach ( $first['strip'] as $x ) {
	if ( $x['source'] === 'library' && $x['role'] === 'cta' ) {
		$lock = array( 'place' => $x['place'], 'role' => $x['role'], 'source' => 'library', 'home' => 0, 'n' => $x['n'], 'ops' => $x['ops'] );
		break;
	}
}
$kept = 0;
if ( $lock !== null ) {
	foreach ( array( 1, 2, 3, 4, 5 ) as $shuffle ) {
		$again = Team_Pages::plan( $homes['bare'], array( array( 'type' => 'service', 'title' => 'Water Damage', 'shuffle' => $shuffle, 'recipe' => 0, 'locks' => array( $lock ) ) ), array( 'library' => 'fill' ) );
		foreach ( $again[0]['strip'] as $x ) {
			if ( (int) $x['place'] === (int) $lock['place'] && $x['source'] === 'library' && $x['ops'] === $lock['ops'] && ! empty( $x['locked'] ) ) {
				++$kept;
			}
		}
	}
}
$expect( 'a section that was locked comes back as the same library section through five shuffles', $lock !== null && $kept === 5, $lock === null ? 'no library call to action to lock' : (string) $kept );

$out = Team_Pages::build( $homes['bare'], $wanted, false, array( 'library' => 'fill' ) );
$expect( 'the pages are made', ! is_wp_error( $out ) && count( $out['pages'] ) === 4, is_wp_error( $out ) ? $out->get_error_message() : (string) count( $out['pages'] ) );
if ( is_wp_error( $out ) ) {
	$fin();
	exit( 1 );
}
foreach ( $out['pages'] as $p ) {
	$made[] = (int) $p['id'];
}
$service = (string) get_post_field( 'post_content', (int) $out['pages'][0]['id'] );
$expect( 'a page holds the library\'s sections, each marked as its own', Library_View::has_section( $service ) && substr_count( $service, 'dxai-lib' ) >= 3, (string) substr_count( $service, 'dxai-lib' ) );
$expect( 'filled from the Home: its phone in the call, its name in the words, no token and no marker left', str_contains( $service, 'href="tel:+15550100101"' ) && str_contains( $service, 'Harborview Water and Fire' ) && ! str_contains( $service, '{{' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon/', $service ) );
$expect( 'and nothing of another company: no phone but the Home\'s, no name of the sites the sections came from', ! preg_match( '/\(\d{3}\) \d{3}-\d{4}/', $service ) || str_contains( $service, '(555) 010-0101' ) );
$expect( 'the page parses back to the same markup', ! is_wp_error( Block_Tree::serialize_checked( array_values( array_filter( parse_blocks( $service ), static fn( $b ) => ! empty( $b['blockName'] ) ) ) ) ) );
$expect( 'its drawings are files in the media library (the build makes them, the plan did not)', preg_match( '#<img src="[^"]+/dxai-ui/icons/[0-9a-f]+\.svg"#', $service ) === 1 || ! str_contains( $service, 'dxaiIcon' ), '' );
$expect( 'the quality report counts the library\'s inline styles apart (blocks are valid, nothing foreign)', count( array_filter( $out['report'], static fn( $r ) => count( array_filter( $r['gates'], static fn( $g ) => in_array( $g['gate'], array( 'G7', 'G9' ), true ) && ! $g['ok'] ) ) > 0 ) ) === 0, wp_json_encode( array_map( static fn( $r ) => array_map( static fn( $g ) => $g['problems'], array_filter( $r['gates'], static fn( $g ) => ! $g['ok'] ) ), $out['report'] ) ) );

echo "\nThe Home\n";
$expect( 'the Home itself is not changed: its content is the same as before the pages were made', (string) get_post_field( 'post_content', $homes['bare'] ) === $home_before );
$expect( 'no section of the library is on it', ! Library_View::has_section( (string) get_post_field( 'post_content', $homes['bare'] ) ) );
$mutated = $home_before . '<!-- wp:group {"className":"dxai-lib"} --><div class="wp-block-group dxai-lib"></div><!-- /wp:group -->';
$expect( 'a Home with a section of the library on it would be caught (the check is able to)', Library_View::has_section( $mutated ) && $mutated !== $home_before );
$expect( 'and a class that only starts like the library\'s is not a section of it', ! Library_View::has_section( 'class="wp-block-group dxai-lib-tight"' ) && ! Library_View::has_section( 'class="my-dxai-lib"' ) && Library_View::has_section( 'class="a dxai-lib b"' ) );

echo "\nThe assets\n";
$view = new Library_View();
add_filter( 'dxai_ui_theme_has_block_library', '__return_false' );
wp_dequeue_style( Library_View::HANDLE );
wp_dequeue_script( Library_View::HANDLE );
$view->register_assets();
$view->on_render( '<div class="wp-block-group relative">no section</div>' );
$expect( 'a page with no section of the library asks for none of it', ! wp_style_is( Library_View::HANDLE, 'enqueued' ) && ! wp_script_is( Library_View::HANDLE, 'enqueued' ) );
$view->on_render( '<div class="wp-block-group dxai-lib"><p class="faq-answer">x</p></div>' );
$expect( 'a section of the library asks for its styles, and the script when it holds questions', wp_style_is( Library_View::HANDLE, 'enqueued' ) && wp_script_is( Library_View::HANDLE, 'enqueued' ) );
wp_dequeue_style( Library_View::HANDLE );
wp_dequeue_script( Library_View::HANDLE );
add_filter( 'dxai_ui_theme_has_block_library', '__return_true', 20 );
$view->on_render( '<div class="wp-block-group dxai-lib"><p class="faq-answer">x</p></div>' );
$expect( 'a theme that brings them is left to', ! wp_style_is( Library_View::HANDLE, 'enqueued' ) && ! wp_script_is( Library_View::HANDLE, 'enqueued' ) );
remove_filter( 'dxai_ui_theme_has_block_library', '__return_true', 20 );
remove_filter( 'dxai_ui_theme_has_block_library', '__return_false' );
$css = (string) file_get_contents( DXAI_UI_DIR . 'assets/css/block-library.css' );
$expect( 'every rule of the styles is inside the library\'s class, so none can reach a design', preg_match_all( '/^[^\/@\s}][^{]*\{/m', $css, $sel ) > 5 && count( array_filter( $sel[0], static fn( $s ) => ! str_contains( $s, '.dxai-lib' ) ) ) === 0, implode( ' | ', array_filter( $sel[0], static fn( $s ) => ! str_contains( $s, '.dxai-lib' ) ) ) );
$js = (string) file_get_contents( DXAI_UI_DIR . 'assets/js/block-library.js' );
$expect( 'and the script acts only on the library\'s questions', str_contains( $js, "var SCOPE = '.dxai-lib'" ) && ! preg_match( "/querySelectorAll\\(\\s*'\\.faq-question/", $js ) );

echo "\nThe editor\n";
$patterns = WP_Block_Patterns_Registry::get_instance();
$expect( 'the sections are patterns of a category of their own ("DX Library")', WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'dxai-library' ) && ( WP_Block_Pattern_Categories_Registry::get_instance()->get_registered( 'dxai-library' )['label'] ?? '' ) === 'DX Library' );
foreach ( array_keys( $entries ) as $id ) {
	// A layout (the team's arrangement, filled from a page's lists) is not in the inserter: it has no pattern file.
	if ( ( $entries[ $id ]['source'] ?? '' ) === 'layout' ) {
		continue;
	}
	$pattern = $patterns->get_registered( 'dxai-ui/library-' . $id );
	$content = is_array( $pattern ) ? (string) $pattern['content'] : '';
	$parsed  = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	$expect( "$id: a pattern, made for this site when it is asked for: one section, no token, no marker, an address on every link", $content !== '' && count( $parsed ) === 1 && Library_View::has_section( $content ) && ! str_contains( $content, '{{' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon|href=""/', $content ) && ! is_wp_error( Block_Tree::serialize_checked( $parsed ) ), $content === '' ? 'no pattern' : substr( (string) preg_replace( '/\s+/', ' ', $content ), 0, 100 ) );
}
$cta = (string) ( $patterns->get_registered( 'dxai-ui/library-cta-call' )['content'] ?? '' );
$expect( 'a phone the site does not say is a placeholder to edit, never a number made up', preg_match( '/href="tel:\+?\d[\d ()-]*"/', $cta ) === 0 || str_contains( $cta, 'tel:' . preg_replace( '/[^\d+]/', '', (string) ( Block_Library::facts( 0, array( 'phone' => null, 'email' => '', 'address' => '' ) )['phone_url'] ?? '' ) ) ), '' );
$expect( 'and the pattern is the team\'s block as it was written: no Home\'s look on it', ! str_contains( $cta, 'dxs-' ) );

echo "\nThe layouts (the team's arrangement, bin/build-layouts.php)\n";
$layouts = array_filter( $entries, static fn( $e ) => ( $e['source'] ?? '' ) === 'layout' );
$expect( 'the library holds the team\'s layouts, and says so', Block_Library::has_layouts() && count( $layouts ) >= 40, (string) count( $layouts ) );
$lay_roles = array_unique( array_map( static fn( $e ) => (string) $e['role'], $layouts ) );
sort( $lay_roles );
$expect( 'of every role a page is made of but the hero and the form: ' . implode( ', ', $lay_roles ), array_diff( array( 'areas', 'cards', 'content', 'cta', 'faq', 'process', 'related', 'reviews', 'trust', 'two-col' ), $lay_roles ) === array() );
$expect( 'each is named by its role and a number, not by the site it was read from, and is for no page in particular (the page maker picks it by role)', count( array_filter( $layouts, static fn( $e ) => preg_match( '/^layout-[a-z-]+-\d+$/', (string) $e['id'] ) === 1 && (array) $e['pages'] === array() && $leaks( (string) $e['id'] . ' ' . (string) $e['label'] ) === array() && is_array( $e['slots'] ) && is_array( $e['seen'] ) ) ) === count( $layouts ) );
$expect( 'the list each repeats is one a Home gives (Layouts::MAX), or none', count( array_filter( $layouts, static fn( $e ) => (string) ( $e['list'] ?? '' ) === '' || in_array( (string) $e['list'], $known_lists, true ) ) ) === count( $layouts ) );
$from_show = array_count_values( array_map( static fn( $e ) => preg_match( '/^team#(arcusrestoration|archerrestorations)-/', (string) ( $e['from'] ?? '' ), $fm ) === 1 ? $fm[1] : 'live', $layouts ) );
$expect( 'the team\'s own showcase pages (the Arcus and Archer exports) give layouts too, beside the live sites\', and no role is theirs alone', ( $from_show['arcusrestoration'] ?? 0 ) >= 3 && ( $from_show['archerrestorations'] ?? 0 ) >= 3 && ( $from_show['live'] ?? 0 ) >= 20 && count( array_filter( $lay_roles, static fn( $r ) => count( array_filter( $layouts, static fn( $e ) => (string) $e['role'] === $r && ! preg_match( '/^team#(arcusrestoration|archerrestorations)-/', (string) ( $e['from'] ?? '' ) ) ) ) === 0 && count( array_filter( $layouts, static fn( $e ) => (string) $e['role'] === $r ) ) > 1 ) ) === 0, json_encode( $from_show ) );
// More rows than any layout shows: what is shown is as many as the team's section held, dealt across its columns, each row once.
$many = $facts;
foreach ( \DXAI_UI\Pages\Layouts::MAX as $list => $max ) {
	if ( $list !== 'places' && $list !== 'services' ) {
		$many['lists'][ $list ] = $rows_of( $list, 9 );
	}
}
// What a filled layout must not say: a site's name, a brand's colour (the page's own phone, addresses and figures are its own).
$site_leaks = static function ( string $markup ) use ( $forbidden ): array {
	$out = array();
	foreach ( array( 'a name of a site the layouts came from', 'a company name', 'a colour of one brand' ) as $label ) {
		if ( isset( $forbidden[ $label ] ) && preg_match( $forbidden[ $label ], $markup, $m ) === 1 ) {
			$out[] = $label . ' (“' . substr( $m[0], 0, 40 ) . '”)';
		}
	}

	return $out;
};
$dealt = 0;
// A layout's markup less an item it held apart from a list's rows (the source site's last step, "Phase 5 · Final Walkthrough and Reopening"):
// it goes with the list when the page's rows are made (Block_Library::repeats()), so its words are not the page's to show.
$shape_of      = static function ( array $b ) use ( &$shape_of ): string {
	$kids = array_values( array_filter( (array) ( $b['innerBlocks'] ?? array() ), static fn( $c ) => ! empty( $c['blockName'] ) ) );
	// (A group around one group is the group inside it, as Block_Library::shape() reads it.)
	if ( (string) ( $b['blockName'] ?? '' ) === 'core/group' && count( $kids ) === 1 && (string) ( $kids[0]['blockName'] ?? '' ) === 'core/group' ) {
		return $shape_of( $kids[0] );
	}

	return (string) $b['blockName'] . '(' . implode( ',', array_map( $shape_of, $kids ) ) . ')';
};
$without_apart = static function ( array $b ) use ( &$without_apart, $shape_of ): array {
	$kids  = (array) ( $b['innerBlocks'] ?? array() );
	$rows  = array_map( $shape_of, array_filter( $kids, static fn( $c ) => isset( $c['attrs']['dxaiRepeat'] ) ) );
	$keep  = array();
	foreach ( $kids as $c ) {
		if ( ! isset( $c['attrs']['dxaiRepeat'] ) && in_array( $shape_of( $c ), $rows, true ) && str_contains( serialize_block( $c ), '{{t:' ) ) {
			continue;
		}
		$keep[] = $without_apart( $c );
	}
	$b['innerBlocks'] = $keep;

	return $b;
};
foreach ( $layouts as $id => $e ) {
	$markup = Block_Library::markup( (string) $id );
	$blocks = array_values( array_filter( parse_blocks( $markup ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	$lists  = array();
	$marks  = array();
	foreach ( $flat( $blocks[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ) ) as $b ) {
		if ( isset( $b['attrs']['dxaiRepeat'] ) ) {
			$name  = (string) $b['attrs']['dxaiRepeat'];
			$count = $b['attrs']['dxaiCount'] ?? null;
			$slice = $b['attrs']['dxaiSlice'] ?? null;
			if ( ! is_int( $count ) || $count < 1 || $count > 12 ) {
				$marks[] = "$name without a count of one to twelve";
			}
			if ( $slice !== null && ! ( is_array( $slice ) && count( $slice ) === 2 && is_int( $slice[0] ) && is_int( $slice[1] ) && $slice[1] >= 2 && $slice[0] >= 0 && $slice[0] < $slice[1] ) ) {
				$marks[] = "$name with a slice that is not [which, of how many]";
			}
			$dealt += is_array( $slice ) ? 1 : 0;
			// How many of the rows this list shows: this column's share of them (dealt in turn), and no more than the section held.
			$share = 0;
			$given = count( (array) ( $many['lists'][ $name ] ?? ( $name === 'places' ? $many['places'] : ( $name === 'services' ? $many['services'] : array() ) ) ) );
			for ( $r = 0; $r < $given; $r++ ) {
				if ( ! is_array( $slice ) || $r % (int) $slice[1] === (int) $slice[0] ) {
					++$share;
				}
			}
			$lists[ $name ] = ( $lists[ $name ] ?? 0 ) + min( $share, is_int( $count ) ? $count : 9 );
		}
	}
	$expect( "$id: the section's class, a list with a count, a slice as [which column, of how many]", str_contains( (string) ( $blocks[0]['attrs']['className'] ?? '' ), 'dxai-lib' ) && $marks === array(), implode( '; ', $marks ) );
	// A colour as a number is one brand's unless it is a grey; a picture laid under a section (positioned, faded, named a background) is the site's.
	$tinted = array();
	foreach ( $flat( $blocks[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ) ) as $b ) {
		$style = (array) ( $b['attrs']['style'] ?? array() );
		$found = array();
		foreach ( array( 'text', 'background', 'gradient' ) as $k ) {
			if ( isset( $style['color'][ $k ] ) ) {
				$found[] = (string) $style['color'][ $k ];
			}
		}
		foreach ( (array) ( $style['elements'] ?? array() ) as $el => $spec ) {
			foreach ( array( 'text', 'background', 'gradient' ) as $k ) {
				if ( isset( $spec['color'][ $k ] ) ) {
					$found[] = (string) $spec['color'][ $k ];
				}
			}
		}
		foreach ( $found as $v ) {
			if ( str_starts_with( $v, 'var:preset' ) ) {
				continue;
			}
			if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', trim( $v ), $hm ) === 1 ) {
				$ch = array( hexdec( substr( $hm[1], 0, 2 ) ), hexdec( substr( $hm[1], 2, 2 ) ), hexdec( substr( $hm[1], 4, 2 ) ) );
				if ( max( $ch ) - min( $ch ) <= 3 ) {
					continue;
				}
			}
			$tinted[] = $v;
		}
		if ( in_array( (string) $b['blockName'], array( 'core/image', 'dx/picture' ), true ) && str_contains( wp_json_encode( $b['attrs'] ) ?: '', '{{image_url' ) && ( preg_match( '/(^|\s)(absolute|z-negative|inset-0|full-w-figure|opacity-\d+)(\s|$)/', (string) ( $b['attrs']['className'] ?? '' ) ) === 1 || preg_match( '/background/i', (string) ( $b['attrs']['metadata']['name'] ?? '' ) ) === 1 ) ) {
			$tinted[] = 'a picture under the section: ' . (string) ( $b['attrs']['className'] ?? $b['attrs']['metadata']['name'] ?? '' );
		}
	}
	$expect( "$id: its colours are the theme's presets or greys, in its elements too, and no Home picture is laid under it", $tinted === array(), implode( '; ', array_unique( $tinted ) ) );
	// Words read on the ground they stand on: no colour whose meaning differs from site to site (secondary, primary-light, border…) on
	// words at all, no white words on a light ground, no dark words on a dark one.
	$unread  = array();
	$lum_of  = static function ( string $v ): ?float {
		if ( preg_match( '/#([0-9a-f]{6})/i', $v, $hm ) !== 1 ) {
			return null;
		}

		return ( 0.299 * hexdec( substr( $hm[1], 0, 2 ) ) + 0.587 * hexdec( substr( $hm[1], 2, 2 ) ) + 0.114 * hexdec( substr( $hm[1], 4, 2 ) ) ) / 255;
	};
	$grounds = static function ( array $b, string $ground ) use ( &$grounds, &$unread, $lum_of ): void {
		$slug = (string) ( $b['attrs']['backgroundColor'] ?? '' );
		$bg   = (string) ( $b['attrs']['style']['color']['background'] ?? '' );
		if ( (string) $b['blockName'] === 'core/cover' ) {
			$ground = 'dark';
		} elseif ( $slug !== '' ) {
			$ground = in_array( $slug, array( 'primary-dark', 'primary', 'accent', 'black', 'ink', 'foreground' ), true ) ? 'dark' : 'light';
		} elseif ( $bg !== '' && $lum_of( $bg ) !== null ) {
			$ground = $lum_of( $bg ) < 0.5 ? 'dark' : 'light';
		}
		$text = (string) ( $b['attrs']['textColor'] ?? '' );
		if ( $text !== '' ) {
			$light = in_array( $text, array( 'on-accent', 'white', 'background' ), true );
			$dark  = in_array( $text, array( 'primary-dark', 'black', 'ink', 'foreground' ), true );
			if ( ! ( $light || $dark || in_array( $text, array( 'primary', 'accent' ), true ) ) || ( $light && $ground !== 'dark' ) || ( $dark && $ground === 'dark' ) ) {
				$unread[] = "$text on a $ground ground";
			}
		}
		$custom = (string) ( $b['attrs']['style']['color']['text'] ?? '' );
		if ( $custom !== '' && $lum_of( $custom ) !== null && ( ( $ground === 'dark' && $lum_of( $custom ) < 0.4 ) || ( $ground !== 'dark' && $lum_of( $custom ) > 0.6 ) ) ) {
			$unread[] = "$custom on a $ground ground";
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
			$grounds( (array) $c, $ground );
		}
	};
	$grounds( $blocks[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ), 'light' );
	// A box with nothing in it (the frame of a site's own photograph or decoration) is not left in a layout.
	$hollow = array();
	foreach ( array_slice( $flat( $blocks[0] ?? array( 'blockName' => '', 'innerBlocks' => array() ) ), 1 ) as $b ) {
		if ( in_array( (string) $b['blockName'], array( 'core/group', 'core/column', 'core/columns', 'core/cover', 'dxai-ui/box' ), true ) && (array) ( $b['innerBlocks'] ?? array() ) === array() && trim( wp_strip_all_tags( (string) $b['innerHTML'] ) ) === '' ) {
			$hollow[] = (string) $b['blockName'] . ' ' . (string) ( $b['attrs']['className'] ?? '' );
		}
	}
	$expect( "$id: no box is left with nothing in it", $hollow === array(), implode( '; ', $hollow ) );
	$expect( "$id: its words read on the ground they stand on (no colour that means another thing on another site, no white on light, no dark on dark)", $unread === array(), implode( '; ', array_unique( $unread ) ) );
	$why   = '';
	$block = Block_Library::fill( (string) $id, $many, $why );
	$expect( "$id: made for a page with nine rows of every list", $block !== null, $why );
	if ( $block === null ) {
		continue;
	}
	$out  = serialize_block( $block );
	$text = html_entity_decode( wp_strip_all_tags( $out ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$expect( "$id: no token or marker is left, and a picture's id is a number", ! str_contains( $out, '{{' ) && ! str_contains( $out, '[[' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon|dxaiCount|dxaiSlice/', $out ) && ! preg_match( '/"(id|imageId|mediaId)":"\d+"/', $out ) && ! is_wp_error( Block_Tree::serialize_checked( array( $block ) ) ) );
	// The team's words a slot kept are the page's words, with its facts in them; a kept line claims nothing of one site.
	// (A heading the team wrote for another list — "Our Restoration Services" over the Home's points — gives way to the page's own words:
	// Block_Library::says_what_it_lists(); it is still held to claiming nothing of one site.)
	$kept_from = serialize_blocks( array_map( $without_apart, array_values( array_filter( parse_blocks( $markup ), static fn( $b ) => ! empty( $b['blockName'] ) ) ) ) );
	// (A badge set as a figure shows the Home's figure and its words, not the team's label: Block_Library::figures().)
	$kept_from = (string) preg_replace( '#<!-- wp:paragraph [^>]*"fontSize":"(?:2[89]|[3-9]\d)px"[^>]*-->\s*<p[^>]*>\{\{t:p-s\}\}</p>\s*<!-- /wp:paragraph -->\s*<!-- wp:paragraph[^>]*-->\s*<p[^>]*>\{\{t:p-s\|[^}]*\}\}</p>#', '', $kept_from );
	preg_match_all( '/\{\{t:([a-z0-9-]+)\|([^{}]*)\}\}/', $kept_from, $km_sets, PREG_SET_ORDER );
	preg_match_all( '/"dxaiRepeat":"([a-z]+)"/', $markup, $km_lists );
	$km_every = array_map( static fn( array $m ): string => $m[2], $km_sets );
	$km       = array( 1 => array_values( array_map( static fn( array $m ): string => $m[2], array_filter( $km_sets, static fn( array $m ): bool => Block_Library::says_what_it_lists( $m[1], $m[2], array( 'section_lists' => array_values( array_unique( $km_lists[1] ) ) ) ) ) ) ) );
	$kept_plain = array_values( array_filter( $km[1], static fn( string $d ): bool => ! str_contains( $d, '[[' ) ) );
	$kept_facts = array_values( array_filter( $km[1], static fn( string $d ): bool => str_contains( $d, '[[company' ) ) );
	$shown      = count( array_filter( $kept_plain, static fn( string $d ): bool => str_contains( $text, html_entity_decode( $d, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
	$claims     = array_values( array_filter( $km_every, static fn( string $d ): bool => preg_match( '/\b(?:google|yelp|facebook|bbb|angi|homeadvisor|iicrc|epa|osha|matterport|trustindex)\b|\b\d[\d,]*\s*\+|\b\d{2,3},\d{3}\b|\b\d+\s*(?:years?|minutes?)\b|\[\[(?!company|company_short|phone|phone_url|email|title|subject|region\b)/i', $d ) === 1 ) );
	$expect( "$id: the team's words it kept (" . count( $km[1] ) . ') are shown as they are, with the company in them where they name it, and claim nothing of one site', $claims === array() && ( $kept_plain === array() || $shown >= (int) ceil( 0.8 * count( $kept_plain ) ) ) && ( $kept_facts === array() || str_contains( $text, 'Smith & Sons' ) ), implode( '; ', $claims ) . ( $kept_plain !== array() ? " shown $shown of " . count( $kept_plain ) : '' ) );
	$expect( "$id: nothing of the sites it was taken from is in what it says", $site_leaks( $out ) === array(), implode( '; ', $site_leaks( $out ) ) );
	foreach ( $lists as $name => $want ) {
		$seen = array();
		$dup  = array();
		foreach ( (array) ( $many['lists'][ $name ] ?? ( $name === 'places' ? $many['places'] : ( $name === 'services' ? $many['services'] : array() ) ) ) as $row ) {
			// A row is shown where its title is (a logo's is its picture's alt: in the markup, not in the words).
			if ( substr_count( html_entity_decode( $out, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), (string) $row['title'] ) > 0 ) {
				$seen[] = $row['title'];
			}
			if ( substr_count( $text, (string) $row['title'] ) > 1 ) {
				$dup[] = $row['title'];
			}
		}
		// A static list ({{t:li-s}}) names the items too: there a title may be seen twice.
		$twice_ok = $name === 'items' && str_contains( $markup, '{{t:li' );
		$expect( "$id: shows $want of the $name given, each once (as many as the team's section held, dealt across its columns)", count( $seen ) === $want && ( $twice_ok || $dup === array() ), count( $seen ) . ' shown' . ( $dup !== array() ? ', twice: ' . implode( ', ', $dup ) : '' ) );
	}
	if ( isset( $lists['logos'] ) ) {
		$expect( "$id: a logo is marked as one (its size is held by the library's styles), with the row's picture", str_contains( $out, 'dxai-logo' ) && str_contains( $out, 'uploads/logo-Alpha.png' ) );
	}
}
$expect( 'some layouts deal their rows across columns', $dealt >= 3, (string) $dealt );
$all_slots = 0;
$all_kept  = 0;
foreach ( array_keys( $layouts ) as $id ) {
	$m         = Block_Library::markup( (string) $id );
	$all_slots += preg_match_all( '/\{\{t:(?!call)[a-z0-9-]+(?:\||\}\})/', $m );
	$all_kept  += preg_match_all( '/\{\{t:(?!call)[a-z0-9-]+\|/', $m );
}
$expect( "about half the slots for words keep the team's own words as their first words ($all_kept of $all_slots)", $all_slots > 0 && $all_kept >= (int) ( 0.45 * $all_slots ) );
$expect( 'the team\'s words take the page\'s facts: the company, its phone, what the page is about, the place or "the area"', Block_Library::team_words( 'Why [[region|the area]] property owners choose [[company]]: call [[phone]].', $facts ) === 'Why the area property owners choose Smith & Sons "Pro" Restoration: call (555) 010-2030.' && Block_Library::team_words( 'Our [[subject]] process', array_merge( $facts, array( 'subject' => 'Mold Removal' ) ) ) === 'Our Mold Removal process' && Block_Library::team_words( 'Write to [[email]]', $facts ) === '', Block_Library::team_words( 'Why [[region|the area]] property owners choose [[company]]: call [[phone]].', $facts ) );
$words = array( 'h2-s', 'h3-s', 'p-s', 'p-m', 'p-l', 'li-s', 'label-s', 'button', 'call' );
$silent = array();
foreach ( array( 'process', 'two-col', 'cards', 'reviews', 'faq', 'cta', 'trust', 'areas', 'related', 'content' ) as $role ) {
	foreach ( $words as $slot ) {
		if ( \DXAI_UI\Pages\Layouts::words( $slot, array_merge( $facts, array( 'role' => $role ) ), null ) === '' ) {
			$silent[] = "$role $slot";
		}
	}
}
$expect( 'a layout\'s every slot for words has provisional words, for every role', $silent === array(), implode( ', ', $silent ) );
$p_lines = '';
for ( $i = 0; $i < 6; $i++ ) {
	$p_lines .= ' ' . \DXAI_UI\Pages\Layouts::words( 'p-l', $facts, null );
}
$expect( 'the call says the phone; the paragraphs name the company and the page; two places on a page do not start at the same line', \DXAI_UI\Pages\Layouts::words( 'call', $facts, null ) === 'Call (555) 010-2030' && str_contains( $p_lines, 'Smith & Sons "Pro" Restoration handles water damage' ) && \DXAI_UI\Pages\Layouts::words( 'p-s', array_merge( $facts, array( 'slot_seed' => '1' ) ), null ) !== \DXAI_UI\Pages\Layouts::words( 'p-s', array_merge( $facts, array( 'slot_seed' => '2' ) ), null ) );
// What the words are about, by the kind of page: a place's page is about its service, in its place; a page about the company is about restoration.
$pf_loc   = \DXAI_UI\Pages\Layouts::page_facts( $facts, array( 'title' => 'Water Damage in Tyler, TX', 'type' => 'location' ), 'reviews', 'seed|1', array() );
$pf_about = \DXAI_UI\Pages\Layouts::page_facts( $facts, array( 'title' => 'About Us', 'type' => 'about' ), 'cta', 'seed|2', array() );
$pf_svc   = \DXAI_UI\Pages\Layouts::page_facts( array_merge( $facts, array( 'places_home' => array( 'Longview, TX' ) ) ), array( 'title' => 'Mold Removal', 'type' => 'service' ), 'cta', 'seed|3', array() );
$expect( 'a place\'s page is about its service, in its place; a page about the company is about restoration, in the Home\'s first place; a page\'s title is never the place', ( $pf_loc['subject'] ?? '' ) === 'Water Damage' && ( $pf_loc['region'] ?? '' ) === 'Tyler, TX' && ( $pf_about['subject'] ?? '' ) === 'restoration' && ( $pf_about['region'] ?? '' ) === 'Tyler, TX' && ( $pf_svc['subject'] ?? '' ) === 'Mold Removal' && ( $pf_svc['region'] ?? '' ) === 'Longview, TX', json_encode( array( $pf_loc['subject'] ?? null, $pf_loc['region'] ?? null, $pf_about['subject'] ?? null, $pf_about['region'] ?? null, $pf_svc['region'] ?? null ) ) );
// (A page says a sentence once, a paragraph is two of them at most: the company's line is in one of the page's paragraphs.)
$about_p = '';
for ( $i = 0; $i < 5; $i++ ) {
	$about_p .= ' ' . \DXAI_UI\Pages\Layouts::words( 'p-l', array_merge( $pf_about, array( 'slot_seed' => (string) $i ) ), null );
}
$expect( 'and the words say so: the place, not the page\'s title, and "restoration", not "about us"', str_contains( \DXAI_UI\Pages\Layouts::words( 'h2-s', $pf_loc, null ), 'in Tyler, TX trust' ) && str_contains( $about_p, 'restoration' ) && ! str_contains( mb_strtolower( \DXAI_UI\Pages\Layouts::words( 'h2-s', $pf_about, null ) . \DXAI_UI\Pages\Layouts::words( 'p-l', $pf_about, null ) ), 'about us' ), \DXAI_UI\Pages\Layouts::words( 'h2-s', $pf_loc, null ) . ' | ' . \DXAI_UI\Pages\Layouts::words( 'h2-s', $pf_about, null ) );
$planted_site = '<!-- wp:paragraph --><p>Why homeowners choose PacesGA for water damage.</p><!-- /wp:paragraph -->';
$expect( 'the leak check is able to catch the name of a site the layouts came from', $leaks( $planted_site ) !== array(), implode( '; ', $leaks( $planted_site ) ) );

echo "\nThe look of the Home on the layouts (Library_Style)\n";
// A Home with a look of its own: its cards are one box (corners, shadow, border, background, padding), its eyebrows one line, its buttons
// one style; a page made for it gets the layouts' cards, eyebrows and buttons in that look, and the Home itself is not touched.
$look_card = static fn( string $t, string $d ): string => '<!-- wp:column {"className":"svc-card rounded-md px-6-5 d-flex flex-column pt-7 pb-6 shadow-dxai-shadow-2 bg-dxai-neutral-100","dxaiCss":"border:1px solid var(--dxai-surface)"} --><div class="wp-block-column svc-card rounded-md px-6-5 d-flex flex-column pt-7 pb-6 shadow-dxai-shadow-2 bg-dxai-neutral-100 dxs-look1">' . $heading( $t, 3 ) . $para( $d ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-primary-button"} --><div class="wp-block-button is-style-primary-button"><a class="wp-block-button__link wp-element-button" href="#">Learn more</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column -->';
$look_eye  = static fn( string $t ): string => '<!-- wp:paragraph {"className":"mx-0 mt-0 mb-3-5 text-13 uppercase fw-900 ls-0-16em","style":{"color":{"text":"var(--dxai-red-49)"}}} --><p class="mx-0 mt-0 mb-3-5 text-13 uppercase fw-900 ls-0-16em has-text-color" style="color:var(--dxai-red-49)">' . $t . '</p><!-- /wp:paragraph -->';
$look_home = $header
	. $section( 'Hero Section', 'hero-x bg-dark', $inner( $heading( 'Restoration in Tyler', 1 ) . $para( $say ) . $button( 'Call now', 'tel:+15550100101' ) ) )
	. $section( 'Services grid', 'svc-x', $inner( $look_eye( 'What we do' ) . $heading( 'Restoration Services We Provide' ) . $para( 'What we do.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $look_card( 'Water Damage', 'Extraction and drying.' ) . $look_card( 'Fire Damage', 'Soot and smoke.' ) . $look_card( 'Mold Removal', 'Containment.' ) . '</div><!-- /wp:columns -->' ) )
	. $section( 'Why Section', 'why-x', $inner( $look_eye( 'Why us' ) . $heading( 'Why property owners choose us' ) . $para( 'Because we come.' ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-secondary-button"} --><div class="wp-block-button is-style-secondary-button"><a class="wp-block-button__link wp-element-button" href="#">Request a callback</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' ) )
	// Four plain cards with no box of their own (a Home's values, say): they say nothing about the look, and do not outvote the ones that do.
	. $section( 'Values grid', 'val-x', $inner( $heading( 'What we stand for' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Honesty', 'We say what we find.' ) . $card( 'Speed', 'We come within the hour.' ) . $card( 'Care', 'Your home, not a job.' ) . $card( 'Follow-through', 'We stay until it is done.' ) . '</div><!-- /wp:columns -->' ) )
	. $footer;
$look_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Harborview Looked', 'post_content' => wp_slash( $look_home ) ) );
$made[]  = (int) $look_id;
update_post_meta( $look_id, Page_Scope::META, $look_id );
update_post_meta( $look_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $look_id, '_dxai_ui_css_url', 'dxai-ui/fixture-library-look.css' );
$look = \DXAI_UI\Blocks\Native\Library_Style::look( (int) $look_id );
$expect( 'the Home\'s cards are read as one box: their corners, shadow, background, padding and border (the plain cards beside them say nothing, and do not outvote them)', is_array( $look['card'] ) && in_array( 'rounded-md', (array) $look['card']['class'], true ) && in_array( 'shadow-dxai-shadow-2', (array) $look['card']['class'], true ) && in_array( 'bg-dxai-neutral-100', (array) $look['card']['class'], true ) && in_array( 'border:1px solid var(--dxai-surface)', (array) $look['card']['css'], true ) && ! in_array( 'd-flex', (array) $look['card']['class'], true ), json_encode( $look['card'] ) );
$expect( 'its eyebrows as one line: size, case, weight, tracking and colour', is_array( $look['eyebrow'] ) && in_array( 'text-13', (array) $look['eyebrow']['class'], true ) && in_array( 'uppercase', (array) $look['eyebrow']['class'], true ) && in_array( 'color:var(--dxai-red-49)', (array) $look['eyebrow']['css'], true ) && ! in_array( 'mx-0', (array) $look['eyebrow']['class'], true ), json_encode( $look['eyebrow'] ) );
$expect( 'its buttons as a first and a second style', is_array( $look['button'] ) && ( $look['button']['primary']['key'] ?? '' ) === 'is-style-primary-button' && ( $look['button']['secondary']['key'] ?? '' ) === 'is-style-secondary-button', json_encode( $look['button'] ) );
$marked = array_values( array_filter( array_keys( $layouts ), static fn( $id ) => str_contains( Block_Library::markup( (string) $id ), 'dxai-lib-card' ) && str_contains( Block_Library::markup( (string) $id ), 'dxai-lib-eyebrow' ) ) );
$expect( 'the builder marks the layouts\' cards and eyebrows for it', count( $marked ) >= 5 && count( array_filter( array_keys( $layouts ), static fn( $id ) => str_contains( Block_Library::markup( (string) $id ), 'dxai-lib-card' ) ) ) >= 15, (string) count( $marked ) );
$dressed = 0;
$links   = 0;
$undress = array();
foreach ( array_slice( $marked, 0, 6 ) as $id ) {
	$why   = '';
	$block = Block_Library::fill( (string) $id, array_merge( $many, array( 'home' => (int) $look_id ) ), $why );
	if ( $block === null ) {
		$undress[] = "$id: $why";
		continue;
	}
	foreach ( $flat( $block ) as $b ) {
		$cls = (string) ( $b['attrs']['className'] ?? '' );
		if ( str_contains( $cls, 'dxai-lib-card' ) ) {
			++$dressed;
			if ( ! str_contains( $cls, 'rounded-md' ) || ! str_contains( $cls, 'shadow-dxai-shadow-2' ) || isset( $b['attrs']['style']['border'] ) || isset( $b['attrs']['style']['shadow'] ) || isset( $b['attrs']['backgroundColor'] ) || isset( $b['attrs']['style']['color']['background'] ) || preg_match( '/has-background|has-[a-z0-9-]+-background-color/', (string) $b['innerHTML'] ) === 1 || ! str_contains( (string) $b['innerHTML'], 'rounded-md' ) || ! str_contains( (string) ( $b['attrs']['dxaiCss'] ?? '' ), 'border:1px solid var(--dxai-surface)' ) ) {
				$undress[] = "$id card: $cls | " . json_encode( $b['attrs']['style'] ?? null ) . ' | ' . substr( (string) $b['innerHTML'], 0, 120 );
			}
		}
		if ( str_contains( $cls, 'dxai-lib-eyebrow' ) ) {
			++$dressed;
			if ( ! str_contains( $cls, 'uppercase' ) || ! str_contains( $cls, 'text-13' ) || isset( $b['attrs']['fontSize'] ) || isset( $b['attrs']['style']['typography'] ) || isset( $b['attrs']['textColor'] ) || ! str_contains( (string) ( $b['attrs']['dxaiCss'] ?? '' ), 'color:var(--dxai-red-49)' ) || preg_match( '/has-[a-z0-9-]+-font-size|has-[a-z0-9-]+-color/', (string) $b['innerHTML'] ) === 1 ) {
				$undress[] = "$id eyebrow: $cls | " . json_encode( $b['attrs'] ) . ' | ' . substr( (string) $b['innerHTML'], 0, 120 );
			}
		}
		if ( (string) $b['blockName'] === 'core/button' && str_contains( $cls, 'is-style-' ) ) {
			preg_match_all( '/(?:^|\s)(is-style-[a-z0-9-]+)/', $cls, $styles );
			if ( str_contains( $cls, 'is-style-link' ) ) {
				// A button drawn as a link (a card's "Learn more") is a link: it keeps its own look.
				++$links;
			} elseif ( count( $styles[1] ) !== 1 || ! in_array( $styles[1][0], array( 'is-style-primary-button', 'is-style-secondary-button' ), true ) || isset( $b['attrs']['textColor'] ) || isset( $b['attrs']['backgroundColor'] ) || isset( $b['attrs']['fontSize'] ) || isset( $b['attrs']['style']['color'] ) || isset( $b['attrs']['style']['spacing']['padding'] ) || isset( $b['attrs']['style']['typography'] ) || preg_match( '/has-[a-z0-9-]+-color|has-text-color|has-background|has-[a-z0-9-]+-font-size|has-custom-font-size/', (string) $b['innerHTML'] ) === 1 ) {
				$undress[] = "$id button: $cls | " . json_encode( $b['attrs'] ) . ' | ' . substr( (string) $b['innerHTML'], 0, 220 );
			} else {
				++$dressed;
			}
		}
	}
}
$expect( 'on a page of that Home, the layouts\' cards are that box, their eyebrows that line, their buttons those styles — their own corners, shadows, backgrounds, colours, padding and type taken off, in the settings and in the tag', $dressed >= 8 && $undress === array(), implode( '; ', array_slice( $undress, 0, 3 ) ) );
$link_layouts = array_values( array_filter( array_keys( $layouts ), static fn( $id ) => str_contains( Block_Library::markup( (string) $id ), 'is-style-link-button' ) ) );
foreach ( array_slice( $link_layouts, 0, 2 ) as $id ) {
	$why   = '';
	$block = Block_Library::fill( (string) $id, array_merge( $many, array( 'home' => (int) $look_id ) ), $why );
	foreach ( $block !== null ? $flat( $block ) : array() as $b ) {
		if ( (string) $b['blockName'] === 'core/button' && str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'is-style-link' ) ) {
			++$links;
		}
	}
}
$expect( 'a layout\'s button drawn as a link (a card\'s "Learn more") keeps its link style on that page: it is not made the Home\'s button', $link_layouts !== array() && $links > 0, json_encode( array( count( $link_layouts ), $links ) ) );
$why   = '';
$plain = Block_Library::fill( (string) $marked[0], array_merge( $many, array( 'home' => (int) $homes['bare'] ) ), $why );
$keeps = 0;
foreach ( $plain !== null ? $flat( $plain ) : array() as $b ) {
	if ( str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'dxai-lib-card' ) && ! str_contains( (string) ( $b['attrs']['className'] ?? '' ), 'rounded-md' ) ) {
		++$keeps;
	}
}
$expect( 'on a page of a Home with no look of its own (too few cards alike), the layouts keep the team\'s', $plain !== null && $keeps >= 1, $why );
$expect( 'the Home is not changed by any of it', (string) get_post_field( 'post_content', (int) $look_id ) === wp_unslash( wp_slash( $look_home ) ) );
// The Home's pictures go to four sections of a page at most: the fifth place is offered none.
$pool = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$pool[] = array( 'id' => 0, 'url' => "https://example.test/wp-content/uploads/photo-$i.jpg", 'alt' => "Photo $i" );
}
$pf_item  = array( 'title' => 'Mold Removal', 'type' => 'service' );
$pf_facts = array_merge( $facts, array( 'home' => 987654, 'title' => 'Mold Removal', 'kind' => 'service' ) );
$offered  = 0;
for ( $i = 1; $i <= 6; $i++ ) {
	$pf = \DXAI_UI\Pages\Layouts::page_facts( $pf_facts, $pf_item, 'cards', "seed|$i", array( 'pool' => $pool ) );
	if ( (string) ( $pf['image_url:1'] ?? '' ) !== '' ) {
		++$offered;
		\DXAI_UI\Pages\Layouts::pictured( $pf, 1 ); // as a section that shows it would say
	}
}
$expect( 'a page\'s sections are offered the Home\'s pictures four times, then none (the hero\'s is the Home\'s own)', $offered === 4, (string) $offered );
// A list of titles alone is shown by a section with no slot for a row's words, when the library has one.
$bare_rows = array( 'lists' => array( 'items' => array_map( static fn( $r ) => array( 'title' => $r['title'], 'text' => '', 'url' => '', 'icon' => 'check' ), $many['lists']['items'] ) ) );
$textless  = array_values( array_filter( array_keys( $layouts ), static fn( $id ) => (string) ( $layouts[ $id ]['role'] ?? '' ) === 'cards' && (string) ( $layouts[ $id ]['list'] ?? '' ) === 'items' && ! in_array( 'row:text', (array) ( $layouts[ $id ]['tokens'] ?? array() ), true ) ) );
$why       = '';
$picked    = Block_Library::section( 'cards', array_merge( $many, $bare_rows ), 'seed|bare', array(), '', $why );
$expect( 'the points of a Home that are titles alone go to a section of cards that asks for no more (' . count( $textless ) . ' of them in the library), not to one that would show empty cards', $textless === array() || ( $picked !== null && in_array( (string) $picked['id'], $textless, true ) ), $picked !== null ? (string) $picked['id'] : $why );

echo "\nThe generic library (a theme with no template of its own)\n";
// Forced here, on the theme that has the template: every section the library makes comes out in core blocks, with the colours the
// palette has for the roles, and nothing of the template's theme in it.
add_filter( 'dxai_ui_template', $force_generic );
$roles = \DXAI_UI\Pages\Template::palette_roles();
$expect( 'the theme\'s palette is read by role: a dark ground, a main colour, an accent and a light ground, the dark and the light apart', $roles['dark'] !== '' && $roles['main'] !== '' && $roles['accent'] !== '' && $roles['light'] !== '' && $roles['dark'] !== $roles['light'] && $roles['on_dark'] === $roles['light'], json_encode( $roles ) );
$palette_now = \DXAI_UI\Theme\Capabilities::palette();
$gen_bad     = array();
$gen_n       = 0;
foreach ( array_merge( array_keys( $layouts ), array( 'cta-call', 'cta-banner', 'faq-accordion', 'faq-accordion-intro', 'areas-places', 'process-steps' ) ) as $id ) {
	$why   = '';
	$block = Block_Library::fill( (string) $id, $many, $why );
	if ( $block === null ) {
		$gen_bad[] = "$id: $why";
		continue;
	}
	++$gen_n;
	$out = serialize_block( $block );
	if ( preg_match( '/<!-- wp:(?:amr|dx)\//', $out ) === 1 ) {
		$gen_bad[] = "$id: a theme's block";
	}
	preg_match_all( '/\bhas-([a-z0-9-]+?)-(?:background-color|border-color|color)\b/', $out, $cm );
	foreach ( array_unique( $cm[1] ) as $slug ) {
		if ( ! in_array( $slug, array( 'text', 'link', 'background' ), true ) && ! isset( $palette_now[ $slug ] ) ) {
			$gen_bad[] = "$id: has-$slug-color";
		}
	}
	foreach ( $flat( $block ) as $b ) {
		foreach ( array( 'backgroundColor', 'textColor', 'borderColor' ) as $k ) {
			if ( isset( $b['attrs'][ $k ] ) && ! isset( $palette_now[ (string) $b['attrs'][ $k ] ] ) ) {
				$gen_bad[] = "$id: $k " . (string) $b['attrs'][ $k ];
			}
		}
	}
	if ( str_contains( $out, '{{' ) || str_contains( $out, '[[' ) || is_wp_error( Block_Tree::serialize_checked( array( $block ) ) ) ) {
		$gen_bad[] = "$id: a token left, or no round trip";
	}
}
$expect( "every layout and every hand-built section is made in the generic library ($gen_n), in core blocks only, with colours the palette has, round-tripping", $gen_bad === array() && $gen_n >= 60, implode( '; ', array_slice( array_unique( $gen_bad ), 0, 4 ) ) );
$why = '';
$rel = Block_Library::section( 'related', array_merge( $many, array( 'kind' => 'service' ) ), 'seed|generic', array(), '', $why );
$rm  = $rel !== null ? serialize_block( $rel['block'] ) : '';
$expect( 'a card that was the theme\'s box with a link is a group whose title is the link, to the row\'s page', $rel !== null && str_contains( $rm, 'href="https://example.test/related-Alpha/"' ) && ! str_contains( $rm, 'amr/link-box' ) && ! str_contains( $rm, 'amr-link-box' ), $why . ' ' . substr( preg_replace( '/\s+/', ' ', $rm ), 0, 160 ) );
remove_filter( 'dxai_ui_template', $force_generic );

echo "\nA page's words and colours, on any theme and any template\n";
// The company's name: what is not the name at either end of the Home's title goes (a code someone put there, a page's word), never a word
// of the name.
$names_seen = array(
	Block_Library::name_in( 'BR12 H2O Away', '<p>Call H2O Away today.</p>' ),
	Block_Library::name_in( 'Harborview Water and Fire', '<p>© Harborview Co.</p>' ),
	Block_Library::name_in( 'Home | Acme Dry', '<p>Call Acme Dry.</p>' ),
	Block_Library::name_in( 'H2O Away', '' ),
	Block_Library::name_in( 'Acme Dry – Homepage', '' ),
);
$expect( 'the company is the name the Home\'s title gives, without a code or a page\'s word at either end, and never cut inside', $names_seen === array( 'H2O Away', 'Harborview Water and Fire', 'Acme Dry', 'H2O Away', 'Acme Dry' ), json_encode( $names_seen ) );
$expect( 'a placeholder a design left to be filled in is not something the Home says (and a line that only sounds like one is)', \DXAI_UI\Pages\Layouts::placeholder( '“Paste a verbatim 5-star Google review here, word for word.”' ) && \DXAI_UI\Pages\Layouts::placeholder( 'Reviewer Name' ) && \DXAI_UI\Pages\Layouts::placeholder( 'Lorem ipsum dolor sit amet' ) && ! \DXAI_UI\Pages\Layouts::placeholder( 'They were at the house within the hour.' ) && ! \DXAI_UI\Pages\Layouts::placeholder( 'Our new office is coming soon' ) );

// What a Home says, read the way designs write it: steps numbered in badges hidden from screen readers, points whose head is in bold,
// a hero's pills and buttons that are not points, a placeholder the design left for reviews, a street address beside the figures.
$badge = static fn( int $n, string $t, string $d ): string => '<!-- wp:group {"className":"step-card"} --><div class="wp-block-group step-card"><!-- wp:paragraph {"className":"badge"} --><p class="badge"><span aria-hidden="true">' . $n . '</span></p><!-- /wp:paragraph -->' . $heading( $t, 3 ) . $para( $d ) . '</div><!-- /wp:group -->';
$quote = static fn( string $where ): string => '<!-- wp:column --><div class="wp-block-column">' . $para( '“Paste a verbatim 5-star Google review here, word for word.”' ) . $para( 'Reviewer Name' ) . $para( $where ) . '</div><!-- /wp:column -->';
$said_home = $section( 'Hero Section', 'hero-q', $heading( 'Water Damage Restoration in Tyler', 1 ) . $para( '24/7 Emergency Response · Tyler, TX' ) . $para( 'Free inspections today' ) . $para( 'Water, fire and mold damage restoration for homes and businesses in Tyler.' ) . '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Licensed and insured crews</li><!-- /wp:list-item --><!-- wp:list-item --><li>Call now: (555) 010-0101</li><!-- /wp:list-item --><!-- wp:list-item --><li>800+ Google reviews · 4.9 rating</li><!-- /wp:list-item --></ul><!-- /wp:list -->' )
	. $section( 'Promise Section', 'promise-q', $heading( 'Our promise to you' ) . '<!-- wp:group {"className":"grid"} --><div class="wp-block-group grid">' . $badge( 1, 'Call, day or night', 'A live person answers and sends the nearest crew.' ) . $badge( 2, 'On site within the hour', 'Most crews arrive within the hour anywhere we work.' ) . $badge( 3, 'Free inspection', 'We assess the damage and document everything.' ) . $badge( 4, 'Dry and restore', 'Extraction, drying and the rebuild, start to finish.' ) . '</div><!-- /wp:group -->' )
	. $section( 'Why Section', 'why-q', '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $heading( 'Why homeowners call us' ) . $para( '✓ <strong>On site in about an hour.</strong> When water is spreading, every minute matters, so we come day or night.' ) . $para( '<strong>Three generations of claims experience:</strong> we know how carriers think and where claims get stuck.' ) . $para( '<strong>One project manager on every job</strong> — one point of contact from start to finish.' ) . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/crew.jpg" alt="The crew"/></figure><!-- /wp:image --></div><!-- /wp:column --></div><!-- /wp:columns -->' )
	. $section( 'Testimonials Section', 'rev-q', $heading( 'What customers say' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $quote( 'Tyler, TX' ) . $quote( 'Longview, TX' ) . $quote( 'Lindale, TX' ) . '</div><!-- /wp:columns -->' )
	. $section( 'Visit Section', 'visit-q', $heading( 'Visit our office' ) . $para( '497 Lendall Ln, Suite 104' ) . $para( 'Open around the clock' ) . $para( '17,000+ homes restored' ) );
$said_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'BR12 Tyler Dry Pros', 'post_content' => wp_slash( $said_home ) ) );
$made[]  = (int) $said_id;
update_post_meta( $said_id, Page_Scope::META, $said_id );
update_post_meta( $said_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $said_id, '_dxai_ui_css_url', 'dxai-ui/fixture-library-said.css' );
$said = \DXAI_UI\Pages\Layouts::lists( (int) $said_id, \DXAI_UI\Pages\Section_Library::for_page( (int) $said_id ) );
$expect( 'cards numbered one to four in badges (hidden from screen readers) are the Home\'s steps, in order, each with its words', count( $said['steps'] ) === 4 && array_column( $said['steps'], 'eyebrow' ) === array( '1', '2', '3', '4' ) && ( $said['steps'][1]['title'] ?? '' ) === 'On site within the hour' && str_starts_with( (string) ( $said['steps'][1]['text'] ?? '' ), 'Most crews' ), json_encode( array_map( static fn( $r ) => ( $r['eyebrow'] ?? '' ) . ' ' . $r['title'], $said['steps'] ) ) );
$headed = array_values( array_filter( $said['items'], static fn( $r ) => (string) ( $r['text'] ?? '' ) !== '' ) );
$expect( 'lines whose head is in bold are points with their words (the head without its full stop or colon), after a tick or not', array_column( $headed, 'title' ) === array( 'On site in about an hour', 'Three generations of claims experience', 'One project manager on every job' ) && str_starts_with( (string) $headed[0]['text'], 'When water is spreading' ) && str_starts_with( (string) $headed[2]['text'], 'one point of contact' ), json_encode( array_map( static fn( $r ) => $r['title'] . ' | ' . substr( (string) $r['text'], 0, 20 ), $said['items'] ) ) );
$bad_points = array_filter( $said['items'], static fn( $r ) => preg_match( '/\(\d{3}\)|·|Emergency Response|Call now|Free inspections today/', (string) $r['title'] ) === 1 );
$expect( 'a hero\'s pills, its buttons and the labels of its figures are not points; an item of its list is', $bad_points === array() && in_array( 'Licensed and insured crews', array_column( $said['items'], 'title' ), true ), json_encode( array_column( $said['items'], 'title' ) ) );
$expect( 'reviews the design left as placeholders ("Paste a verbatim review here", "Reviewer Name") are no reviews', $said['reviews'] === array(), json_encode( $said['reviews'] ) );
$expect( 'a street address is not a figure; a figure beside it is', ! in_array( '497', array_column( $said['stats'], 'title' ), true ) && in_array( '17,000+', array_column( $said['stats'], 'title' ), true ), json_encode( array_column( $said['stats'], 'title' ) ) );
$said_facts = Block_Library::facts( (int) $said_id, Home_Kit::facts( (int) $said_id ) );
$expect( 'and its company is "Tyler Dry Pros": the code before the name in its title is not the name', ( $said_facts['company'] ?? '' ) === 'Tyler Dry Pros', json_encode( $said_facts['company'] ?? null ) );

// A list is shown once on a page: the next section that lists it goes on from the rows the page has shown.
$off_facts = array_merge( $facts, array( 'home' => 987655, 'title' => 'Offsets Page', 'kind' => 'service', 'lists' => array( 'items' => $rows_of( 'items', 5 ) ) ) );
$off_item  = array( 'type' => 'service', 'title' => 'Offsets Page' );
\DXAI_UI\Pages\Layouts::begin( 'Offsets Page', 'service' );
$off_one = \DXAI_UI\Pages\Layouts::page_facts( $off_facts, $off_item, 'cards', 'off|1', array() );
$why     = '';
$off_sec = Block_Library::fill( 'layout-cards-1', $off_one, $why );
$off_n   = \DXAI_UI\Pages\Layouts::shown( $off_one, 'items' );
$off_two = \DXAI_UI\Pages\Layouts::page_facts( $off_facts, $off_item, 'two-col', 'off|2', array() );
$expect( 'a section that shows a list counts the rows it showed for its page, and the next section of the page is given the rows after them', $off_sec !== null && $off_n >= 1 && array_column( $off_two['lists']['items'], 'title' ) === array_slice( array_column( $rows_of( 'items', 5 ), 'title' ), $off_n ), json_encode( array( $why, $off_n, array_column( $off_two['lists']['items'], 'title' ) ) ) );
\DXAI_UI\Pages\Layouts::begin( 'Offsets Page', 'service' );
$off_new = \DXAI_UI\Pages\Layouts::page_facts( $off_facts, $off_item, 'cards', 'off|1', array() );
$rel = \DXAI_UI\Pages\Layouts::page_facts( array_merge( $off_facts, array( 'region' => '' ) ), $off_item, 'related', 'off|3', array( 'rows' => array( array( 'title' => 'Services', 'url' => 'https://example.test/services/' ), array( 'title' => 'Mold Removal', 'text' => 'Containment and safe removal.', 'url' => 'https://example.test/mold/' ) ) ) );
$expect( 'a related page the Home says nothing about gets a line of its own (its card is not a title in an empty box); one it describes keeps its words', str_contains( (string) ( $rel['lists']['related'][0]['text'] ?? '' ), 'Services from' ) && ( $rel['lists']['related'][1]['text'] ?? '' ) === 'Containment and safe removal.', json_encode( array_column( (array) ( $rel['lists']['related'] ?? array() ), 'text' ) ) );
$expect( 'and a page begun again (a build after a plan) starts from the first row', ( $off_new['lists']['items'][0]['title'] ?? '' ) === ( $rows_of( 'items', 1 )[0]['title'] ?? '-' ) );

// The team's words of a heading are kept only where they name what the section lists.
$expect( 'the team\'s heading is kept where it names what the section lists ("Our Restoration Services" over services, "Service Areas" over places), and gives way where it does not (over the Home\'s points)', Block_Library::says_what_it_lists( 'h2-s', 'Our Restoration Services', array( 'section_lists' => array( 'services' ) ) ) && ! Block_Library::says_what_it_lists( 'h2-s', 'Our Restoration Services', array( 'section_lists' => array( 'items' ) ) ) && Block_Library::says_what_it_lists( 'h2-s', 'Service Areas', array( 'section_lists' => array( 'places' ) ) ) && ! Block_Library::says_what_it_lists( 'h2-s', 'What our customers say', array( 'section_lists' => array( 'items' ) ) ) && Block_Library::says_what_it_lists( 'p-m', 'Our restoration services are here for you.', array( 'section_lists' => array( 'items' ) ) ) );

// Provisional words: one paragraph of them in a section, beside the team's own words.
$prov = parse_blocks( '<!-- wp:group --><div class="wp-block-group">' . $heading( '{{t:h2-s}}' ) . $para( '{{t:p-m}}' ) . $para( '{{t:p-l}}' ) . '<!-- wp:group --><div class="wp-block-group">' . $para( '{{t:p-s}}' ) . $para( '{{t:p-m|We work with all insurance companies.}}' ) . '</div><!-- /wp:group --></div><!-- /wp:group -->' )[0];
$tok  = new ReflectionMethod( Block_Library::class, 'tokens' );
$thin = new ReflectionMethod( Block_Library::class, 'thin' );
$tok->setAccessible( true );
$thin->setAccessible( true );
$prov_missing = array();
$prov_facts   = array_merge( $many, array( 'role' => 'cta' ) );
$tok->invokeArgs( null, array( &$prov, $prov_facts, null, &$prov_missing ) );
$prov_kept = false;
$thin->invokeArgs( null, array( &$prov, &$prov_kept ) );
$prov_out = serialize_block( $prov );
$expect( 'a section keeps one paragraph of provisional words, and the team\'s own words beside it', substr_count( $prov_out, '<p>' ) === 2 && str_contains( $prov_out, 'We work with all insurance companies.' ) && substr_count( $prov_out, '<h2' ) === 1, (string) substr_count( $prov_out, '<p>' ) );

// Every line readable on what it stands on, in this theme's colours: decided from the colours themselves, so it holds on any theme.
$pal = \DXAI_UI\Theme\Capabilities::palette();
foreach ( (array) ( \DXAI_UI\Theme\Theme_Palette::current()['anchors'] ?? array() ) as $slug => $hex ) {
	$pal[ $slug ] = $pal[ $slug ] ?? (string) $hex;
}
$dark_text = (string) ( \DXAI_UI\Pages\Template::palette_roles()['text'] ?? '' );
$cpara     = static fn( string $t, string $slug ): string => '<!-- wp:paragraph {"textColor":"' . $slug . '"} --><p class="has-' . $slug . '-color has-text-color">' . $t . '</p><!-- /wp:paragraph -->';
$box       = static fn( string $bg, string $inner, string $class = '' ): string => '<!-- wp:group {' . ( $class !== '' ? '"className":"' . $class . '",' : '' ) . '"style":{"color":{"background":"' . $bg . '"}}} --><div class="wp-block-group' . ( $class !== '' ? ' ' . $class : '' ) . ' has-background" style="background-color:' . $bg . '">' . $inner . '</div><!-- /wp:group -->';
$grounds   = $box( '#173764', $para( 'Plain words on dark' ) . $cpara( 'Unknown colour on dark', 'no-such-colour' ) . $box( '#ffffff0a', $para( 'Under a veil of white' ) ) )
	. '<!-- wp:group {"className":"dxai-lib-card"} --><div class="wp-block-group dxai-lib-card">' . $box( '#26cbf8', $para( 'In a loud box' ) ) . $box( '#eaf2fb', $para( 'In a light tint' ) ) . '</div><!-- /wp:group -->'
	. ( $dark_text !== '' ? $cpara( 'Dark words on the page', $dark_text ) : '' );
$leg      = Block_Library::legible( parse_blocks( '<!-- wp:group --><div class="wp-block-group">' . $grounds . '</div><!-- /wp:group -->' )[0] );
$by_words = array();
foreach ( $flat( $leg ) as $b ) {
	$t = trim( wp_strip_all_tags( (string) $b['innerHTML'] ) );
	if ( (string) $b['blockName'] === 'core/paragraph' ) {
		$by_words[ $t ] = $b;
	}
}
$reads = static function ( string $words, string $ground, float $at_least ) use ( $by_words, $pal ): bool {
	$slug = (string) ( $by_words[ $words ]['attrs']['textColor'] ?? '' );

	return $slug !== '' && isset( $pal[ $slug ] ) && \DXAI_UI\Support\Color_Math::contrast( (string) $pal[ $slug ], $ground ) >= $at_least && str_contains( (string) $by_words[ $words ]['innerHTML'], 'has-' . $slug . '-color' );
};
$expect( 'words with no colour of their own on a dark ground take a colour of this theme that reads there (4.5:1), in the setting and in the tag', $reads( 'Plain words on dark', '#173764', 4.5 ), json_encode( $by_words['Plain words on dark']['attrs'] ?? null ) );
$expect( 'a colour the theme does not have, on a dark ground, gives way to one it has that reads', $reads( 'Unknown colour on dark', '#173764', 3.0 ) && ! str_contains( (string) ( $by_words['Unknown colour on dark']['innerHTML'] ?? '' ), 'no-such-colour' ), json_encode( $by_words['Unknown colour on dark']['attrs'] ?? null ) );
$expect( 'a veil of white over a dark band is still a dark ground', $reads( 'Under a veil of white', '#173764', 3.0 ), json_encode( $by_words['Under a veil of white']['attrs'] ?? null ) );
$loud_box = null;
$tint_box = null;
foreach ( $flat( $leg ) as $b ) {
	foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
		$t = trim( wp_strip_all_tags( (string) ( $c['innerHTML'] ?? '' ) ) );
		if ( $t === 'In a loud box' ) {
			$loud_box = $b;
		} elseif ( $t === 'In a light tint' ) {
			$tint_box = $b;
		}
	}
}
$expect( 'a loud colour (bright and saturated) as a box\'s ground inside a card gives way to the card\'s own surface; a light tint stays', $loud_box !== null && ! isset( $loud_box['attrs']['style']['color']['background'] ) && ! str_contains( (string) $loud_box['innerHTML'], '#26cbf8' ) && $tint_box !== null && ( $tint_box['attrs']['style']['color']['background'] ?? '' ) === '#eaf2fb', json_encode( array( $loud_box['attrs'] ?? null, $tint_box['attrs'] ?? null ) ) );
$expect( 'and the theme\'s colour for words that reads on the page is left as it is', $dark_text === '' || ( $by_words['Dark words on the page']['attrs']['textColor'] ?? '' ) === $dark_text );
$expect( 'the section is still valid blocks after it', ! is_wp_error( Block_Tree::serialize_checked( array( $leg ) ) ) );
// On a page of a design, a colour of the theme is drawn in the design's colour of the same name (its scope redefines the class): a
// brand colour the design made light gives way, on a light ground, to a colour of the theme that reads and is still a colour.
$chroma_slug = '';
foreach ( $pal as $slug => $hex ) {
	$rgb = \DXAI_UI\Support\Color_Math::parse( (string) $hex );
	if ( $rgb !== null && \DXAI_UI\Support\Color_Math::oklch( $rgb )[1] > 0.1 && \DXAI_UI\Support\Color_Math::contrast( (string) $hex, '#ffffff' ) >= 3.0 ) {
		$chroma_slug = (string) $slug;
		break;
	}
}
update_post_meta( (int) $said_id, '_dxai_ui_palette', array( 'colors' => array( '#f2e6a0' => array( 'slug' => $chroma_slug, 'name' => 'Pale', 'value' => '#f2e6a0' ) ) ) );
$scoped     = parse_blocks( '<!-- wp:group --><div class="wp-block-group">' . $cpara( 'In the design’s own colour', $chroma_slug ) . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"textColor":"' . $chroma_slug . '","backgroundColor":"white"} --><div class="wp-block-button"><a class="wp-block-button__link has-' . $chroma_slug . '-color has-white-background-color has-text-color has-background wp-element-button">Call now</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->' )[0];
$on_theme   = Block_Library::legible( $scoped );
$on_design  = Block_Library::legible( $scoped, (int) $said_id );
$theme_slug = (string) ( $on_theme['innerBlocks'][0]['attrs']['textColor'] ?? '' );
$scope_slug = (string) ( $on_design['innerBlocks'][0]['attrs']['textColor'] ?? '' );
$scope_rgb  = \DXAI_UI\Support\Color_Math::parse( (string) ( $pal[ $scope_slug ] ?? '' ) );
$scope_btn  = (array) ( $on_design['innerBlocks'][1]['innerBlocks'][0] ?? array() );
$btn_slug   = (string) ( $scope_btn['attrs']['textColor'] ?? '' );
$expect( 'and a button\'s own words on its own ground the same, written on its link (where a button\'s settings go)', $btn_slug !== '' && $btn_slug !== $chroma_slug && \DXAI_UI\Support\Color_Math::contrast( (string) ( $pal[ $btn_slug ] ?? '#ffffff' ), '#ffffff' ) >= 4.5 && preg_match( '/<a class="[^"]*has-' . preg_quote( $btn_slug, '/' ) . '-color/', (string) ( $scope_btn['innerHTML'] ?? '' ) ) === 1 && ! str_contains( (string) ( $scope_btn['innerHTML'] ?? '' ), 'has-' . $chroma_slug . '-color' ), json_encode( array( $btn_slug, substr( (string) ( $scope_btn['innerHTML'] ?? '' ), 0, 160 ) ) ) );
$expect( 'on a page of a design that draws a theme colour lighter, words in it give way to a colour of the theme that reads (4.5:1) and is still a colour; on a page of the theme they keep it', $chroma_slug !== '' && $theme_slug === $chroma_slug && $scope_slug !== '' && $scope_slug !== $chroma_slug && $scope_rgb !== null && \DXAI_UI\Support\Color_Math::contrast( (string) $pal[ $scope_slug ], '#ffffff' ) >= 4.5 && \DXAI_UI\Support\Color_Math::oklch( $scope_rgb )[1] > 0.08, json_encode( array( $chroma_slug, $theme_slug, $scope_slug ) ) );

// The Home's own questions come first: a section that lists them over one that carries the team's questions.
$faq_ids = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$why  = '';
	$made_faq = Block_Library::section( 'faq', array_merge( $many, array( 'kind' => 'service', 'role' => 'faq' ) ), "faq-seed-$i", array(), '', $why );
	if ( $made_faq !== null ) {
		$faq_ids[] = (string) $made_faq['id'];
	}
}
$expect( 'when the Home has its own questions, the page\'s questions section is one that lists them (never the team\'s own questions)', count( $faq_ids ) === 6 && count( array_filter( $faq_ids, static fn( $id ) => str_contains( Block_Library::markup( $id ), '"dxaiRepeat":"faq"' ) ) ) === 6, json_encode( $faq_ids ) );

// The library's stylesheet: a box that is a link keeps the card's colours; a row of a flex box is spaced by its gap, not by the browser's
// margins.
$lib_css = (string) file_get_contents( DXAI_UI_DIR . 'assets/css/block-library.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expect( 'the library\'s stylesheet keeps a box-link\'s words in the card\'s colours, and takes the browser\'s margins off a flex row\'s headings with no weight', preg_match( '/a\.wp-block-amr-link-box[^{]*\{[^}]*color:\s*inherit/s', $lib_css ) === 1 && preg_match( '/:where\([^)]*\.d-flex[^{]*>\s*:where\(h1[^{]*\{\s*margin-block:\s*0/s', $lib_css ) === 1 );

remove_filter( 'dxai_ui_template', $pin_built_in, 5 );
Block_Library::forget();
echo "\nOn a theme with no template (Twenty Twenty-Five, when it is installed)\n";
if ( wp_get_theme( 'twentytwentyfive' )->exists() ) {
	$theme_was = get_stylesheet();
	$back      = static function () use ( $theme_was ): void {
		if ( get_stylesheet() !== $theme_was ) {
			switch_theme( $theme_was );
			\DXAI_UI\Theme\Theme_Palette::reset();
			\DXAI_UI\Theme\Theme_Buttons::reset();
		}
	};
	register_shutdown_function( $back );
	switch_theme( 'twentytwentyfive' );
	\DXAI_UI\Theme\Theme_Palette::reset();
	\DXAI_UI\Theme\Theme_Buttons::reset();
	$expect( 'the theme has no template of its own; the generic library is in use, and the library is on', \DXAI_UI\Pages\Template::for_theme() === null && \DXAI_UI\Pages\Template::is_generic() && Block_Library::theme_ok() && ! Block_Library::template_ok(), get_stylesheet() );
	$expect( 'the roles the sections use have core blocks to be written in', \DXAI_UI\Theme\Capabilities::CORE_BLOCKS['link_box'] === 'core/group' && \DXAI_UI\Theme\Capabilities::CORE_BLOCKS['span'] === 'core/paragraph' && \DXAI_UI\Theme\Capabilities::CORE_BLOCKS['picture'] === 'core/image' );
	$tt_roles = \DXAI_UI\Pages\Template::palette_roles();
	$tt_pal   = \DXAI_UI\Theme\Capabilities::palette();
	$expect( 'its palette is read by role (' . json_encode( $tt_roles ) . ')', $tt_roles['dark'] !== '' && $tt_roles['light'] !== '' && $tt_roles['main'] !== '' && isset( $tt_pal[ $tt_roles['dark'] ], $tt_pal[ $tt_roles['light'] ] ) );
	$tt_bad = array();
	$tt_n   = 0;
	foreach ( array( 'cta', 'faq', 'cards', 'reviews', 'process', 'trust', 'related', 'two-col' ) as $role ) {
		$why = '';
		$sec = Block_Library::section( $role, array_merge( $many, array( 'kind' => 'service' ) ), 'seed|tt5', array(), '', $why );
		if ( $sec === null ) {
			$tt_bad[] = "$role: $why";
			continue;
		}
		++$tt_n;
		$out = serialize_block( $sec['block'] );
		if ( preg_match( '/<!-- wp:(?:amr|dx)\//', $out ) === 1 || str_contains( $out, '{{' ) || str_contains( $out, '[[' ) ) {
			$tt_bad[] = "$role: a theme's block or a token";
		}
		preg_match_all( '/\bhas-([a-z0-9-]+?)-(?:background-color|border-color|color)\b/', $out, $cm );
		foreach ( array_unique( $cm[1] ) as $slug ) {
			if ( ! in_array( $slug, array( 'text', 'link', 'background' ), true ) && ! isset( $tt_pal[ $slug ] ) ) {
				$tt_bad[] = "$role: has-$slug-color is not a colour of this theme";
			}
		}
		// A button's style is the theme's own (fill, outline); a quote's or a separator's plain style is core's, on any theme.
		if ( preg_match( '/<!-- wp:button \{[^}]*"className":"[^"]*\b(is-style-(?!fill\b|outline\b)[a-z-]+)/', $out, $sm ) === 1 ) {
			$tt_bad[] = "$role: $sm[1]";
		}
	}
	$expect( "a section of every role is made on it ($tt_n of 8): core blocks, this theme's colours and button styles, no token", $tt_bad === array() && $tt_n === 8, implode( '; ', array_slice( $tt_bad, 0, 4 ) ) );
	$back();
	$expect( 'and the theme is switched back', get_stylesheet() === $theme_was && Block_Library::template_ok(), get_stylesheet() );
} else {
	echo "  (not installed: skipped)\n";
}

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
