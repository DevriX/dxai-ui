<?php
/**
 * A page built from an old site's page gets the sections every page of its kind has, the site's own, from its Home as they are
 * (Pages\Home_Frame; docs/PLAN-NETWORK-PAGES.md, 11.1).
 *
 *   bash bin/wp-php.sh bin/verify-home-frame.php        (or: wp eval-file bin/verify-home-frame.php --user=1)
 *
 * A Home with a hero, a strip of badges, cards, the steps of a call, a call to action, reviews, questions and a form; the pages of
 * the network's kinds (Network_Model::recipe()). A service page that was a title and a text gets the badges after its hero and the
 * steps, the call, the reviews and the form after its text, in the network's order, each the Home's word for word; its own sections
 * keep their order. A page that has reviews of its own gets no second; a page of a place gets what the network's pages of places have;
 * a page of no known kind gets nothing; a Home with no form gives none. The kind of a page not made yet is read from its address and
 * its menu's name; the composer adds them when told the kind, and the crawl tells it. Makes one page (a Home) and deletes it.
 * Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Network\Page_Copies;
use DXAI_UI\Pages\Home_Frame;

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

$section = static fn( string $class, string $inner ): array => parse_blocks( '<!-- wp:group {"tagName":"section","className":"' . $class . '"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->' )[0];
$h       = static fn( int $l, string $t ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$p       = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$img     = static fn( string $n ): string => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/' . $n . '.png" alt=""/></figure><!-- /wp:image -->';
$words   = 'Our own crews handle every job from the first call to the last repair, with photographs and a written scope for your insurer, and a project manager who answers the phone.';

$home = array(
	$section( 'hero', $h( 1, 'Restoration in Fairbanks' ) . $p( 'Water, fire and mold.' ) ),
	$section( 'badges', $img( 'iicrc' ) . $img( 'bbb' ) . $img( 'google' ) ),
	$section( 'services', $h( 2, 'What we restore' ) . $h( 3, 'Water' ) . $h( 3, 'Fire' ) . $h( 3, 'Mold' ) ),
	$section( 'steps', $h( 2, 'What happens when you call' ) . $h( 3, 'Call' ) . $h( 3, 'We arrive' ) . $h( 3, 'We restore' ) ),
	$section( 'call', $h( 2, 'Water damage? Call now.' ) . $p( '<a href="tel:+15550100101">(555) 010-0101</a>' ) ),
	$section( 'reviews', $h( 2, 'What our customers say' ) . $p( 'They came within the hour and handled the insurance.' ) ),
	$section( 'faq', $h( 2, 'Frequently asked questions' ) . $p( 'Do you bill insurance? We do.' ) ),
	$section( 'form', $h( 2, 'Request a callback' ) . '<!-- wp:html --><form><input type="tel" name="phone"/></form><!-- /wp:html -->' ),
);
$library = array_map( static fn( array $b, int $i ): array => array( 'index' => $i, 'block' => $b ), $home, array_keys( $home ) );
$classes = static fn( array $sections ): array => array_map( static fn( array $s ): string => (string) ( $s['attrs']['className'] ?? '' ), $sections );

echo "The Home's sections read as the network's pages were\n";
$read = array_map( static fn( array $b, int $i ): string => Home_Frame::role( $b, $i ), $home, array_keys( $home ) );
$expect( 'its hero, badges, cards, steps, call, reviews, questions and form', $read === array( 'hero', 'trust', 'cards', 'process', 'cta', 'reviews', 'faq', 'form' ), implode( ' ', $read ) );

echo "A service page that was a title and a text\n";
$page   = array( $section( 'page-hero', $h( 1, 'Fire Damage Restoration' ) . $p( 'Fire damage cleanup in Fairbanks.' ) ), $section( 'page-text', $img( 'fire' ) . $p( $words ) ) );
$log    = array();
$framed = Home_Frame::complete( $page, $library, 'service', $log );
$expect( 'gets the badges after its hero, then its text, then the steps, the call, the reviews and the form — the network\'s order', $classes( $framed ) === array( 'page-hero', 'badges', 'page-text', 'steps', 'call', 'reviews', 'form' ), implode( ' ', $classes( $framed ) ) );
$expect( '…each the Home\'s word for word', serialize_block( $framed[1] ) === serialize_block( $home[1] ) && serialize_block( $framed[5] ) === serialize_block( $home[5] ) && serialize_block( $framed[6] ) === serialize_block( $home[6 + 1] ) );
$expect( '…and says what it added', count( $log ) === 5 && str_contains( $log[0], 'trust' ) );
$expect( 'the questions, the Home\'s cards: not the site\'s own, not added', ! in_array( 'faq', $classes( $framed ), true ) && ! in_array( 'services', $classes( $framed ), true ) );

echo "Pages that have them already, and other kinds\n";
$with_reviews = Home_Frame::complete( array( $page[0], $page[1], $section( 'own-reviews', $h( 2, 'Customer reviews' ) . $p( 'Five stars from the Smiths.' ) ) ), $library, 'service' );
$expect( 'a page with reviews of its own gets no second', ! in_array( 'reviews', $classes( $with_reviews ), true ) && in_array( 'own-reviews', $classes( $with_reviews ), true ) );
$place = Home_Frame::complete( $page, $library, 'location' );
$expect( 'a page of a place gets what the network\'s pages of places have (reviews, badges, a call; no steps, no form)', $classes( $place ) === array( 'page-hero', 'page-text', 'reviews', 'badges', 'call' ), implode( ' ', $classes( $place ) ) );
$expect( 'a page of no known kind gets nothing', Home_Frame::complete( $page, $library, '' ) === $page && Home_Frame::complete( $page, $library, 'nothing-like-it' ) === $page );
$no_form = array_values( array_filter( $library, static fn( array $c ): bool => ( $c['block']['attrs']['className'] ?? '' ) !== 'form' ) );
$expect( 'a Home with no form gives none', ! in_array( 'form', $classes( Home_Frame::complete( $page, $no_form, 'service' ) ), true ) );

echo "The kind of a page not made yet\n";
$kinds = array(
	Page_Copies::kind_of_address( '/services/fire-damage/', 'Fire & Smoke Damage' ),
	Page_Copies::kind_of_address( '/services/mold-testing-remediation/', 'Mold Testing & Remediation' ),
	Page_Copies::kind_of_address( '/service-areas/lacey-wa/', 'Lacey' ),
	Page_Copies::kind_of_address( '/about-us/', 'About' ),
	Page_Copies::kind_of_address( '/contact/', 'Contact' ),
	Page_Copies::kind_of_address( '/water-damage-lacey-wa/', 'Water Damage Restoration' ),
);
$expect( 'read from its address and its menu\'s name: a service, a service, a place, about, contact, and a service in a city (its address ends in a state) a place', $kinds === array( 'service', 'service', 'location', 'about', 'contact', 'location' ), implode( ' ', $kinds ) );

echo "Composed, and built by the crawl\n";
// The composer, told the page's kind, ends with the frame; the crawl tells it the kind of the page its menu names.
$analyzed = array_map( static fn( array $b, int $i ): array => DXAI_UI\Pages\Section_Library::analyze( $b, $i ), $home, array_keys( $home ) );
$model    = ( new DXAI_UI\Pages\Content_Extractor() )->extract( '<html><body><main><section><h1>Fire Damage Restoration</h1><p>Fire damage cleanup in Fairbanks, day or night, by our own crews.</p></section><section><h2>Smoke and soot</h2><p>' . $words . '</p></section></main></body></html>', 'https://old.example.test/services/fire/' );
$composed = ( new DXAI_UI\Pages\Page_Composer( $analyzed, static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )->kind( 'service' )->compose( $model );
$expect( 'the composer, told the page is a service, adds the Home\'s badges, steps, reviews and form', count( array_intersect( array( 'badges', 'steps', 'reviews', 'form' ), $classes( $composed['sections'] ) ) ) === 4, implode( ' ', $classes( $composed['sections'] ) ) );
$untold = ( new DXAI_UI\Pages\Page_Composer( $analyzed, static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )->compose( $model );
$expect( '…and not told, adds none', array_intersect( array( 'badges', 'steps', 'form' ), $classes( $untold['sections'] ) ) === array(), implode( ' ', $classes( $untold['sections'] ) ) );
$home_id = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'Home Frame Home ' . substr( md5( (string) microtime( true ) ), 0, 6 ),
		'post_content' => wp_slash( implode( '', array_map( 'serialize_block', $home ) ) ),
	)
);
register_shutdown_function( static fn() => wp_delete_post( $home_id, true ) );
$body = new ReflectionMethod( DXAI_UI\Structures\Site_From_Menu::class, 'compose_body' );
$body->setAccessible( true );
$summary = array();
$markup  = (string) $body->invokeArgs( null, array( $home_id, $model, &$summary, '/services/fire-damage/', 'Fire Damage' ) );
$expect( 'the crawl builds a page its menu names a service with the frame', str_contains( $markup, 'class="wp-block-group badges"' ) && str_contains( $markup, '<form>' ), implode( ' | ', (array) ( $summary['compose_log']['/services/fire-damage/'] ?? array() ) ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
