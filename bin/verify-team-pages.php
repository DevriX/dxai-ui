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

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
