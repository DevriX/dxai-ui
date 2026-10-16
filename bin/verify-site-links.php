<?php
/**
 * A design's links where the site is: under the site's folder, and to the Home's sections from the other pages.
 *
 *   bash bin/wp-php.sh bin/verify-site-links.php        (or: wp eval-file bin/verify-site-links.php --user=1)
 *
 * A design names its pages from the root of its host (`/careers/`) and the Home's sections by their place (`#why`). On a site of a network
 * in folders (the team's network: a site at …/taylored-restoration/) every such link of Taylored Restoration's pages answered 404 — 11 on each page, the
 * footer's and the service cards' (2026-10-16) — and the footer's "Why Taylored" led nowhere from any page but the Home. Site_Links reads
 * them where they are drawn: a link from the root goes under the site's folder, a place the page lacks and the Home has goes to the Home.
 *
 * The site is put in a folder for the checks by `home_url` (as a network in folders has it); nothing about the site is changed. Makes its
 * pages, a widget record and a menu item's mark, and takes them away, also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Footer_Widgets;
use DXAI_UI\Chrome\Site_Links;
use DXAI_UI\Structures\Page_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

$made    = array();
$options = array();
$clean   = static function () use ( &$made, &$options ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
	foreach ( $options as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	$options = array();
};
register_shutdown_function( $clean );

$page = static function ( string $title, string $content, int $scope = 0, string $type = 'page' ) use ( &$made ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => 'Site links ' . $title . ' ' . wp_generate_password( 6, false ),
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	$made[] = (int) $id;
	if ( $scope !== 0 ) {
		update_post_meta( (int) $id, Page_Scope::META, (string) ( $scope === -1 ? $id : $scope ) );
	}

	return (int) $id;
};
// The site in a folder of its host, as a site of a network in folders is (one folder more when the test site is in one already: ci-clean is).
$folder = static fn( $url ) => is_string( $url ) ? (string) preg_replace( '#^(https?://[^/]+)(?!/sub(?:/|$))#', '$1/sub', $url, 1 ) : $url;
// A page's view: the query, the post, as WordPress has them when it draws it.
$view = static function ( int $id ): void {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $id );
	setup_postdata( $GLOBALS['post'] );
	Site_Links::forget();
};
$hrefs = static function ( string $html ): array {
	preg_match_all( '/href="([^"]*)"/', $html, $m );

	return $m[1];
};

echo "A link from the root of the host\n";
$expect( 'at the root of the host: as it is', '/careers/' === Site_Links::rooted( '/careers/', '' ) );
$expect( 'in a folder: under the folder', '/taylored-restoration/careers/' === Site_Links::rooted( '/careers/', '/taylored-restoration' ) );
$expect( 'the root itself: the site\'s own root', '/taylored-restoration/' === Site_Links::rooted( '/', '/taylored-restoration' ) );
$expect( 'already under the folder (a page, the folder itself, a query, a place): as it is', array( '/taylored-restoration/careers/', '/taylored-restoration', '/taylored-restoration?p=2', '/taylored-restoration#why' ) === array_map( static fn( $h ) => Site_Links::rooted( $h, '/taylored-restoration' ), array( '/taylored-restoration/careers/', '/taylored-restoration', '/taylored-restoration?p=2', '/taylored-restoration#why' ) ) );
$expect( 'a folder that only starts like it is another folder', '/taylored-restoration/taylored-restoration-old/' === Site_Links::rooted( '/taylored-restoration-old/', '/taylored-restoration' ) );
$expect( 'another host, a protocol-relative one, a phone, a mail, a relative link: as they are', array( 'https://x.com/a/', '//cdn.x.com/a.png', 'tel:9073441239', 'mailto:a@b.c', 'careers/' ) === array_map( static fn( $h ) => Site_Links::rooted( $h, '/sub' ), array( 'https://x.com/a/', '//cdn.x.com/a.png', 'tel:9073441239', 'mailto:a@b.c', 'careers/' ) ) );

echo "\nThe site's folder\n";
$base = Site_Links::path();
$expect( 'the site\'s folder, without its slash (none at the root of its host)', '' === $base || ( str_starts_with( $base, '/' ) && '/' !== substr( $base, -1 ) ), $base );
add_filter( 'home_url', $folder, 999 );
$f = Site_Links::path();
$expect( 'a site in a folder: the folder, without its slash', '/sub' . $base === $f, $f );

echo "\nA design's pages, in a folder\n";
$home = $page( 'Home', '<!-- wp:group {"anchor":"why"} --><div id="why" class="wp-block-group"><p>Why</p></div><!-- /wp:group --><!-- wp:group --><section id="faqs"><p>Questions</p></section><!-- /wp:group --><!-- wp:paragraph --><p id="main">Hero</p><!-- /wp:paragraph --><!-- wp:paragraph --><p id="reviews">Reviews</p><!-- /wp:paragraph -->', -1 );
$sub  = $page( 'Water', '<!-- wp:paragraph --><p id="process">Steps</p><!-- /wp:paragraph --><!-- wp:paragraph --><p id="reviews">Its own reviews</p><!-- /wp:paragraph -->', $home );
$body = '<p><a href="/careers/">Careers</a> <a href="/water-damage-restoration/">Water</a> <a href="#why">Why Taylored</a> <a href="#faqs">FAQs</a>'
	. ' <a href="#process">Steps</a> <a href="#reviews">Reviews</a> <a href="#nowhere">Nowhere</a> <a href="#main">Skip to content</a> <a class="skip-link" href="#why">Skip</a>'
	. ' <a href="tel:9073441239">Call</a> <a href="https://www.facebook.com/x">Facebook</a> <a href="' . $f . '/contact/">Contact</a></p>';
$view( $sub );
$out  = (string) apply_filters( 'the_content', $body );
$got  = $hrefs( $out );
$home_url = (string) get_permalink( $home );
$expect( 'a page from the root of the host: under the folder', in_array( $f . '/careers/', $got, true ) && in_array( $f . '/water-damage-restoration/', $got, true ), implode( ' ', $got ) );
$expect( 'a section the page lacks and the Home has (an element\'s id, a block\'s anchor): on the Home', in_array( $home_url . '#why', $got, true ) && in_array( $home_url . '#faqs', $got, true ), implode( ' ', $got ) );
$expect( 'a place the page has (and the Home has too): on the page', in_array( '#process', $got, true ) && in_array( '#reviews', $got, true ), implode( ' ', $got ) );
$expect( 'a place neither has: as it is', in_array( '#nowhere', $got, true ) );
$expect( 'a skip link (to #main, or with the skip class): the page\'s own', in_array( '#main', $got, true ) && str_contains( $out, 'class="skip-link" href="#why"' ), $out );
$expect( 'a phone, another site, a link already under the folder: as they are', in_array( 'tel:9073441239', $got, true ) && in_array( 'https://www.facebook.com/x', $got, true ) && in_array( $f . '/contact/', $got, true ), implode( ' ', $got ) );
$alone = Site_Links::links( $body, $sub, $home );
$expect( 'the words and the rest of the markup are as they were', wp_strip_all_tags( $alone ) === wp_strip_all_tags( $body ) && preg_replace( '/href="[^"]*"/', '', $alone ) === preg_replace( '/href="[^"]*"/', '', $body ), $alone );

$view( $home );
$got = $hrefs( (string) apply_filters( 'the_content', $body ) );
$expect( 'on the Home, its sections stay places on the page', in_array( '#why', $got, true ) && in_array( '#faqs', $got, true ) && in_array( $f . '/careers/', $got, true ), implode( ' ', $got ) );

$plain = $page( 'Plain', '<!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph -->' );
$view( $plain );
$got = $hrefs( (string) apply_filters( 'the_content', $body ) );
$expect( 'a page that is no design\'s: nothing read (a person\'s links are theirs)', in_array( '/careers/', $got, true ) && in_array( '#why', $got, true ), implode( ' ', $got ) );

$view( $sub );
add_filter( 'dxai_ui_site_links', '__return_false' );
$got = $hrefs( (string) apply_filters( 'the_content', $body ) );
remove_filter( 'dxai_ui_site_links', '__return_false' );
$expect( 'dxai_ui_site_links false: as they are', in_array( '/careers/', $got, true ) && in_array( '#why', $got, true ) );

echo "\nThe footer's widgets and the header's menu items\n";
$options[ Footer_Widgets::OWNED ] = get_option( Footer_Widgets::OWNED, null );
update_option( Footer_Widgets::OWNED, array( 'block-990001' => array( 'area' => 'footer-1', 'md5' => '' ) ) );
$widget       = new WP_Widget_Block();
$widget->id   = 'block-990001';
$theirs       = new WP_Widget_Block();
$theirs->id   = 'block-990002';
$w_html       = '<ul><li><a href="/careers/">Careers</a></li><li><a href="/privacy-policy/">Privacy Policy</a></li></ul>';
$got          = $hrefs( (string) apply_filters( 'widget_block_content', $w_html, array(), $widget ) );
$expect( 'a footer widget the plugin installed (a theme draws it): under the folder', array( $f . '/careers/', $f . '/privacy-policy/' ) === $got, implode( ' ', $got ) );
$got = $hrefs( (string) apply_filters( 'widget_block_content', $w_html, array(), $theirs ) );
$expect( 'a widget a person made: as it is', array( '/careers/', '/privacy-policy/' ) === $got, implode( ' ', $got ) );

$item = $page( 'Item', '', 0, 'nav_menu_item' );
update_post_meta( $item, '_dxai_ui_generated', '1' );
$mine  = (array) apply_filters( 'nav_menu_link_attributes', array( 'href' => '/mold-remediation/' ), get_post( $item ), (object) array(), 0 );
$other = $page( 'Other item', '', 0, 'nav_menu_item' );
$their = (array) apply_filters( 'nav_menu_link_attributes', array( 'href' => '/mold-remediation/' ), get_post( $other ), (object) array(), 0 );
$expect( 'a menu item the plugin wrote: under the folder', $f . '/mold-remediation/' === ( $mine['href'] ?? '' ), (string) ( $mine['href'] ?? '' ) );
$expect( 'a menu item a person made: as it is', '/mold-remediation/' === ( $their['href'] ?? '' ) );

remove_filter( 'home_url', $folder, 999 );
echo "\nThe site where it is (the test's folder taken away)\n";
$view( $sub );
$got = $hrefs( (string) apply_filters( 'the_content', $body ) );
$expect( 'links from the root under the site\'s own folder, as they are at the root of a host; the Home\'s sections still on the Home', in_array( Site_Links::rooted( '/careers/', $base ), $got, true ) && in_array( get_permalink( $home ) . '#why', $got, true ), implode( ' ', $got ) );

wp_reset_postdata();
$clean();
echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
