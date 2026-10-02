<?php
/**
 * New pages of a design in the team's style, against a Home made for the purpose (no imported design is read or changed):
 * the services and places its Home names, the plan, the pages made from its sections, the links between them, making
 * them again, a page someone edited, and that the words of the header and footer are not page copy.
 *
 *   bash bin/wp-php.sh bin/verify-team-pages.php        (or: wp eval-file bin/verify-team-pages.php --user=1)
 *
 * Makes a Home and its pages and removes them again. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Copy_Writer;
use DXAI_UI\Pages\Page_Frame;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Variants;
use DXAI_UI\Pages\Team_Chrome;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Pages\Team_Quality;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Structure_Repository;

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

$heading = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$button  = static fn( string $t, string $url ): string => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $t . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$section = static fn( string $name, string $class, string $inner ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"},"className":"' . $class . '","tagName":"section"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->';
$card    = static fn( string $t, string $d ): string => '<!-- wp:column {"className":"svc-card"} --><div class="wp-block-column svc-card">' . $heading( $t, 3 ) . $para( $d ) . $button( 'Learn more', '#' ) . '</div><!-- /wp:column -->';
$place   = static fn( string $t ): string => '<!-- wp:group {"className":"place-card"} --><div class="wp-block-group place-card">' . $heading( $t, 3 ) . $para( 'Open 24/7' ) . '</div><!-- /wp:group -->';

$header = '<!-- wp:group {"tagName":"header","className":"site-bar"} --><header class="wp-block-group site-bar"><!-- wp:paragraph --><p>Call (555) 010-0101 · Menu Home About</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$footer = '<!-- wp:group {"tagName":"footer","className":"site-foot"} --><footer class="wp-block-group site-foot"><!-- wp:paragraph --><p>© Fixture Co. All rights reserved.</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$home   = $header
	. $section( 'Hero Section', 'hero-x bg-dark', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration in Tyler', 1 ) . $para( 'Family run since 1983.' ) . $button( 'Call now', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $section( 'Services grid', 'svc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration Services We Provide' ) . $para( 'What we do.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Water Damage', 'Extraction and drying.' ) . $card( 'Fire Damage', 'Soot and smoke.' ) . $card( 'Mold Removal', 'Containment.' ) . '</div><!-- /wp:columns -->' . '</div><!-- /wp:group -->' )
	. $section( 'Locations', 'loc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Where we work' ) . $place( 'Tyler, TX' ) . $place( 'Dallas, TX' ) . '</div><!-- /wp:group -->' )
	. $section( 'Reviews Section', 'rev-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'What customers say' ) . $para( '"Fast and kind." — A customer' ) . '</div><!-- /wp:group -->' )
	. $section( 'FAQ Section', 'faq-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Frequently asked questions' ) . $para( 'How fast can you come?' ) . $para( 'Within the hour.' ) . '</div><!-- /wp:group -->' )
	. $section( 'CTA Banner', 'cta-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Water where it should not be?' ) . $button( 'Call (555) 010-0101', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $footer;

$home_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'Fixture Restoration',
		'post_content' => $home,
	)
);
$made = array( (int) $home_id );
$fin  = static function () use ( &$made ): void {
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
};
if ( ! is_int( $home_id ) || $home_id < 1 ) {
	echo "  FAIL  a Home to test on\n";
	exit( 1 );
}
update_post_meta( $home_id, Page_Scope::META, $home_id );
update_post_meta( $home_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home_id, '_dxai_ui_css_url', 'dxai-ui/fixture-home.css' );

echo "What the Home names\n";
$s = Team_Pages::suggestions( $home_id );
$expect( 'the services its cards list', $s['services'] === array( 'Water Damage', 'Fire Damage', 'Mold Removal' ), json_encode( $s['services'] ) );
$expect( 'the places its cards list ("City, ST")', $s['locations'] === array( 'Tyler, TX', 'Dallas, TX' ), json_encode( $s['locations'] ) );
$expect( 'and the general pages that can be made', array_column( $s['general'], 'type' ) === array( 'about', 'contact', 'faq', 'testimonials', 'services', 'areas' ) );
$expect( 'a place is told from a service', Team_Pages::is_place( 'Revere, MA' ) && ! Team_Pages::is_place( 'Water Damage' ) && ! Team_Pages::is_place( 'Family run since 1983' ) );

echo "\nThe plan\n";
$wanted = array(
	array( 'type' => 'service', 'title' => 'Water Damage' ),
	array( 'type' => 'service', 'title' => 'Fire Damage' ),
	array( 'type' => 'service', 'title' => 'Mold Removal' ),
	array( 'type' => 'location', 'title' => 'Water Damage in Tyler, TX' ),
	array( 'type' => 'about', 'title' => '' ),
	array( 'type' => 'contact', 'title' => '' ),
	array( 'type' => 'services', 'title' => '' ),
	array( 'type' => 'nonsense', 'title' => 'x' ),
	array( 'type' => 'service', 'title' => 'Water Damage' ),
);
$plan = Team_Pages::plan( $home_id, $wanted );
$expect( 'a page for each thing asked for; what is not a kind of page, and a repeat, are dropped', count( $plan ) === 7, (string) count( $plan ) );
$expect( 'the hero is first on every page', count( array_filter( $plan, static fn( $p ) => $p['roles'][0]['role'] !== 'hero' ) ) === 0 );
$expect( 'the same Home gets the same plan', $plan === Team_Pages::plan( $home_id, $wanted ) );
$expect( 'nothing is made by planning', get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 50, 'meta_query' => array( array( 'key' => Team_Pages::META, 'compare' => 'EXISTS' ), array( 'key' => Page_Scope::META, 'value' => (string) $home_id ) ) ) ) === array() );

echo "\nThe pages\n";
$out = Team_Pages::build( $home_id, $wanted );
$expect( 'they are made', ! is_wp_error( $out ) && count( $out['pages'] ) === 7, is_wp_error( $out ) ? $out->get_error_message() : (string) count( $out['pages'] ) );
if ( is_wp_error( $out ) ) {
	$fin();
	exit( 1 );
}
$by = array();
foreach ( $out['pages'] as $p ) {
	$made[]                              = (int) $p['id'];
	$by[ $p['type'] . '|' . sanitize_title( $p['title'] ) ] = (int) $p['id'];
}
$svc = $by['service|water-damage'] ?? 0;
$c   = (string) get_post_field( 'post_content', $svc );
$expect( 'a service page is in the Home\'s look: its sections and their classes', str_contains( $c, 'hero-x' ) && str_contains( $c, 'svc-x' ) && str_contains( $c, 'cta-x' ) );
$expect( 'its hero is headed with its title', preg_match( '/<h1[^>]*>Water Damage<\/h1>/', $c ) === 1, substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $c ) ), 0, 80 ) );
$expect( 'the Home\'s header and footer are on it once', substr_count( $c, '<header' ) === 1 && substr_count( $c, '<footer' ) === 1 );
$expect( 'the page belongs to the design: its scope, template and marker', (int) get_post_meta( $svc, Page_Scope::META, true ) === $home_id && get_post_meta( $svc, '_wp_page_template', true ) === \DXAI_UI\Theme\Blank_Template::SLUG && get_post_meta( $svc, Team_Pages::META, true ) === 'service|water-damage' );
$expect( 'and is not itself a design', ! \DXAI_UI\Structures\Design_Attach::is_design( $svc ) );
$expect( 'the page is blocks all the way, nothing loose between them', count( array_filter( parse_blocks( $c ), static fn( $b ) => empty( $b['blockName'] ) && trim( (string) $b['innerHTML'] ) !== '' ) ) === 0 );
$list = $by['services|services'] ?? 0;
$expect( 'the services sit under the page that lists them', (int) get_post_field( 'post_parent', $svc ) === $list && $list > 0 );
$rel = array_filter(
	$out['log'],
	static fn( $l ) => str_contains( $l, 'related' )
);
$expect( 'the cards that list other pages list the other services', (function () use ( $by, $list ) {
	$lp = (string) get_post_field( 'post_content', $list );
	return str_contains( $lp, (string) get_permalink( $by['service|fire-damage'] ?? 0 ) ) && str_contains( $lp, (string) get_permalink( $by['service|mold-removal'] ?? 0 ) );
} )(), count( $rel ) . ' related log lines' );

echo "\nThe words\n";
$inv = Copy_Writer::inventory( $svc );
$texts = implode( ' ', array_column( $inv, 'text' ) );
$expect( 'the header and footer words are not page copy', ! str_contains( $texts, 'Call (555) 010-0101 · Menu' ) && ! str_contains( $texts, 'All rights reserved' ) );
$expect( 'the page\'s own words are', str_contains( $texts, 'Water Damage' ) );

echo "\nAgain\n";
$again = Team_Pages::build( $home_id, $wanted );
$expect( 'a second time updates the same pages', ! is_wp_error( $again ) && array_column( $again['pages'], 'id' ) === array_column( $out['pages'], 'id' ) );
$expect( 'and makes no new ones', count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 50, 'meta_query' => array( array( 'key' => Team_Pages::META, 'compare' => 'EXISTS' ), array( 'key' => Page_Scope::META, 'value' => (string) $home_id ) ) ) ) ) === 7 );
$before = (string) get_post_field( 'post_content', $by['about|about-us'] );
wp_update_post( array( 'ID' => $by['about|about-us'], 'post_content' => $before . "\n<!-- wp:paragraph --><p>Added by a person.</p><!-- /wp:paragraph -->" ) );
$third = Team_Pages::build( $home_id, $wanted );
$expect( 'a page a person edited is kept', ! is_wp_error( $third ) && count( $third['kept'] ) === 1 && str_contains( (string) get_post_field( 'post_content', $by['about|about-us'] ), 'Added by a person.' ) );
$forced = Team_Pages::build( $home_id, array( array( 'type' => 'about', 'title' => '' ) ), true );
$expect( 'unless it is asked for again on purpose', ! is_wp_error( $forced ) && ! str_contains( (string) get_post_field( 'post_content', $by['about|about-us'] ), 'Added by a person.' ) );

echo "\nWhat is refused\n";
$none = Team_Pages::build( $home_id, array( array( 'type' => 'nonsense', 'title' => 'x' ) ) );
$expect( 'nothing asked for is an error', is_wp_error( $none ) && $none->get_error_code() === 'dxai_ui_team_none' );
$bad = Team_Pages::build( 999999, $wanted );
$expect( 'so is a design that is not one', is_wp_error( $bad ) && $bad->get_error_code() === 'dxai_ui_team_design' );

echo "\nA Home without a hero (a job page's Home is a route: its header band is in the chrome)\n";
$home2_html = $header
	. $section( 'Role', 'role-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'What You Will Own' ) . $para( 'The work.' ) . '</div><!-- /wp:group -->' )
	. $section( 'Requirements', 'req-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Role Requirements' ) . $para( 'The skills.' ) . '</div><!-- /wp:group -->' )
	. $footer;
$home2_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Jobs', 'post_content' => $home2_html ) );
$made[]   = (int) $home2_id;
update_post_meta( $home2_id, Page_Scope::META, $home2_id );
update_post_meta( $home2_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home2_id, '_dxai_ui_css_url', 'dxai-ui/fixture-jobs.css' );
$plan2 = Team_Pages::plan( $home2_id, array( array( 'type' => 'service', 'title' => 'Service One' ) ) );
$expect( 'the hero is not left out: the first section lends its heading', ! in_array( 'hero', $plan2[0]['left_out'] ?? array( 'hero' ), true ), json_encode( $plan2[0]['left_out'] ?? null ) );
$out2 = Team_Pages::build( $home2_id, array( array( 'type' => 'service', 'title' => 'Service One' ) ) );
$p2   = ! is_wp_error( $out2 ) ? (int) ( $out2['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $p2;
$c2   = (string) get_post_field( 'post_content', $p2 );
$expect( 'so the page shows its title', preg_match( '/<h2[^>]*>Service One<\/h2>/', $c2 ) === 1, substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $c2 ) ), 0, 80 ) );
$expect( 'the section keeps its look and its other words', str_contains( $c2, 'role-x' ) && str_contains( $c2, 'Role Requirements' ) );
$home3_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture No Headings', 'post_content' => $header . $section( 'Plain', 'plain-x', $para( 'Only a line of text.' ) ) . $footer ) );
$made[]   = (int) $home3_id;
update_post_meta( $home3_id, Page_Scope::META, $home3_id );
update_post_meta( $home3_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home3_id, '_dxai_ui_css_url', 'dxai-ui/fixture-plain.css' );
$plan3 = Team_Pages::plan( $home3_id, array( array( 'type' => 'service', 'title' => 'Service One' ) ) );
$expect( 'a first section with no heading to carry the title is not used', in_array( 'hero', $plan3[0]['left_out'] ?? array(), true ), json_encode( $plan3[0]['left_out'] ?? null ) );

echo "\nThe designs are found among many pages made for them\n";
// The pages made for a design are generated pages too; the Home used to be looked for among the newest hundred of them.
for ( $i = 0; $i < 105; $i++ ) {
	$extra = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Fixture made page ' . $i,
			'meta_input'  => array(
				'_dxai_ui_generated_page' => '1',
				Page_Scope::META          => (string) $home_id,
			),
		)
	);
	$made[] = (int) $extra;
}
$homes = \DXAI_UI\Structures\Design_Attach::home_ids( 100 );
$expect( 'the Home is listed although 105 newer pages were made for it', in_array( $home_id, $homes, true ) );
$expect( 'and a page made for a design is not', count( array_intersect( $homes, array_slice( $made, -105 ) ) ) === 0 );
if ( \DXAI_UI\Structures\Design_Attach::is_design( $home_id ) ) {
	$expect( 'designs() lists it too', in_array( $home_id, array_map( static fn( $p ) => (int) $p->ID, \DXAI_UI\Structures\Design_Attach::designs() ), true ) );
}

echo "\nA page that is not what its name says without one section\n";
$qa    = Team_Pages::build( $home_id, array( array( 'type' => 'faq', 'title' => '' ) ) );
$qid   = ! is_wp_error( $qa ) ? (int) ( $qa['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $qid;
$qc    = (string) get_post_field( 'post_content', $qid );
$expect( 'the FAQ page has the Home\'s questions', str_contains( $qc, 'faq-x' ) && str_contains( $qc, 'How fast can you come?' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $qc ) ), 0, 100 ) );
$expect( 'before the closing call to action', ! str_contains( $qc, 'cta-x' ) || strpos( $qc, 'faq-x' ) < strpos( $qc, 'cta-x' ) );
$qb    = Team_Pages::build( $home2_id, array( array( 'type' => 'faq', 'title' => '' ) ) );
$qbid  = ! is_wp_error( $qb ) ? (int) ( $qb['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $qbid;
$expect( 'a Home with no questions gets no section for them: the page is what the Home can make', $qbid > 0 && (int) ( $qb['pages'][0]['sections'] ?? 9 ) <= 2, json_encode( $qb['pages'][0]['sections'] ?? null ) );
$home4_html = $header
	. $section( 'Hero Section', 'hero4-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration in Tyler', 1 ) . $para( 'Family run.' ) . '</div><!-- /wp:group -->' )
	. $section( 'Contact form', 'form4-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Request an estimate' ) . '<!-- wp:html --><form action="#"><input name="name"><input name="phone"></form><!-- /wp:html --></div><!-- /wp:group -->' )
	. $footer;
$home4_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture With A Form', 'post_content' => $home4_html ) );
$made[]   = (int) $home4_id;
update_post_meta( $home4_id, Page_Scope::META, $home4_id );
update_post_meta( $home4_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home4_id, '_dxai_ui_css_url', 'dxai-ui/fixture-form.css' );
$qd    = Team_Pages::build( $home4_id, array( array( 'type' => 'contact', 'title' => '' ) ) );
$qdid  = ! is_wp_error( $qd ) ? (int) ( $qd['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $qdid;
$dc    = (string) get_post_field( 'post_content', $qdid );
$expect( 'the contact page has the Home\'s form', str_contains( $dc, 'form4-x' ) && str_contains( $dc, '<form' ), (string) ( is_wp_error( $qd ) ? $qd->get_error_message() : implode( ',', $qd['pages'][0]['roles'] ?? array() ) ) );
$expect( 'right after the hero', strpos( $dc, 'hero4-x' ) < strpos( $dc, 'form4-x' ) );

$ar    = Team_Pages::build( $home_id, array( array( 'type' => 'areas', 'title' => '' ) ) );
$arid  = ! is_wp_error( $ar ) ? (int) ( $ar['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $arid;
$arc   = (string) get_post_field( 'post_content', $arid );
$expect( 'the page that lists the service areas opens with the Home\'s areas, not a list of services', str_contains( $arc, 'loc-x' ) && ! str_contains( $arc, 'svc-x' ), implode( ',', $ar['pages'][0]['roles'] ?? array() ) );

echo "\nThe menu: the header and footer links lead to the pages\n";
$mlink = static fn( string $t, string $u ): string => '<!-- wp:dxai-ui/link {"url":"' . $u . '","text":"' . $t . '"} --><a class="wp-block-dxai-ui-link" href="' . $u . '">' . $t . '</a><!-- /wp:dxai-ui/link -->';
$header5 = '<!-- wp:group {"tagName":"header","className":"site-bar"} --><header class="wp-block-group site-bar"><!-- wp:group {"tagName":"nav"} --><nav class="wp-block-group">'
	. $mlink( 'Water Damage', 'https://old.example.com/services/water' )
	. $mlink( 'About', '#about' )
	. $mlink( 'Contact', 'https://old.example.com/contact' )
	. $mlink( 'Careers', 'https://old.example.com/careers' )
	. $mlink( 'Call (555) 010-0101', 'tel:5550100101' )
	. '</nav><!-- /wp:group --></header><!-- /wp:group -->';
$footer5 = '<!-- wp:group {"tagName":"footer","className":"site-foot"} --><footer class="wp-block-group site-foot"><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li><a href="https://old.example.com/faq">FAQ</a></li><!-- /wp:list-item --><!-- wp:list-item --><li><a href="https://old.example.com/privacy">Privacy Policy</a></li><!-- /wp:list-item --></ul><!-- /wp:list --></footer><!-- /wp:group -->';
$card5   = static fn( string $t, string $u ): string => '<!-- wp:dxai-ui/link {"url":"' . $u . '","className":"card","hasInner":true} --><a class="wp-block-dxai-ui-link card" href="' . $u . '"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $t . '</h3><!-- /wp:heading --></a><!-- /wp:dxai-ui/link -->';
$list5   = static fn( array $cards ): string => '<!-- wp:group {"className":"cards5-x"} --><div class="wp-block-group cards5-x">' . implode( '', $cards ) . '</div><!-- /wp:group -->';
$cards5  = $list5( array( $card5( 'Water Damage', 'https://old.example.com/w' ), $card5( 'Fire Damage', 'https://old.example.com/f' ), $card5( 'Roof Repair', 'https://old.example.com/r' ) ) )
	. $list5( array( $card5( 'Gutter Help', 'https://old.example.com/g' ), $card5( 'Tree Help', 'https://old.example.com/t' ) ) );
$home5 = serialize_blocks( parse_blocks( str_replace( array( $header, $footer ), array( $header5, $cards5 . $footer5 ), $home ) ) );
$home5_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture With A Menu', 'post_content' => wp_slash( $home5 ) ) );
$made[]   = (int) $home5_id;
update_post_meta( $home5_id, Page_Scope::META, $home5_id );
update_post_meta( $home5_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home5_id, '_dxai_ui_css_url', 'dxai-ui/fixture-menu.css' );
$mb = Team_Pages::build(
	$home5_id,
	array(
		array( 'type' => 'service', 'title' => 'Water Damage Restoration' ),
		array( 'type' => 'about', 'title' => '' ),
		array( 'type' => 'contact', 'title' => '' ),
		array( 'type' => 'faq', 'title' => '' ),
		array( 'type' => 'services', 'title' => '' ),
	)
);
$mp = array();
foreach ( ! is_wp_error( $mb ) ? $mb['pages'] : array() as $p ) {
	$made[]                  = (int) $p['id'];
	$mp[ $p['type'] ] = (int) $p['id'];
}
$hrefs = static function ( int $id ): array {
	$out = array();
	$walk = static function ( array $bs ) use ( &$walk, &$out ): void {
		foreach ( $bs as $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			if ( preg_match( '/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', (string) $b['innerHTML'], $m ) === 1 ) {
				$out[ trim( wp_strip_all_tags( $m[2] ) ) ] = array( html_entity_decode( $m[1] ), (string) ( $b['attrs']['url'] ?? '' ) );
			}
			$walk( (array) $b['innerBlocks'] );
		}
	};
	$walk( parse_blocks( (string) get_post_field( 'post_content', $id ) ) );

	return $out;
};
$on_home = $hrefs( $home5_id );
$expect( 'the build says how many links it pointed', ! is_wp_error( $mb ) && ( $mb['menu']['links'] ?? 0 ) >= 4, json_encode( $mb['menu'] ?? null ) );
$expect( 'a link named like a service leads to its page', ( $on_home['Water Damage'][0] ?? '' ) === get_permalink( $mp['service'] ?? 0 ), json_encode( $on_home['Water Damage'] ?? null ) );
$expect( 'a section of the Home ("About") leads to the About page', ( $on_home['About'][0] ?? '' ) === get_permalink( $mp['about'] ?? 0 ) );
$expect( 'a contact link to the Contact page, a footer list item to the FAQ page', ( $on_home['Contact'][0] ?? '' ) === get_permalink( $mp['contact'] ?? 0 ) && ( $on_home['FAQ'][0] ?? '' ) === get_permalink( $mp['faq'] ?? 0 ) );
$expect( 'a link to a page that was not made, a phone number and the privacy link stay', ( $on_home['Careers'][0] ?? '' ) === 'https://old.example.com/careers' && ( $on_home['Call (555) 010-0101'][0] ?? '' ) === 'tel:5550100101' && ( $on_home['Privacy Policy'][0] ?? '' ) === 'https://old.example.com/privacy' );
$expect( 'a link block says the same in its markup and in its url (or the editor opens it as invalid)', ( $on_home['Water Damage'][0] ?? 'a' ) === ( $on_home['Water Damage'][1] ?? 'b' ) );
$on_page = $hrefs( $mp['service'] ?? 0 );
$expect( 'the pages made carry the same menu', ( $on_page['About'][0] ?? '' ) === get_permalink( $mp['about'] ?? 0 ) && ( $on_page['FAQ'][0] ?? '' ) === get_permalink( $mp['faq'] ?? 0 ) );
$expect( 'links in the body of a page are not the menu', ( $hrefs( $mp['service'] ?? 0 )['Learn more'][0] ?? '#' ) === '#' );
$cards_of = static function ( int $id ): array {
	$out  = array();
	$walk = static function ( array $bs ) use ( &$walk, &$out ): void {
		foreach ( $bs as $b ) {
			if ( ( $b['blockName'] ?? '' ) === 'dxai-ui/link' && ! empty( $b['innerBlocks'] ) ) {
				$h = (array) $b['innerBlocks'][0];
				$out[ trim( wp_strip_all_tags( (string) ( $h['innerHTML'] ?? '' ) ) ) ] = (string) ( $b['attrs']['url'] ?? '' );
			}
			$walk( (array) ( $b['innerBlocks'] ?? array() ) );
		}
	};
	$walk( parse_blocks( (string) get_post_field( 'post_content', $id ) ) );

	return $out;
};
$ch5 = $cards_of( $home5_id );
$expect( 'a card whose heading is a page\'s opens that page', ( $ch5['Water Damage'] ?? '' ) === get_permalink( $mp['service'] ?? 0 ), json_encode( $ch5 ) );
$expect( 'cards of the same list that have no page open the page that lists the services', ( $ch5['Fire Damage'] ?? '' ) === get_permalink( $mp['services'] ?? 0 ) && ( $ch5['Roof Repair'] ?? '' ) === get_permalink( $mp['services'] ?? 0 ), json_encode( $ch5 ) );
$expect( 'a list of cards none of which has a page is left as it is', ( $ch5['Gutter Help'] ?? '' ) === 'https://old.example.com/g' && ( $ch5['Tree Help'] ?? '' ) === 'https://old.example.com/t', json_encode( $ch5 ) );
$expect( 'a card block says the same in its markup and its url', (bool) preg_match( '/href="' . preg_quote( get_permalink( $mp['service'] ?? 0 ), '/' ) . '"/', (string) get_post_field( 'post_content', $home5_id ) ) );
$again = \DXAI_UI\Pages\Team_Menu::link( $home5_id );
$expect( 'pointing them again changes nothing', $again === array( 'pages' => 0, 'links' => 0 ), json_encode( $again ) );
// The words of a page written by the copy writer can still be put back after the menu was pointed again.
$svc5   = $mp['service'] ?? 0;
$inv5   = Copy_Writer::inventory( $svc5 );
$w5     = Copy_Writer::apply( $svc5, array( $inv5[0]['id'] => 'New words' ), Copy_Writer::fingerprint( $inv5 ) );
$cur5   = (string) get_post_field( 'post_content', $svc5 );
$reset5 = str_replace( 'href="' . get_permalink( $mp['about'] ?? 0 ) . '"', 'href="https://old.example.com/about"', $cur5 );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => $reset5 ), array( 'ID' => $svc5 ) );
clean_post_cache( $svc5 );
update_post_meta( $svc5, Copy_Writer::HASH, md5( $reset5 ) );
$fix5 = \DXAI_UI\Pages\Team_Menu::link( $home5_id );
$expect( 'a link put back to the old site is pointed again', $fix5['links'] >= 1, json_encode( $fix5 ) );
$expect( 'and the page\'s earlier words can still be put back', Copy_Writer::can_revert( $svc5 ) && Copy_Writer::revert( $svc5 ) === true );

echo "\nThe Home's header and footer, on every page\n";
global $wpdb;
$expect( 'the build says it put the Home\'s header and footer on the pages (none had to change: they were just copied)', isset( $out['chrome'] ) && $out['chrome'] === 0, json_encode( $out['chrome'] ?? null ) );
$expect( 'a saved Home is followed by its pages (the hook)', has_action( 'save_post_page', array( Team_Chrome::class, 'on_save' ) ) !== false );
$home_now = (string) get_post_field( 'post_content', $home_id );
$wpdb->update( $wpdb->posts, array( 'post_content' => str_replace( 'Menu Home', 'Menu Away', $home_now ) ), array( 'ID' => $home_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
clean_post_cache( $home_id );
$before_q = Team_Quality::measure( $home_id );
$expect( 'the Home\'s header changed and the pages still have the old one (found by G1)', count( $before_q['gates']['G1'] ) >= 7, (string) count( $before_q['gates']['G1'] ) );
Team_Chrome::on_save( $home_id );
$after_q = Team_Quality::measure( $home_id );
$expect( 'once the Home is saved, every page has the new header', $after_q['gates']['G1'] === array() && str_contains( (string) get_post_field( 'post_content', $svc ), 'Menu Away' ) && ! str_contains( (string) get_post_field( 'post_content', $svc ), 'Menu Home' ) );
$expect( 'the middle of the page is as it was', str_contains( (string) get_post_field( 'post_content', $svc ), 'svc-x' ) && preg_match( '/<h1[^>]*>Water Damage<\/h1>/', (string) get_post_field( 'post_content', $svc ) ) === 1 );
$expect( 'a page nobody edited is not an edited page after it', ! (bool) Team_Pages::plan( $home_id, array( array( 'type' => 'service', 'title' => 'Water Damage' ) ) )[0]['edited'] );
$again = Team_Chrome::sync( $home_id );
$expect( 'doing it again changes nothing', $again['pages'] === 0 && $again['skipped'] === array(), json_encode( $again ) );
$kept = (string) get_post_field( 'post_content', $by['about|about-us'] );
$no_header = implode( "\n\n", array_map( 'serialize_block', array_slice( array_values( array_filter( parse_blocks( $kept ), static fn( $b ) => ! empty( $b['blockName'] ) ) ), 2 ) ) );
$wpdb->update( $wpdb->posts, array( 'post_content' => $no_header ), array( 'ID' => $by['about|about-us'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
clean_post_cache( $by['about|about-us'] );
$skip = Team_Chrome::sync( $home_id );
$expect( 'a page whose beginning is not a header (somebody took it out) is left alone, and named', in_array( $by['about|about-us'], $skip['skipped'], true ) && (string) get_post_field( 'post_content', $by['about|about-us'] ) === $no_header );
$wpdb->update( $wpdb->posts, array( 'post_content' => $kept ), array( 'ID' => $by['about|about-us'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
clean_post_cache( $by['about|about-us'] );
Team_Chrome::sync( $home_id );

echo "\nThe Home's frame (the group around its header, main and footer)\n";
$expect( 'a Home that has no wrapper has pages that have none', Page_Frame::frame_key( parse_blocks( (string) get_post_field( 'post_content', $svc ) ) ) === '' );
$wrapped_home = '<!-- wp:group {"className":"max-w-full","dxaiCss":"overflow-x:clip"} --><div class="wp-block-group max-w-full dxs-vtxwrap">'
	. $header
	. '<!-- wp:group {"tagName":"main","anchor":"main"} --><main id="main" class="wp-block-group">' . str_replace( array( $header, $footer ), '', $home ) . '</main><!-- /wp:group -->'
	. $footer
	. '</div><!-- /wp:group -->';
$wh = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Wrapped', 'post_content' => wp_slash( $wrapped_home ) ) );
$made[] = (int) $wh;
update_post_meta( $wh, Page_Scope::META, $wh );
update_post_meta( $wh, '_dxai_ui_generated_page', '1' );
update_post_meta( $wh, '_dxai_ui_css_url', 'dxai-ui/fixture-wrapped.css' );
$shells = Page_Frame::shells( $wh );
$expect( 'the Home\'s wrapper and main are found, empty', $shells['wrapper'] !== null && $shells['main'] !== null && $shells['wrapper']['innerBlocks'] === array() && ( $shells['wrapper']['attrs']['dxaiCss'] ?? '' ) === 'overflow-x:clip' );
$wb = Team_Pages::build( $wh, array( array( 'type' => 'service', 'title' => 'Water Damage' ), array( 'type' => 'about', 'title' => '' ) ) );
$wp = array();
foreach ( ! is_wp_error( $wb ) ? $wb['pages'] : array() as $p ) {
	$made[]            = (int) $p['id'];
	$wp[ $p['type'] ] = (int) $p['id'];
}
$wc = (string) get_post_field( 'post_content', $wp['service'] ?? 0 );
$wtop = array_values( array_filter( parse_blocks( $wc ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$expect( 'the pages are made in the Home\'s frame: one group around everything, as the Home has it', count( $wtop ) === 1 && ( $wtop[0]['attrs']['dxaiCss'] ?? '' ) === 'overflow-x:clip' && str_contains( $wc, 'dxs-vtxwrap' ), (string) count( $wtop ) );
$expect( 'the header, the main with the sections, and the footer are inside it, once each', substr_count( $wc, '<header' ) === 1 && substr_count( $wc, '<footer' ) === 1 && substr_count( $wc, '<main' ) === 1 && str_contains( $wc, 'svc-x' ) && str_contains( $wc, 'cta-x' ) );
$expect( 'a page in the Home\'s frame is not given a second header and footer when it is shown (Page_Chrome reads it as a design is read)', \DXAI_UI\Chrome\Page_Chrome::with_chrome( $wp['service'] ?? 0, $wc ) === $wc && \DXAI_UI\Chrome\Page_Chrome::already_has_chrome( $wc ) );
$expect( 'the page still reads as sections', count( Section_Library::for_page( $wp['service'] ?? 0 ) ) >= 4, (string) count( Section_Library::for_page( $wp['service'] ?? 0 ) ) );
$wq = Team_Quality::measure( $wh );
$expect( 'the quality measure finds the header, the footer and the frame the Home\'s', $wq['gates']['G1'] === array() && count( $wq['pages'] ) === 2, json_encode( $wq['gates']['G1'] ) );
// A page made before the frame existed: flat. It is found, and given the frame.
$legacy = Page_Frame::split( parse_blocks( $wc ), 2, 1 );
$flat   = implode( "\n\n", array_map( 'serialize_block', array_merge( $legacy['head'], $legacy['middle'], $legacy['tail'] ) ) );
$wpdb->update( $wpdb->posts, array( 'post_content' => $flat ), array( 'ID' => $wp['service'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
clean_post_cache( $wp['service'] );
$expect( 'a flat page of a wrapped Home is found by G1', str_contains( implode( ';', Team_Quality::measure( $wh )['gates']['G1'][ $wp['service'] ] ?? array() ), 'frame' ) );
$sync = Team_Chrome::sync( $wh );
$expect( 'and the sync gives it the frame', $sync['pages'] === 1 && Team_Quality::measure( $wh )['gates']['G1'] === array() && Page_Frame::frame_key( parse_blocks( (string) get_post_field( 'post_content', $wp['service'] ) ) ) !== '', json_encode( $sync ) );
$expect( 'once more changes nothing', Team_Chrome::sync( $wh ) === array( 'pages' => 0, 'skipped' => array() ) );

echo "\nThe measure of the pages (Team_Quality)\n";
$q = Team_Quality::measure( $home_id );
$expect( 'the pages made for the design are the ones measured', count( $q['pages'] ) >= 7 && in_array( $svc, array_column( $q['pages'], 'id' ), true ), (string) count( $q['pages'] ) );
$expect( 'pages made here keep the Home\'s header and footer, have valid blocks and nothing foreign', $q['gates']['G1'] === array() && $q['gates']['G7'] === array() && $q['gates']['G9'] === array(), json_encode( array( $q['gates']['G1'], $q['gates']['G7'], $q['gates']['G9'] ) ) );
$sec = array_values( array_filter( $q['pages'], static fn( $p ) => (int) $p['id'] === $svc ) )[0]['sections'] ?? array();
$expect( 'each section says what it is, where it comes from in the Home, and what was done to it', $sec !== array() && count( array_filter( $sec, static fn( $x ) => $x['role'] !== '' && in_array( $x['origin'], array( 'home', 'derived' ), true ) ) ) === count( $sec ) );
$expect( 'pages that are the Home\'s sections and little else are told so (G8)', isset( $q['gates']['G8'][ $svc ] ) );
$original = (string) get_post_field( 'post_content', $svc );
$tamper   = static function ( string $content ) use ( $svc ): void {
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $svc ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	clean_post_cache( $svc );
};
$find = static function ( string $gate ) use ( $home_id, $svc ): string {
	return implode( '; ', Team_Quality::measure( $home_id )['gates'][ $gate ][ $svc ] ?? array() );
};
$added = static fn( string $html ): string => $original . "\n<!-- wp:paragraph -->" . $html . "<!-- /wp:paragraph -->";
$tamper( str_replace( 'Menu Away', 'Menu Elsewhere', $original ) );
$expect( 'G1: a header that is not the Home\'s is found', str_contains( $find( 'G1' ), 'header differs' ), $find( 'G1' ) );
$tamper( $original . "\n<!-- wp:paragraph --><p>The end</p><!-- /wp:paragraph -->" );
$expect( 'G1: a footer that is not the last thing on the page is found', str_contains( $find( 'G1' ), 'footer differs' ), $find( 'G1' ) );
$tamper( $added( '<p>Call (214) 555-0199 today</p>' ) );
$expect( 'G9: a phone number the Home does not have is found', str_contains( $find( 'G9' ), 'phones' ), $find( 'G9' ) );
$tamper( $added( '<p>Write to info@elsewhere.example</p>' ) );
$expect( 'G9: an e-mail address the Home does not have is found', str_contains( $find( 'G9' ), 'mails' ), $find( 'G9' ) );
$tamper( $added( '<p><a href="https://other-company.example/page">more</a></p>' ) );
$expect( 'G9: an address of another site is found', str_contains( $find( 'G9' ), 'addresses of other sites' ), $find( 'G9' ) );
$tamper( $added( '<p>Lorem ipsum dolor sit amet</p>' ) );
$expect( 'G9: placeholder text is found', str_contains( $find( 'G9' ), 'placeholder' ), $find( 'G9' ) );
$tamper( $added( '<p>As seen at Fixture With A Menu</p>' ) );
$expect( 'G9: the name of another design on the site is found', str_contains( $find( 'G9' ), 'another design' ), $find( 'G9' ) );
$tamper( $added( '<p style="color:red">Red</p>' ) );
$expect( 'G7: an inline style the Home does not have is found', str_contains( $find( 'G7' ), 'inline style' ), $find( 'G7' ) );
$tamper( $original . "\n<!-- wp:vtx/not-a-block /-->" );
$expect( 'G7: a block that is not registered is found', str_contains( $find( 'G7' ), 'unregistered' ), $find( 'G7' ) );
$tamper( $original );
$expect( 'put back, the page is clean again', Team_Quality::measure( $home_id )['gates']['G1'] === array() && ( Team_Quality::measure( $home_id )['gates']['G9'][ $svc ] ?? array() ) === array() );

echo "\nThe sections are varied (Section_Variants)\n";
$ops_now = json_decode( (string) get_post_meta( $svc, Team_Pages::OPS_META, true ), true );
$sec_now = Section_Library::for_page( $svc );
$expect( 'what was done to each section is kept with the page: one list for each', is_array( $ops_now ) && count( $ops_now ) === count( $sec_now ), json_encode( $ops_now ) . ' / ' . count( $sec_now ) );
$sv_before = (string) get_post_field( 'post_content', $svc );
Team_Pages::build( $home_id, $wanted, true );
$expect( 'making a page again makes the same page (the variants are the seed\'s)', (string) get_post_field( 'post_content', $svc ) === $sv_before );
$ops_a = json_decode( (string) get_post_meta( $by['service|water-damage'], Team_Pages::OPS_META, true ), true );
$ops_b = json_decode( (string) get_post_meta( $by['service|fire-damage'] ?? 0, Team_Pages::OPS_META, true ), true );
$expect( 'two pages of a kind are made differently', is_array( $ops_b ) && ( $ops_a !== $ops_b || (string) get_post_field( 'post_content', $by['service|water-damage'] ) !== (string) get_post_field( 'post_content', $by['service|fire-damage'] ) ) );
$expect( 'a design that cannot be asked about its places (no stylesheet) moves nothing but is still varied by what is safe', Section_Variants::positional_classes( $home_id ) === null );

echo "\nWhose page it is\n";
$slug_svc = (string) get_post_field( 'post_name', $svc );
$expect( 'a page made here has a key of its own', get_post_meta( $svc, Team_Pages::KEY_META, true ) === Team_Pages::page_key( $home_id, 'service', 'water-damage' ), (string) get_post_meta( $svc, Team_Pages::KEY_META, true ) );
$repo = new Structure_Repository();
$ref  = new ReflectionMethod( $repo, 'existing_page' );
$ref->setAccessible( true );
$expect( 'an import of another design with the same address (and a key of its own) does not take it', $ref->invoke( $repo, $slug_svc, 'Other Design.zip#' . $slug_svc ) === null );
$expect( '… nor an import that has no key to give', $ref->invoke( $repo, $slug_svc, '' ) === null );
$plain = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Vtx plain page', 'post_name' => 'vtx-plain-page', 'post_content' => '' ) );
$made[] = (int) $plain;
$taken_by = $ref->invoke( $repo, 'vtx-plain-page', 'Other Design.zip#vtx-plain-page' );
$expect( 'an ordinary page without a key is still adopted, as before', $taken_by instanceof WP_Post && (int) $taken_by->ID === (int) $plain );

// The page an import did take (an earlier version let it): its team marker no longer lets the build write over it.
$about = $by['about|about-us'] ?? 0;
$was   = (string) get_post_field( 'post_content', $about );
update_post_meta( $about, Team_Pages::KEY_META, 'Other Design.zip#about-us' );
$rebuilt = Team_Pages::build( $home_id, array( array( 'type' => 'about', 'title' => '' ) ), true );
$new_id  = ! is_wp_error( $rebuilt ) ? (int) ( $rebuilt['pages'][0]['id'] ?? 0 ) : 0;
$made[]  = $new_id;
$expect( 'a page an import took is not written over: the design gets a page of its own', $new_id > 0 && $new_id !== (int) $about, (string) $new_id );
$expect( '… and the page the import took is as it was', (string) get_post_field( 'post_content', $about ) === $was );
$expect( '… and the new one is the design\'s', get_post_meta( $new_id, Team_Pages::KEY_META, true ) === Team_Pages::page_key( $home_id, 'about', 'about-us' ) && (int) get_post_meta( $new_id, Page_Scope::META, true ) === $home_id );

// The upgrade: the pages made before the key existed get one, and the marker leaves the page an import took.
delete_post_meta( $svc, Team_Pages::KEY_META );
$claimed = Team_Pages::claim();
$expect( 'the upgrade gives a page made before the key existed its key', get_post_meta( $svc, Team_Pages::KEY_META, true ) === Team_Pages::page_key( $home_id, 'service', 'water-damage' ) && $claimed['keyed'] >= 1, json_encode( $claimed ) );
$expect( 'and takes the team marker off the page an import took', $claimed['released'] >= 1 && get_post_meta( $about, Team_Pages::META, true ) === '' && get_post_meta( $about, Team_Pages::HASH, true ) === '' );
$expect( 'its key (the import\'s) stays', get_post_meta( $about, Team_Pages::KEY_META, true ) === 'Other Design.zip#about-us' );
$again2 = Team_Pages::claim();
$expect( 'doing it again changes nothing', $again2 === array( 'keyed' => 0, 'released' => 0 ), json_encode( $again2 ) );

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
