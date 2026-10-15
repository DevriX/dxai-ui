<?php
/**
 * A design's markup as assistive technology reads it (Seo\Accessible_Markup).
 *
 *   bash bin/wp-php.sh bin/verify-accessible-markup.php        (or: wp eval-file bin/verify-accessible-markup.php --user=1)
 *
 * Lighthouse's accessibility audit on the corpus (docs/PLAN-PERFECT-PAGES.md, T5.5): a React ref left as an attribute and a menu button
 * pointing at an id the page does not have (Five Star), aria-expanded on a <header> and links reachable by Tab inside a layer hidden
 * from readers (DevriX Elevate), call and social links whose aria-label does not hold their visible words (Five Star, Semper Dry).
 * Each repair, what it leaves alone, and that it is made only on a design's page.
 *
 * Makes two pages and deletes them, also when a check dies half-way. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Seo\Accessible_Markup;

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
$tag = static fn( string $html, string $needle ): string => preg_match( '#<[a-z][a-z0-9]*\b[^>]*' . preg_quote( $needle, '#' ) . '[^>]*>#i', $html, $m ) ? $m[0] : '';

echo "Attributes\n";
$out = Accessible_Markup::fix( '<button type="button" ref="[object Object]" data-x="ref-button">Menu</button>' );
$expect( 'a React ref left as an attribute is removed', ! str_contains( $out, 'ref=' ) && str_contains( $out, 'data-x="ref-button"' ), $out );
$out = Accessible_Markup::fix( '<header class="h1x" aria-expanded="false" data-wp-bind--aria-expanded="state.open">x</header><section class="s1x" aria-expanded="true">y</section>' );
$expect( 'aria-expanded on a <header> or a <section> (roles that cannot be expanded) is removed, with its binding', ! str_contains( $out, 'aria-expanded' ), $out );
$out = Accessible_Markup::fix( '<button class="b1x" aria-expanded="false">x</button><header class="h2x" role="button" aria-expanded="false">y</header><div class="d1x" aria-expanded="false">z</div>' );
$expect( '…kept on a button, on an element whose role can be expanded, and on a <div> with no role', str_contains( $tag( $out, 'b1x' ), 'aria-expanded' ) && str_contains( $tag( $out, 'h2x' ), 'aria-expanded' ) && str_contains( $tag( $out, 'd1x' ), 'aria-expanded' ), $out );

echo "\nA layer hidden from readers\n";
$out = Accessible_Markup::fix( '<div class="ticker" aria-hidden="true"><div><a class="l1" href="/a">A</a><button class="b2">B</button><span class="s2" tabindex="0">C</span><a class="l2">no address</a><input class="i1" type="hidden"></div></div><a class="after" href="/after">After</a>' );
$expect( 'what the Tab key reaches inside it leaves the Tab order (links, buttons, tabindex 0)', str_contains( $tag( $out, 'l1' ), 'tabindex="-1"' ) && str_contains( $tag( $out, 'b2' ), 'tabindex="-1"' ) && str_contains( $tag( $out, 's2' ), 'tabindex="-1"' ), $out );
$expect( '…and nothing else: a link with no address, a hidden input, a link after the layer', ! str_contains( $tag( $out, 'l2' ), 'tabindex' ) && ! str_contains( $tag( $out, 'i1' ), 'tabindex' ) && ! str_contains( $tag( $out, 'after' ), 'tabindex' ), $out );
$out = Accessible_Markup::fix( '<a class="hid" href="/x" aria-hidden="true">x</a>' );
$expect( 'a link hidden from readers itself leaves the Tab order too', str_contains( $out, 'tabindex="-1"' ), $out );

echo "\nNames\n";
$out = Accessible_Markup::fix( '<a class="call" href="tel:5550100" aria-label="Call Five Star Disaster Services"><span aria-hidden="true">☎</span> (509) 404-0778</a>' );
$expect( 'a link whose aria-label does not hold its visible words gets them first in its name', str_contains( $out, 'aria-label="(509) 404-0778, Call Five Star Disaster Services"' ), $out );
$out = Accessible_Markup::fix( '<a href="/x" aria-label="Call (509) 404-0778 now">(509) 404-0778</a><a href="/ig" aria-label="Semper Dry on Instagram"><svg aria-hidden="true"></svg></a>' );
$expect( '…not one whose name holds them, nor an icon with no words', str_contains( $out, 'aria-label="Call (509) 404-0778 now"' ) && str_contains( $out, 'aria-label="Semper Dry on Instagram"' ), $out );
$out = Accessible_Markup::fix( '<button aria-label="Open">Menu</button>' );
$expect( 'a button too', str_contains( $out, 'aria-label="Menu, Open"' ), $out );

echo "\nReferences (the whole page)\n";
$doc = Accessible_Markup::references( '<button class="m1" aria-controls="mobile-menu">Menu</button><button class="m2" aria-controls="panel-a gone">Both</button><div id="panel-a"></div><button class="m3" aria-describedby="panel-a">Here</button>' );
$expect( 'a reference to an id the page does not have is removed', ! str_contains( $tag( $doc, 'm1' ), 'aria-controls' ), $doc );
$expect( '…the ids that are there are kept', str_contains( $tag( $doc, 'm2' ), 'aria-controls="panel-a"' ) && str_contains( $tag( $doc, 'm3' ), 'aria-describedby="panel-a"' ), $doc );

echo "\nOnly on a design's page\n";
$made  = array();
$clean = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $clean );
$body  = '<!-- wp:html --><header class="hx" aria-expanded="false">x</header><!-- /wp:html -->';
$home  = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Accessible markup fixture Home', 'post_content' => 'x' ) );
$page  = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Accessible markup fixture', 'post_content' => $body ) );
$plain = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Accessible markup fixture (no design)', 'post_content' => $body ) );
$made  = array_filter( array( $home, $page, $plain ) );
update_post_meta( $page, '_dxai_ui_scope_id', (string) $home );
$render = static function ( int $id ): string {
	$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = get_post( $id );
	setup_postdata( $GLOBALS['post'] );

	return (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
};
$expect( 'the content of a design\'s page is repaired as it is served', ! str_contains( $render( $page ), 'aria-expanded' ) );
remove_all_filters( 'wp_template_enhancement_output_buffer' );
Accessible_Markup::watch();
$expect( '…and its whole document is handed over for the references (WordPress 6.9+)', false !== has_filter( 'wp_template_enhancement_output_buffer', array( Accessible_Markup::class, 'document' ) ) );
$expect( 'a page that is no design\'s is left as it is', str_contains( $render( $plain ), 'aria-expanded' ) );
remove_all_filters( 'wp_template_enhancement_output_buffer' );
Accessible_Markup::watch();
$expect( '…and its document is not buffered for it', false === has_filter( 'wp_template_enhancement_output_buffer', array( Accessible_Markup::class, 'document' ) ) );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $page ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
add_filter( 'dxai_ui_accessible_markup', '__return_false' );
$expect( 'nothing when the filter says no', str_contains( $render( $page ), 'aria-expanded' ) );
remove_filter( 'dxai_ui_accessible_markup', '__return_false' );

$clean();
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
