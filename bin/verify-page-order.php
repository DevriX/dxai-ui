<?php
/**
 * The order of a page's sections against fixtures: the tail (related services, questions, the last call to action)
 * in the team's order, and every case that must be left alone.
 *
 *   bash bin/wp-php.sh bin/verify-page-order.php        (or: wp eval-file bin/verify-page-order.php)
 *
 * Reads nothing from the site's content and writes nothing. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Page_Order;
use DXAI_UI\Pages\Section_Roles;

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

$group = static fn( string $name, string $inner, string $css = '' ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"}' . ( $css !== '' ? ',"dxaiCss":"' . $css . '"' : '' ) . '} --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
$head  = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para  = static fn( string $t = 'Text' ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$box   = '<!-- wp:amr/link-box --><a class="wp-block-amr-link-box" href="#"><!-- wp:paragraph --><p>Service</p><!-- /wp:paragraph --></a><!-- /wp:amr/link-box -->';
$make  = array(
	'H' => $group( 'Hero Section', $head( 'Water damage in Tyler', 1 ) ),
	't' => $group( 'Intro', $para( 'Some words about the work, said plainly.' ) ),
	'S' => $group( 'Related Services', $head( 'Related services' ) . $box . $box . $box ),
	'Q' => $group( 'FAQ Section', $head( 'Questions' ) ),
	'A' => $group( 'CTA Banner', $head( 'Call us' ) . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="#">Call</a></div><!-- /wp:button -->' ),
	'F' => $group( 'Contact', '<!-- wp:shortcode -->[gravityform id="1"]<!-- /wp:shortcode -->' ),
);
$page  = static function ( string $letters ) use ( &$make ): array {
	return parse_blocks( implode( "\n\n", array_map( static fn( $c ) => $make[ $c ], str_split( $letters ) ) ) );
};
$order = static function ( array $blocks ): string {
	$names = array( 'Hero Section' => 'H', 'Intro' => 't', 'Related Services' => 'S', 'FAQ Section' => 'Q', 'CTA Banner' => 'A', 'Contact' => 'F' );

	return implode( '', array_map( static fn( $b ) => $names[ (string) ( $b['attrs']['metadata']['name'] ?? '' ) ] ?? '?', array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) ) ) );
};
$run = static function ( string $letters ) use ( $page, $order ): string {
	return $order( Page_Order::arrange( array_values( array_filter( $page( $letters ), static fn( $b ) => ! empty( $b['blockName'] ) ) ) ) );
};

echo "Roles\n";
$blocks = array_values( array_filter( $page( 'HtSQA' ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$roles  = array();
foreach ( $blocks as $i => $b ) {
	$roles[] = Section_Roles::of( $b, $i === 0 );
}
$expect( 'sections are read for the part they play', $roles === array( 'hero', 'text', 'related', 'faq', 'cta' ), implode( ',', $roles ) );

echo "\nThe tail\n";
$expect( 'related services, questions, call to action: the composed order is put right', $run( 'HtQAS' ) === 'HtSQA', $run( 'HtQAS' ) );
$expect( 'questions after the call to action are put before it', $run( 'HtAQ' ) === 'HtQA', $run( 'HtAQ' ) );
$expect( 'a page already in order is left as it is', $run( 'HtSQA' ) === 'HtSQA' );
$expect( 'two calls to action: the first stays, the last closes the page', $run( 'HAtQA' ) === 'HAtQA' && $run( 'HAtAQ' ) === 'HAtQA', $run( 'HAtAQ' ) );
$expect( 'the hero stays first, what is between keeps its order', $run( 'HttQAS' ) === 'HttSQA', $run( 'HttQAS' ) );
$expect( 'a page without questions is left as it is', $run( 'HtAS' ) === 'HtAS' );
$expect( 'a page that ends in a form keeps its ending', $run( 'HtQAF' ) === 'HtQAF' );
$expect( 'a page too short to arrange is left as it is', $run( 'HQ' ) === 'HQ' );
$expect( 'a second pass changes nothing', $run( $run( 'HtQAS' ) ) === $run( 'HtQAS' ) );

echo "\nWhat must not move\n";
$lean = $make;
$make['S'] = $group( 'Related Services', $head( 'Related services' ) . $box . $box . $box, 'margin-top:-48px' );
$expect( 'a page with a section that leans on its neighbour is left as it is', $run( 'HtQAS' ) === 'HtQAS', $run( 'HtQAS' ) );
$make = $lean;
$before = array_values( array_filter( $page( 'HtQAS' ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$after  = Page_Order::arrange( $before );
$same   = count( $before ) === count( $after ) && array_diff( array_map( 'serialize_block', $before ), array_map( 'serialize_block', $after ) ) === array();
$expect( 'no section changes, only their places', $same );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
