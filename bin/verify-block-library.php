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
$known_tokens = array( 'company', 'company_short', 'phone', 'phone_url', 'email', 'address', 'region', 'title', 'contact_url', 'site_url', 'row', 'icon_url', 'icon_id' );
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
$expect( 'there are sections, each with an id, a role, a label, what it needs and the pages it is for', count( $entries ) >= 6 && count( array_filter( $entries, static fn( $e ) => preg_match( '/^[a-z0-9-]{2,60}$/', (string) $e['id'] ) === 1 && (string) $e['role'] !== '' && (string) $e['label'] !== '' && is_array( $e['needs'] ) && (array) $e['pages'] !== array() ) ) === count( $entries ), (string) count( $entries ) );
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
	$expect( "$id: every token is one the library fills (" . implode( ' ', $tokens ) . ')', array_diff( $tokens, $known_tokens ) === array() && substr_count( $markup, '{{' ) === preg_match_all( '/\{\{[^{}]*\}\}/', $markup ), implode( ',', array_diff( $tokens, $known_tokens ) ) );
	preg_match_all( '/"(dxaiIf|dxaiRepeat|dxaiIcon)":"([^"]*)"/', $markup, $mk, PREG_SET_ORDER );
	$bad_marker = array();
	foreach ( $mk as $m ) {
		if ( $m[1] === 'dxaiIcon' && ! is_readable( DXAI_UI_DIR . 'data/block-library/icons/' . $m[2] . '.svg' ) ) {
			$bad_marker[] = 'no drawing ' . $m[2];
		}
		if ( $m[1] === 'dxaiRepeat' && ! in_array( $m[2], array( 'places', 'services' ), true ) ) {
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
			$w = (string) ( $b['attrs']['width'] ?? '' );
			$h = (string) ( $b['attrs']['height'] ?? '' );
			if ( $w === '' || $h === '' || ! str_contains( (string) $b['innerHTML'], 'width:' . $w ) || ! str_contains( (string) $b['innerHTML'], 'height:' . $h ) ) {
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
);
foreach ( array_keys( $entries ) as $id ) {
	$why   = '';
	$block = Block_Library::fill( (string) $id, $facts, $why );
	$expect( "$id: it is made for a company that says its phone, its name and its places", $block !== null, $why );
	if ( $block === null ) {
		continue;
	}
	$markup = serialize_block( $block );
	$expect( "$id: no token, no marker, no empty or unfilled address is left in it", ! str_contains( $markup, '{{' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon/', $markup ) && ! preg_match( '/href=""|href="\{\{|"url":"\{\{/', $markup ), substr( (string) preg_replace( '/\s+/', ' ', $markup ), 0, 120 ) );
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
add_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
$why = '';
$expect( 'a theme the sections are not made for gets none', ! Block_Library::theme_ok() && Block_Library::section( 'cta', $facts, 'x', array(), '', $why ) === null, $why );
remove_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
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
add_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
$av_theme = Block_Library::availability( $homes['bare'] );
$route    = $ask( $homes['bare'] );
$expect( 'a site on another theme is told the theme is the reason, with its name, and so is the panel', $av_theme['code'] === 'theme' && $av_theme['theme'] !== '' && ( $route['library']['code'] ?? '' ) === 'theme' && ( $route['library']['theme'] ?? '' ) === $av_theme['theme'], wp_json_encode( array( $av_theme, $route ) ) );
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
add_filter( 'dxai_ui_block_library_themes', '__return_empty_array' );
$expect( 'on a theme the sections are not made for, even asked to prefer them, none is put in a plan (the pages are made from the Home as before)', $lib_places( Team_Pages::plan( $homes['bare'], $wanted, array( 'library' => 'prefer' ) ) ) === array() );
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
	$pattern = $patterns->get_registered( 'dxai-ui/library-' . $id );
	$content = is_array( $pattern ) ? (string) $pattern['content'] : '';
	$parsed  = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	$expect( "$id: a pattern, made for this site when it is asked for: one section, no token, no marker, an address on every link", $content !== '' && count( $parsed ) === 1 && Library_View::has_section( $content ) && ! str_contains( $content, '{{' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon|href=""/', $content ) && ! is_wp_error( Block_Tree::serialize_checked( $parsed ) ), $content === '' ? 'no pattern' : substr( (string) preg_replace( '/\s+/', ' ', $content ), 0, 100 ) );
}
$cta = (string) ( $patterns->get_registered( 'dxai-ui/library-cta-call' )['content'] ?? '' );
$expect( 'a phone the site does not say is a placeholder to edit, never a number made up', preg_match( '/href="tel:\+?\d[\d ()-]*"/', $cta ) === 0 || str_contains( $cta, 'tel:' . preg_replace( '/[^\d+]/', '', (string) ( Block_Library::facts( 0, array( 'phone' => null, 'email' => '', 'address' => '' ) )['phone_url'] ?? '' ) ) ), '' );
$expect( 'and the pattern is the team\'s block as it was written: no Home\'s look on it', ! str_contains( $cta, 'dxs-' ) );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
