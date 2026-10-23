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
// The checks are of the pages made in the Home's own look (the library where the Home has nothing); the team's layouts, the usual
// where the library holds them, are checked at the end.
add_filter( 'dxai_ui_team_library', static fn() => 'fill' );

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

echo "\nThe network's order (asked for; or on a site whose network was read: Network\\Network_Model::recipe)\n";
$net  = Team_Pages::plan( $home_id, $wanted, array( 'recipe' => 'network' ) );
$seq  = static fn( array $p ): string => implode( ' ', array_column( $p['roles'], 'role' ) );
$svcs = array_values( array_filter( $net, static fn( $p ) => $p['type'] === 'service' ) );
$want = DXAI_UI\Network\Network_Model::recipe( 'service' );
$expect( 'every service page has the same order, as the team\'s services are made from one page', count( $svcs ) >= 2 && count( array_unique( array_map( $seq, $svcs ) ) ) === 1, implode( ' | ', array_map( $seq, $svcs ) ) );
$expect( '…the network\'s order of a service page (hero first, its questions, the list of the other services before them)', $want !== null && $svcs !== array() && $svcs[0]['roles'][0]['role'] === 'hero' && in_array( 'faq', array_column( $svcs[0]['roles'], 'role' ), true ) && is_int( array_search( 'related', array_column( $svcs[0]['roles'], 'role' ), true ) ) && array_search( 'related', array_column( $svcs[0]['roles'], 'role' ), true ) < array_search( 'faq', array_column( $svcs[0]['roles'], 'role' ), true ), $svcs !== array() ? $seq( $svcs[0] ) : '' );
$expect( 'not asked for, and no network read here: the usual plan, as it was', DXAI_UI\Network\Network_Model::stored() !== null || Team_Pages::plan( $home_id, $wanted ) === $plan );
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
$expect( 'the page belongs to the design: its scope, template and marker', (int) get_post_meta( $svc, Page_Scope::META, true ) === $home_id && get_post_meta( $svc, '_wp_page_template', true ) === \DXAI_UI\Theme\Blank_Template::default_slug() && get_post_meta( $svc, Team_Pages::META, true ) === 'service|water-damage' );
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
$learn = (string) ( $hrefs( $mp['service'] ?? 0 )['Learn more'][0] ?? '#' );
$expect( 'links in the body of a page are not the menu: a card\'s button is the Home\'s own (#) or leads to a page made here, never to the old site', $learn === '#' || $learn === (string) get_permalink( $mp['services'] ?? 0 ) || in_array( $learn, array_map( 'get_permalink', array_values( $mp ) ), true ), $learn );
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
$tamper( $added( '<p><a href="#a">One <a href="#b">two</a></a></p>' ) );
$expect( 'G7: a link inside a link is found', str_contains( $find( 'G7' ), 'links inside links' ), $find( 'G7' ) );
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

echo "\nSections the Home does not have, made from what it has (Section_Blueprints)\n";
$pg = (array) $out['pages'];
$repeated = array();
$jammed   = array();
foreach ( $pg as $p ) {
	$seen = array();
	$last = '';
	foreach ( array_values( Section_Library::for_page( (int) $p['id'] ) ) as $i => $c ) {
		$h = md5( serialize_block( $c['block'] ) );
		if ( $h === $last ) {
			$jammed[] = $p['title'];
		}
		if ( isset( $seen[ $h ] ) && \DXAI_UI\Pages\Section_Roles::of( $c['block'], $i === 0 ) !== 'cta' ) {
			$repeated[] = $p['title'];
		}
		$seen[ $h ] = true;
		$last       = $h;
	}
}
$expect( 'no section is on a page twice in a row', $jammed === array(), implode( ', ', array_unique( $jammed ) ) );
$expect( 'and a section of the Home is not repeated on a page (a call to action may be: it closes more than one stretch)', $repeated === array(), implode( ', ', array_unique( $repeated ) ) );

$about_c = (string) get_post_field( 'post_content', $by['about|about-us'] ?? 0 );
$expect( 'the About page ends with the site\'s services, in the Home\'s cards', str_contains( $about_c, (string) get_permalink( $by['service|water-damage'] ?? 0 ) ) && str_contains( $about_c, 'svc-card' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $about_c ) ), 0, 120 ) );
$plan_r = Team_Pages::plan( $home_id, array( array( 'type' => 'about', 'title' => '' ) ) );
$expect( 'the plan says so before anything is made: a list of the site\'s pages, from the Home\'s cards', in_array( 'related', array_column( $plan_r[0]['roles'], 'role' ), true ), json_encode( array_column( $plan_r[0]['roles'], 'role' ) ) );
$expect( 'the section of a page that lists the pages wears nothing but the Home\'s classes', str_contains( $about_c, 'svc-card' ) && ! preg_match( '/class="[^"]*\b(?:bg-red|text-red)/', $about_c ) );

$home6_html = str_replace( '© Fixture Co. All rights reserved.', '© Fixture Co. 12 Elm St, Tyler, TX. All rights reserved.', $home );
$home6_id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture With Contacts', 'post_content' => wp_slash( $home6_html ) ) );
$made[]     = (int) $home6_id;
update_post_meta( $home6_id, Page_Scope::META, $home6_id );
update_post_meta( $home6_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home6_id, '_dxai_ui_css_url', 'dxai-ui/fixture-contacts.css' );
$cb   = Team_Pages::build( $home6_id, array( array( 'type' => 'contact', 'title' => '' ) ) );
$cbid = ! is_wp_error( $cb ) ? (int) ( $cb['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $cbid;
$cc   = (string) get_post_field( 'post_content', $cbid );
$expect( 'a Home that states a phone number and an address gets the ways to reach it on its contact page', str_contains( $cc, 'Get in touch' ) && str_contains( $cc, '(555) 010-0101' ) && str_contains( $cc, '12 Elm St, Tyler, TX' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $cc ) ), 0, 200 ) );
$expect( 'the phone leads to a call, the address leads nowhere', str_contains( $cc, 'href="tel:5550100101"' ) && ! preg_match( '/<a[^>]*>[^<]*12 Elm St/', $cc ) );
$expect( 'in the Home\'s cards, after the hero', str_contains( $cc, 'svc-card' ) && strpos( $cc, 'hero-x' ) < strpos( $cc, 'Get in touch' ) );
$expect( 'what the Home says nothing of is not made up: no e-mail', ! str_contains( $cc, 'Email us' ) && ! str_contains( $cc, 'mailto:' ) );
$cq = Team_Quality::measure( $home6_id );
$expect( 'the page is measured clean (the Home\'s header and footer, valid blocks, nothing foreign)', ( $cq['gates']['G1'][ $cbid ] ?? array() ) === array() && ( $cq['gates']['G7'][ $cbid ] ?? array() ) === array() && ( $cq['gates']['G9'][ $cbid ] ?? array() ) === array(), json_encode( array( $cq['gates']['G1'][ $cbid ] ?? null, $cq['gates']['G7'][ $cbid ] ?? null, $cq['gates']['G9'][ $cbid ] ?? null ) ) );
$cb2 = Team_Pages::build( $home_id, array( array( 'type' => 'contact', 'title' => '' ) ), true );
$cb2id = ! is_wp_error( $cb2 ) ? (int) ( $cb2['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $cb2id;
$expect( 'a Home that states only a phone number gets no such section (one way to reach it is not a set of cards)', $cb2id > 0 && ! str_contains( (string) get_post_field( 'post_content', $cb2id ), 'Get in touch' ) );

echo "\nRicher pages: the lists, the lead of a service, the ways to reach on About\n";
$many = Team_Pages::build(
	$home_id,
	array(
		array( 'type' => 'service', 'title' => 'Water Damage' ),
		array( 'type' => 'service', 'title' => 'Fire Damage' ),
		array( 'type' => 'service', 'title' => 'Mold Removal' ),
		array( 'type' => 'service', 'title' => 'Roof Repair' ),
		array( 'type' => 'service', 'title' => 'Gutter Help' ),
		array( 'type' => 'service', 'title' => 'Tree Care' ),
		array( 'type' => 'services', 'title' => '' ),
	),
	true
);
$mm = array();
foreach ( ! is_wp_error( $many ) ? $many['pages'] : array() as $p ) {
	$mm[ $p['type'] . '|' . $p['title'] ] = (int) $p['id'];
	$made[]                               = (int) $p['id'];
}
$list_c = (string) get_post_field( 'post_content', $mm['services|Services'] ?? 0 );
$missing = array();
foreach ( array( 'Water Damage', 'Fire Damage', 'Mold Removal', 'Roof Repair', 'Gutter Help', 'Tree Care' ) as $t ) {
	if ( ! str_contains( $list_c, (string) get_permalink( $mm[ 'service|' . $t ] ?? 0 ) ) ) {
		$missing[] = $t;
	}
}
$expect( 'the page that lists the services lists all of them, six cards from a section of three (not as many as the Home\'s section has)', $missing === array() && substr_count( $list_c, 'svc-card' ) >= 6, 'missing: ' . implode( ', ', $missing ) . '; cards ' . substr_count( $list_c, 'svc-card' ) );
$list_t = wp_strip_all_tags( $list_c );
$expect( 'each with the Home\'s words for it where it has them, and none where it has not (no other card\'s words)', str_contains( $list_t, 'Extraction and drying.' ) && str_contains( $list_t, 'Soot and smoke.' ) && substr_count( $list_t, 'Extraction and drying.' ) === 1, substr( preg_replace( '/\s+/', ' ', $list_t ), 0, 200 ) );
$wm = (string) get_post_field( 'post_content', $mm['service|Water Damage'] ?? 0 );

$home7_html = str_replace( array( 'Family run since 1983.', 'Extraction and drying.' ), array( 'Family run since 1983. One team from the emergency call to the final repair.', 'Extraction, structural drying and mold-safe cleanup of your home.' ), $home );
$home7_id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture With Leads', 'post_content' => wp_slash( $home7_html ) ) );
$made[]     = (int) $home7_id;
update_post_meta( $home7_id, Page_Scope::META, $home7_id );
update_post_meta( $home7_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home7_id, '_dxai_ui_css_url', 'dxai-ui/fixture-leads.css' );
$lb = Team_Pages::build( $home7_id, array( array( 'type' => 'service', 'title' => 'Water Damage' ), array( 'type' => 'service', 'title' => 'Roof Repair' ) ) );
$lw = $lr = 0;
foreach ( ! is_wp_error( $lb ) ? $lb['pages'] : array() as $p ) {
	$made[] = (int) $p['id'];
	if ( $p['title'] === 'Water Damage' ) {
		$lw = (int) $p['id'];
	} else {
		$lr = (int) $p['id'];
	}
}
$lwc = (string) get_post_field( 'post_content', $lw );
$lrc = (string) get_post_field( 'post_content', $lr );
$expect( 'a page for a service the Home lists opens with what the Home says about it, not with its line about everything', str_contains( $lwc, 'Extraction, structural drying and mold-safe cleanup of your home.' ) && ! str_contains( $lwc, 'One team from the emergency call' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $lwc ) ), 0, 160 ) );
$expect( 'and says so with the page ("lead:service")', str_contains( (string) get_post_meta( $lw, Team_Pages::OPS_META, true ), 'lead:service' ) );
$expect( 'a page for a service the Home does not list keeps the Home\'s line (nothing is made up)', str_contains( $lrc, 'One team from the emergency call to the final repair.' ) && ! str_contains( (string) get_post_meta( $lr, Team_Pages::OPS_META, true ), 'lead:service' ) );
$expect( 'a lead that is only a tag (a short line) is not replaced', ! str_contains( (string) get_post_meta( $mm['service|Water Damage'] ?? 0, Team_Pages::OPS_META, true ), 'lead:service' ) && str_contains( $wm, 'Family run since 1983.' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $wm ) ), 0, 120 ) );

$ab = Team_Pages::build( $home6_id, array( array( 'type' => 'about', 'title' => '' ) ) );
$abid = ! is_wp_error( $ab ) ? (int) ( $ab['pages'][0]['id'] ?? 0 ) : 0;
$made[] = $abid;
$abc = (string) get_post_field( 'post_content', $abid );
$expect( 'the About page of a Home that states its phone and address has the ways to reach it, before the questions and the call to action', str_contains( $abc, 'Get in touch' ) && str_contains( $abc, '12 Elm St, Tyler, TX' ) && ( ! str_contains( $abc, 'cta-x' ) || strpos( $abc, 'Get in touch' ) < strpos( $abc, 'cta-x' ) ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $abc ) ), 0, 200 ) );

echo "\nThe team's arrangement (the usual, where the library holds the team's layouts)\n";
remove_all_filters( 'dxai_ui_team_library' );
$expect( 'the library holds the team\'s layouts, so a run with nothing said about the library lays the pages out with them', DXAI_UI\Pages\Block_Library::has_layouts() );
// A restoration company's Home that states what the layouts list: the steps of its work, what customers said, its questions, its
// badges, and a few points about it.
$col3  = static fn( string $a, string $b, string $c ): string => '<!-- wp:columns --><div class="wp-block-columns">' . $a . $b . $c . '</div><!-- /wp:columns -->';
$step  = static fn( int $n, string $t, string $d ): string => '<!-- wp:column --><div class="wp-block-column">' . $para( (string) $n ) . $heading( $t, 3 ) . $para( $d ) . '</div><!-- /wp:column -->';
$quote = static fn( string $q, string $who ): string => '<!-- wp:column --><div class="wp-block-column">' . $para( $q ) . $para( $who ) . '</div><!-- /wp:column -->';
$qa    = static fn( string $q, string $a ): string => '<!-- wp:details --><details class="wp-block-details"><summary>' . $q . '</summary>' . $para( $a ) . '</details><!-- /wp:details -->';
$logo  = static fn( string $n ): string => '<!-- wp:image {"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="https://example.test/wp-content/uploads/' . $n . '.png" alt="' . ucfirst( $n ) . ' certified"/></figure><!-- /wp:image -->';
$why   = static fn( string $t, string $d ): string => '<!-- wp:group {"className":"why-item"} --><div class="wp-block-group why-item">' . $heading( $t, 3 ) . $para( $d ) . '</div><!-- /wp:group -->';
$home9 = $header
	. $section( 'Hero Section', 'hero-x bg-dark', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration in Tyler', 1 ) . $para( 'Water damage, fire damage and mold remediation for homes and businesses. Restoration done right, with the insurance claim handled.' ) . '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>24/7 emergency response</li><!-- /wp:list-item --><!-- wp:list-item --><li>Licensed and insured crews</li><!-- /wp:list-item --><!-- wp:list-item --><li>Direct insurance billing</li><!-- /wp:list-item --></ul><!-- /wp:list -->' . $button( 'Call now', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $section( 'Trust Row', 'trust-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $logo( 'iicrc' ) . $logo( 'bbb' ) . $logo( 'angi' ) . '</div><!-- /wp:group -->' )
	. $section( 'Services grid', 'svc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration Services We Provide' ) . $para( 'What we do.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Water Damage', 'Extraction and drying.' ) . $card( 'Fire Damage', 'Soot and smoke.' ) . $card( 'Mold Removal', 'Containment.' ) . '</div><!-- /wp:columns -->' . '</div><!-- /wp:group -->' )
	. $section( 'How It Works', 'proc-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'How it works' ) . $col3( $step( 1, 'Call us', 'We answer around the clock and ask what you are seeing.' ), $step( 2, 'Inspection', 'A technician inspects the damage and writes the plan.' ), $step( 3, 'Restoration', 'The crew dries, cleans and rebuilds, and walks the site with you.' ) ) . '</div><!-- /wp:group -->' )
	. $section( 'Reviews Section', 'rev-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'What customers say' ) . $col3( $quote( 'They were at the house within the hour and had the water out by evening.', 'Maria G., Tyler' ), $quote( 'Clear about the cost, careful with the furniture, done when they said.', 'Dan R., Longview' ), $quote( 'The claim paperwork was handled for us. Could not ask for more.', 'Priya S., Dallas' ) ) . '</div><!-- /wp:group -->' )
	. $section( 'FAQ Section', 'faq-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Frequently asked questions' ) . $qa( 'How fast can you come?', 'Within the hour, day or night.' ) . $qa( 'Do you work with my insurance?', 'Yes. We document everything and talk to the adjuster.' ) . $qa( 'Is the equipment loud?', 'The dryers hum. Most people sleep fine.' ) . '</div><!-- /wp:group -->' )
	. $section( 'Why Section', 'why-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Why property owners choose us' ) . '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $why( 'Veteran founded', 'Founded on military values, and it shows in how we treat your home.' ) . $why( 'Thousands of properties restored', 'Our team has guided thousands of homeowners through a claim.' ) . $why( 'Decades of combined experience', 'The crew at your door has likely handled damage just like yours.' ) . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column">' . $logo( 'crew' ) . '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->' )
	. $section( 'CTA Banner', 'cta-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Water where it should not be?' ) . $button( 'Call (555) 010-0101', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $footer;
$home9_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Restoration', 'post_content' => wp_slash( $home9 ) ) );
$made[]   = (int) $home9_id;
update_post_meta( $home9_id, Page_Scope::META, $home9_id );
update_post_meta( $home9_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home9_id, '_dxai_ui_css_url', 'dxai-ui/fixture-rich.css' );
$lists9 = DXAI_UI\Pages\Layouts::lists( $home9_id, Section_Library::for_page( $home9_id ) );
$expect( 'the Home gives the layouts its steps, reviews, questions, badges and points (three bare, three with their words), each read once; the number of a step is not a figure', count( $lists9['steps'] ) === 3 && count( $lists9['reviews'] ) === 3 && count( $lists9['faq'] ) === 3 && count( $lists9['logos'] ) === 3 && count( $lists9['items'] ) === 6 && $lists9['stats'] === array(), json_encode( array_map( 'count', $lists9 ) ) );
$expect( 'a review is who said it and what was said; a question is the question and its answer; a step is numbered', ( $lists9['reviews'][0]['title'] ?? '' ) === 'Maria G., Tyler' && str_starts_with( (string) ( $lists9['reviews'][0]['text'] ?? '' ), 'They were at the house' ) && ( $lists9['faq'][1]['title'] ?? '' ) === 'Do you work with my insurance?' && str_starts_with( (string) ( $lists9['faq'][1]['text'] ?? '' ), 'Yes.' ) && ( $lists9['steps'][1]['title'] ?? '' ) === 'Inspection' && ( $lists9['steps'][1]['eyebrow'] ?? '' ) === '2', json_encode( array( $lists9['reviews'][0] ?? null, $lists9['steps'][1] ?? null ) ) );
// Three pages: a page's related services are the other two, enough for the Home's own cards to be poured into — which a team run does not do.
$tm = Team_Pages::build( $home9_id, array( array( 'type' => 'service', 'title' => 'Water Damage' ), array( 'type' => 'service', 'title' => 'Fire Damage' ), array( 'type' => 'service', 'title' => 'Mold Removal' ) ), true, array( 'library' => 'team' ) );
$tmid = 0;
$tms  = array();
foreach ( ! is_wp_error( $tm ) ? $tm['pages'] : array() as $p ) {
	$made[] = (int) $p['id'];
	if ( $p['title'] === 'Water Damage' ) {
		$tmid = (int) $p['id'];
		$tms  = (array) $p['strip'];
	}
}
$by_source = array_count_values( array_map( static fn( $x ) => (string) ( $x['source'] ?? '' ), $tms ) );
$strip_s   = array_map( static fn( $x ) => ( $x['role'] ?? '' ) . ':' . ( $x['source'] ?? '' ), $tms );
$ctas      = array_values( array_filter( $tms, static fn( $x ) => ( $x['role'] ?? '' ) === 'cta' && ( $x['source'] ?? '' ) !== '' ) );
$expect( 'the page opens with the Home\'s hero; its first call to action is the Home\'s own band; the rest of it is the team\'s sections', ( $tms[0]['role'] ?? '' ) === 'hero' && ( $tms[0]['source'] ?? '' ) === 'home' && ( $by_source['home'] ?? 0 ) === 2 && ( $ctas[0]['source'] ?? '' ) === 'home' && ( $by_source['library'] ?? 0 ) >= 5, json_encode( $strip_s ) );
$per_role = array();
foreach ( $tms as $x ) {
	if ( ( $x['source'] ?? '' ) !== '' ) {
		$per_role[ (string) $x['role'] ] = ( $per_role[ (string) $x['role'] ] ?? 0 ) + 1;
	}
}
$expect( 'a role is on the page once, a call to action twice at most, and each section of a role is another one', count( array_filter( $per_role, static fn( $n, $r ) => $n > ( $r === 'cta' ? 2 : 1 ), ARRAY_FILTER_USE_BOTH ) ) === 0 && ( $per_role['cta'] ?? 0 ) === 2 && count( array_unique( array_map( static fn( $x ) => json_encode( $x['ops'] ?? array() ), $ctas ) ) ) === count( $ctas ), json_encode( $per_role ) );
$tmc = (string) get_post_field( 'post_content', $tmid );
$expect( 'the sections carry the library\'s class, and nothing the library keeps for itself is on the page', substr_count( $tmc, 'dxai-lib' ) >= 5 && ! str_contains( $tmc, '{{' ) && ! str_contains( $tmc, '[[' ) && ! preg_match( '/dxaiIf|dxaiRepeat|dxaiIcon|dxaiCount|dxaiSlice/', $tmc ), (string) substr_count( $tmc, 'dxai-lib' ) );
$expect( 'the Home\'s services, steps, reviews, questions and trust row are not copied onto it (their classes are not on the page); its hero and its call to action band are, once', str_contains( $tmc, 'hero-x' ) && substr_count( $tmc, '"className":"cta-x"' ) === 1 && ! str_contains( $tmc, 'svc-x' ) && ! str_contains( $tmc, 'why-x' ) && ! str_contains( $tmc, 'proc-x' ) && ! str_contains( $tmc, 'rev-x' ) && ! str_contains( $tmc, 'faq-x' ) && ! str_contains( $tmc, 'trust-x' ) );
$tmt = html_entity_decode( wp_strip_all_tags( $tmc ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
$expect( 'the layouts show what the Home states: its steps, a review with who said it, a question with its answer', str_contains( $tmt, 'Inspection' ) && str_contains( $tmt, 'Maria G., Tyler' ) && str_contains( $tmt, 'Do you work with my insurance?' ) && str_contains( $tmt, 'talk to the adjuster' ), substr( preg_replace( '/\s+/', ' ', $tmt ), 0, 300 ) );
$expect( 'the related services are the team\'s cards (not the Home\'s cards made into a list), and they link to the other two pages', ! str_contains( $tmc, 'svc-card' ) && str_contains( $tmc, 'Fire Damage' ) && str_contains( $tmc, 'Mold Removal' ) && str_contains( $tmc, (string) get_permalink( (int) ( $tm['pages'][1]['id'] ?? 0 ) ) ) && str_contains( $tmc, (string) get_permalink( (int) ( $tm['pages'][2]['id'] ?? 0 ) ) ), substr( preg_replace( '/\s+/', ' ', $tmt ), 0, 200 ) );
$expect( 'the words are about this page and this company (provisional, until written), not another site\'s', preg_match( '/Fixture( Restoration)?\b/', $tmt ) === 1 && str_contains( $tmt, '(555) 010-0101' ) && ! preg_match( '/\b(paces|calludk|iddk|ajrestores|procraft|h2oaway|floodrescue|americanrestorationnm)\b/i', $tmc ), substr( preg_replace( '/\s+/', ' ', $tmt ), 0, 300 ) );
$with_words = array_values( array_filter( $lists9['items'], static fn( $r ) => (string) ( $r['text'] ?? '' ) !== '' ) );
$expect( 'the Home\'s headed lines ("Veteran founded — …") are its points with their words, before the bare ones, and the page shows them with their words', count( $with_words ) === 3 && ( $lists9['items'][0]['title'] ?? '' ) === 'Veteran founded' && count( $lists9['items'] ) === 6 && str_contains( $tmt, 'Veteran founded' ) && str_contains( $tmt, 'Founded on military values' ), json_encode( array_column( $lists9['items'], 'title' ) ) );
$expect( 'no line is left on the page with no words in it', preg_match( '/<p[^>]*>\s*<\/p>|<h[1-6][^>]*>\s*<\/h[1-6]>|<li[^>]*>\s*<\/li>/', $tmc ) !== 1 );
$tmf = Team_Pages::build( $home9_id, array( array( 'type' => 'service', 'title' => 'Water Damage' ) ), true, array( 'library' => 'fill' ) );
foreach ( ! is_wp_error( $tmf ) ? $tmf['pages'] : array() as $p ) {
	$made[] = (int) $p['id'];
}
$tmfs = ! is_wp_error( $tmf ) ? array_count_values( array_map( static fn( $x ) => (string) ( $x['source'] ?? '' ), (array) ( $tmf['pages'][0]['strip'] ?? array() ) ) ) : array();
$expect( 'asked for the Home\'s look instead ("fill"), the page is the Home\'s sections again', ( $tmfs['home'] ?? 0 ) >= 4, json_encode( $tmfs ) );
// A Home whose reviews the design left as placeholders ("Paste a verbatim review here", "Reviewer Name"): the placeholders are the Home's
// to fill, and no page made from it carries them — not the Home's section, not the library's.
$ph_home = $header
	. $section( 'Hero Section', 'hero-x bg-dark', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Restoration in Tyler', 1 ) . $para( 'Water damage, fire damage and mold remediation for homes and businesses. Restoration done right, with the insurance claim handled.' ) . '</div><!-- /wp:group -->' )
	. $section( 'Reviews Section', 'rev-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'What customers say' ) . $col3( $quote( '“Paste a verbatim 5-star Google review here, word for word.”', 'Reviewer Name' ), $quote( '“Paste a verbatim 5-star Google review here, word for word.”', 'Reviewer Name' ), $quote( '“Paste a verbatim 5-star Google review here, word for word.”', 'Reviewer Name' ) ) . '</div><!-- /wp:group -->' )
	. $section( 'CTA Banner', 'cta-x', '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $heading( 'Water where it should not be?' ) . $button( 'Call (555) 010-0101', 'tel:5550100101' ) . '</div><!-- /wp:group -->' )
	. $footer;
$ph_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Placeholder Restoration', 'post_content' => wp_slash( $ph_home ) ) );
$made[] = (int) $ph_id;
update_post_meta( $ph_id, Page_Scope::META, $ph_id );
update_post_meta( $ph_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $ph_id, '_dxai_ui_css_url', 'dxai-ui/fixture-placeholder.css' );
$ph_made = Team_Pages::build( (int) $ph_id, array( array( 'type' => 'testimonials', 'title' => 'Testimonials' ), array( 'type' => 'service', 'title' => 'Water Damage' ) ), true, array( 'library' => 'team' ) );
$ph_text = '';
foreach ( ! is_wp_error( $ph_made ) ? $ph_made['pages'] : array() as $p ) {
	$made[]   = (int) $p['id'];
	$ph_text .= (string) get_post_field( 'post_content', (int) $p['id'] );
}
$expect( 'a Home\'s reviews left as placeholders by its design are not copied onto the pages made from it (the pages are made, without them)', ! is_wp_error( $ph_made ) && count( $ph_made['pages'] ) === 2 && $ph_text !== '' && ! str_contains( $ph_text, 'Paste a verbatim' ) && ! str_contains( $ph_text, 'Reviewer Name' ), is_wp_error( $ph_made ) ? $ph_made->get_error_message() : (string) substr_count( $ph_text, 'Paste a verbatim' ) );

echo "\nOn a theme with no template (Twenty Twenty-Five, when it is installed)\n";
if ( wp_get_theme( 'twentytwentyfive' )->exists() ) {
	$theme_was = get_stylesheet();
	$back      = static function () use ( $theme_was ): void {
		if ( get_stylesheet() !== $theme_was ) {
			switch_theme( $theme_was );
			DXAI_UI\Theme\Theme_Palette::reset();
			DXAI_UI\Theme\Theme_Buttons::reset();
			if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
				wp_clean_theme_json_cache();
			}
		}
	};
	register_shutdown_function( $back );
	switch_theme( 'twentytwentyfive' );
	DXAI_UI\Theme\Theme_Palette::reset();
	DXAI_UI\Theme\Theme_Buttons::reset();
	// WordPress keeps the theme.json it read for the rest of the request: without this the palette is still the theme switched
	// from (on a site that runs American Restoration the check read its colours and could not see a colour foreign to this theme).
	if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
		wp_clean_theme_json_cache();
	}
	$tt = Team_Pages::build( $home9_id, array( array( 'type' => 'service', 'title' => 'Water Damage' ), array( 'type' => 'service', 'title' => 'Fire Damage' ), array( 'type' => 'service', 'title' => 'Mold Removal' ) ), true, array( 'library' => 'team' ) );
	$ttid = 0;
	$tts  = array();
	foreach ( ! is_wp_error( $tt ) ? $tt['pages'] : array() as $p ) {
		$made[] = (int) $p['id'];
		if ( $p['title'] === 'Water Damage' ) {
			$ttid = (int) $p['id'];
			$tts  = (array) $p['strip'];
		}
	}
	$tt_src = array_count_values( array_map( static fn( $x ) => (string) ( $x['source'] ?? '' ), $tts ) );
	$ttc    = (string) get_post_field( 'post_content', $ttid );
	$tt_pal = DXAI_UI\Theme\Capabilities::palette();
	preg_match_all( '/\bhas-([a-z0-9-]+?)-(?:background-color|border-color|color)\b/', $ttc, $cm );
	$foreign = array_values( array_filter( array_unique( $cm[1] ), static fn( $s ) => ! in_array( $s, array( 'text', 'link', 'background' ), true ) && ! isset( $tt_pal[ $s ] ) ) );
	$expect( 'the page is laid out with the generic library: the Home\'s hero, then the team\'s sections in core blocks, with this theme\'s colours', ( $tts[0]['role'] ?? '' ) === 'hero' && ( $tt_src['library'] ?? 0 ) >= 4 && ! preg_match( '/<!-- wp:(?:amr|dx)\//', $ttc ) && ! str_contains( $ttc, '{{' ) && ! str_contains( $ttc, '[[' ) && $foreign === array() && substr_count( $ttc, 'dxai-lib' ) >= 4, json_encode( array( array_map( static fn( $x ) => ( $x['role'] ?? '' ) . ':' . ( $x['source'] ?? '' ), $tts ), $foreign ) ) );
	$back();
	$expect( 'and the theme is switched back', get_stylesheet() === $theme_was, get_stylesheet() );
} else {
	echo "  (not installed: skipped)\n";
}

echo "\nEach page's own words (words => write): no section says the Home's words on a page about something else\n";
// An AI that does not answer: the page's own lines wait for a person, with the Home's words as their hints.
$down = new class() extends DXAI_UI\Engines\Abstract_Engine {
	public function get_id(): string { return 'down'; }
	public function get_label(): string { return 'Down'; }
	public function test_connection(): bool|\WP_Error { return true; }
	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error { return new \WP_Error( 'x', 'no' ); }
	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error { return new \WP_Error( 'dxai_test_down', 'The provider is down.' ); }
};
add_filter( 'dxai_ui_copy_engine', static fn() => $down );
$ww  = Team_Pages::build( $home_id, array( array( 'type' => 'service', 'title' => 'Storm Damage' ) ), true, array( 'recipe' => 'network', 'words' => 'write' ) );
remove_all_filters( 'dxai_ui_copy_engine' );
$wp  = ! is_wp_error( $ww ) && isset( $ww['pages'][0] ) ? $ww['pages'][0] : array();
$wid = (int) ( $wp['id'] ?? 0 );
if ( $wid > 0 ) {
	$made[] = $wid;
}
$wc = (string) get_post_field( 'post_content', $wid );
$expect( 'the page is made, and its own lines wait for a person (the AI did not answer)', $wid > 0 && ( $wp['words'] ?? '' ) === 'outline' && (int) ( $wp['places'] ?? 0 ) > 0, is_wp_error( $ww ) ? $ww->get_error_message() : wp_json_encode( $wp ) );
$expect( '…each with the Home\'s words as its hint in the editor, never on the page', str_contains( $wc, '"placeholder":"Write for Storm Damage. The original says:' ) );
$expect( 'the sections the site says the same way everywhere keep the Home\'s words, and the AI would not be shown them', count( (array) get_post_meta( $wid, DXAI_UI\Pages\Copy_Writer::KEEP, true ) ) >= 1 && ! str_contains( (string) wp_json_encode( DXAI_UI\Pages\Copy_Writer::inventory( $wid ) ), '"section":"Reviews"' ), (string) wp_json_encode( get_post_meta( $wid, DXAI_UI\Pages\Copy_Writer::KEEP, true ) ) );
$expect( 'the plugin wrote the words: the page is not one a person edited, so it can be made again', $wid > 0 && Team_Pages::plan( $home_id, array( array( 'type' => 'service', 'title' => 'Storm Damage' ) ) )[0]['edited'] === false );

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
