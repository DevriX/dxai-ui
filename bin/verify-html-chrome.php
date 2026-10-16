<?php
/**
 * An HTML page's site header is read as the header, whatever opens the page before it.
 *
 *   bash bin/wp-php.sh bin/verify-html-chrome.php        (or: wp eval-file bin/verify-html-chrome.php --user=1)
 *
 * Source_Compiler reads an HTML page's top-level bands: a <header> (or a wrapper around exactly one, Semper Dry's sticky div) is the
 * site's header, but a <header> or <nav> after the page's content has begun is the page's own (an in-page table of contents, a
 * section's heading band). Taylored Restoration opens with a skip link ("Skip to content", a link to #main), and that counted as
 * content: its sticky wrapper with the top bar and the <header> became a section of the page — no header part, nothing installed as
 * Menus. A skip link (a link to a place on the page) and an empty band (a fixed header's spacer) only open the page.
 *
 * Compiles small pages through the compiler's own method; writes nothing. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Source_Compiler;

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

$compiler = new Source_Compiler();
$method   = new ReflectionMethod( $compiler, 'compile_html_document' );
$method->setAccessible( true );
// The types of a page's structures, in order.
$types = static function ( string $body ) use ( $compiler, $method ): array {
	$row = (array) $method->invoke( $compiler, '<!DOCTYPE html><html><head><title>T</title></head><body>' . $body . '</body></html>', array(), 'T', '/' );

	return array_map( static fn( $s ) => (string) $s['type'], (array) ( $row['structures'] ?? array() ) );
};
$header = '<header><a href="/"><img src="logo.png" alt="Logo"></a><nav aria-label="Primary"><a href="/services/">Services</a><a href="/about/">About</a></nav></header>';
$sticky = '<div data-sticky style="position:sticky;top:0"><div><p>25,000+ Homes Restored</p></div>' . $header . '</div>';
$skip   = '<a href="#main" style="position:absolute;left:-9999px">Skip to content</a>';
$main   = '<main id="main"><section><h1>Water damage</h1><p>Here fast.</p></section><section><h2>Services</h2><p>Every kind.</p></section></main>';
$footer = '<footer><p>© Taylored Restoration</p></footer>';
$show   = static fn( array $t ): string => implode( ', ', $t );

echo "What opens the page\n";
$t = $types( $skip . $sticky . $main . $footer );
$expect( 'a skip link, then a sticky wrapper with a top bar and the <header>: the wrapper is the header (Taylored Restoration)', 'header' === ( $t[1] ?? '' ) && 'footer' === end( $t ), $show( $t ) );
$t = $types( $skip . $header . $main . $footer );
$expect( 'a skip link, then the <header>: the header', 'header' === ( $t[1] ?? '' ), $show( $t ) );
$t = $types( '<div style="height:104px"></div>' . $header . $main . $footer );
$expect( 'an empty band (a fixed header\'s spacer), then the <header>: the header', 'header' === ( $t[1] ?? '' ), $show( $t ) );
$t = $types( $sticky . $main . $footer );
$expect( 'the sticky wrapper first: the header (Semper Dry)', 'header' === ( $t[0] ?? '' ), $show( $t ) );

echo "\nWhat is the page's own\n";
$t = $types( '<section><h2>Intro</h2><p>Words.</p></section>' . $header . $main );
$expect( 'a <header> after the page\'s content has begun: a section (a section\'s heading band)', 'section' === ( $t[1] ?? '' ), $show( $t ) );
$t = $types( '<a href="/coupons/">Save $100 on any job</a>' . $sticky . $main );
$expect( 'a link that leads away is the page\'s content (a promotion band): the header after it is the page\'s', 'header' !== ( $t[1] ?? '' ), $show( $t ) );
$t = $types( '<div><img src="banner.jpg" alt="Banner"></div>' . $header . $main );
$expect( 'a band with a picture and no words is content too', 'header' !== ( $t[1] ?? '' ), $show( $t ) );
$t = $types( $skip . '<main id="main"><header><h2>Our story</h2></header><section><h1>About</h1></section></main>' );
$expect( 'a <header> inside <main> stays the page\'s (after a skip link too)', ! in_array( 'header', $t, true ), $show( $t ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
