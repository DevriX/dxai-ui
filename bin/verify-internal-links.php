<?php
/**
 * The link pass of an import (Compiler\Internal_Links): one lookup per link gives the answer the search of the whole markup for each
 * of the map's spellings gave, in every place a link is written, and fast on a site's worth of pages.
 * Run: wp eval-file bin/verify-internal-links.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok ) use ( &$fail, &$pass ): void {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $what . "\n";
};

/*
 * The pass as it was until 0.4.0-beta.44: a search of the markup per spelling, longest first, thirteen places each. The answer
 * the one-lookup pass must give.
 */
$each = static function ( string $markup, array $map ): string {
	foreach ( $map as $path => $permalink ) {
		$markup = str_replace( 'href="' . $path . '"', 'href="' . $permalink . '"', $markup );
		$markup = str_replace( "href='" . $path . "'", 'href="' . $permalink . '"', $markup );
		$markup = str_replace( '"url":"' . $path . '"', '"url":"' . $permalink . '"', $markup );
		$esc_path = str_replace( '/', '\\/', $path );
		$esc_perm = str_replace( '/', '\\/', $permalink );
		if ( $esc_path !== $path ) {
			$markup = str_replace( '"url":"' . $esc_path . '"', '"url":"' . $esc_perm . '"', $markup );
			$markup = str_replace( 'href=\"' . $esc_path . '\"', 'href=\"' . $esc_perm . '\"', $markup );
		}
		foreach ( array( '#', '?' ) as $tail ) {
			$markup = str_replace( 'href="' . $path . $tail, 'href="' . $permalink . $tail, $markup );
			$markup = str_replace( "href='" . $path . $tail, "href='" . $permalink . $tail, $markup );
			$markup = str_replace( '"url":"' . $path . $tail, '"url":"' . $permalink . $tail, $markup );
			if ( $esc_path !== $path ) {
				$markup = str_replace( '"url":"' . $esc_path . $tail, '"url":"' . $esc_perm . $tail, $markup );
				$markup = str_replace( 'href=\"' . $esc_path . $tail, 'href=\"' . $esc_perm . $tail, $markup );
			}
		}
	}

	return $markup;
};
$links   = new DXAI_UI\Compiler\Internal_Links();
$one     = new ReflectionMethod( $links, 'rewrite_markup' );
$one->setAccessible( true );
$lookup  = static fn( string $markup, array $map ): string => (string) $one->invoke( $links, $markup, $map );
// The map as permalink_map() orders it: the longest spelling first.
$ordered = static function ( array $map ): array {
	uksort( $map, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

	return $map;
};

$map = $ordered(
	array(
		'/'                                   => 'https://site.test/',
		'/about'                              => 'https://site.test/about-us/',
		'/about/'                             => 'https://site.test/about-us/',
		'/about/team'                         => 'https://site.test/team/',
		'https://old.example/about/'          => 'https://site.test/about-us/',
		'https://old.example/about'           => 'https://site.test/about-us/',
		'services.dc.html'                    => 'https://site.test/services/',
		'/fire-damage-restoration-oregon'     => 'https://site.test/?page_id=12',
		'/contact'                            => 'https://site.test/contact/',
	)
);
$cases = array(
	'a whole path in double quotes'            => '<a href="/about">About</a>',
	'the same path with a slash'               => '<a href="/about/">About</a>',
	'a part of a page'                         => '<a href="/about#careers">Careers</a>',
	'a query'                                  => '<a href="/contact?ref=nav">Contact</a>',
	'a part after a query'                     => '<a href="/contact?ref=nav#form">Contact</a>',
	'the Home and its part'                    => '<a href="/">Home</a><a href="/#top">Top</a>',
	'a longer path than one in the map'        => '<a href="/about/team">Team</a><a href="/about/teams">Teams</a>',
	'a path that only starts like one'         => '<a href="/aboutus">x</a><a href="/contact-us">y</a>',
	'the old address, both spellings'          => '<a href="https://old.example/about/">a</a><a href="https://old.example/about">b</a>',
	'single quotes, whole and with a part'     => "<a href='/about'>a</a><a href='/about#x'>b</a><a href='/about?y=1'>c</a>",
	'a file name of the archive'               => '<a href="services.dc.html">S</a><a href="services.dc.html#faq">F</a>',
	'a navigation link in its attributes'      => '<!-- wp:navigation-link {"label":"About","url":"/about"} /-->',
	'escaped slashes in the attributes'        => '<!-- wp:navigation-link {"label":"About","url":"\/about\/team","x":"\/contact#f"} /-->',
	'mixed slashes are not a spelling'         => '<!-- wp:x {"url":"\/about/team"} /-->',
	'a link in an escaped attribute'           => '<!-- wp:html {"content":"<a href=\"\/about\">A<\/a><a href=\"\/contact?x=1\">C<\/a>"} /-->',
	'an escaped attribute with plain slashes'  => '<!-- wp:html {"content":"<a href=\"/about\">A</a>"} /-->',
	'a page address with a query in the map'   => '<a href="/fire-damage-restoration-oregon">F</a><a href="/fire-damage-restoration-oregon#call">C</a>',
	'nothing to rewrite'                       => '<p>No links here, only text with href in it.</p>',
	'empty and odd values'                     => '<a href="">e</a><a href="#">h</a><a href="?">q</a><a href="mailto:a@b.c">m</a>',
	'the same link many times'                 => str_repeat( '<a href="/about">a</a> <a href="/contact#x">c</a>', 50 ),
);
foreach ( $cases as $what => $markup ) {
	$want = $each( $markup, $map );
	$got  = $lookup( $markup, $map );
	$expect( $what . ': the answer the search gave', $want === $got );
	if ( $want !== $got ) {
		echo '        want ' . $want . "\n        got  " . $got . "\n";
	}
}
$expect( 'and the search changed something in most of them (the cases test the rewrite, not its absence)', count( array_filter( $cases, static fn( string $m ): bool => $each( $m, $map ) !== $m ) ) >= 15 );

/*
 * Every place and spelling at random: 3,000 pieces of markup built from the map's paths, near misses, parts, queries, the four
 * places a link is written and both ways of writing a slash, each held to the search's answer.
 */
mt_srand( 44 );
$paths   = array_merge( array_keys( $map ), array( '/about-', '/abou', 'services.dc.htm', '/x/about', 'https://old.example/abou', '' ) );
$tails   = array( '', '', '', '#a', '?b=1', '#a?b', '?b#c', '/', '//' );
$places  = array(
	static fn( string $v ): string => 'href="' . $v . '"',
	static fn( string $v ): string => "href='" . $v . "'",
	static fn( string $v ): string => '"url":"' . $v . '"',
	static fn( string $v ): string => '"url":"' . str_replace( '/', '\\/', $v ) . '"',
	static fn( string $v ): string => 'href=\"' . str_replace( '/', '\\/', $v ) . '\"',
	static fn( string $v ): string => 'href=\"' . $v . '\"',
);
$differ  = 0;
$changed = 0;
for ( $i = 0; $i < 3000; $i++ ) {
	$markup = '';
	for ( $j = 0, $n = mt_rand( 1, 6 ); $j < $n; $j++ ) {
		$value   = $paths[ mt_rand( 0, count( $paths ) - 1 ) ] . $tails[ mt_rand( 0, count( $tails ) - 1 ) ];
		$markup .= '<a ' . $places[ mt_rand( 0, count( $places ) - 1 ) ]( $value ) . '>x</a> ';
	}
	$want = $each( $markup, $map );
	if ( $want !== $markup ) {
		++$changed;
	}
	if ( $want !== $lookup( $markup, $map ) ) {
		++$differ;
		if ( $differ <= 3 ) {
			echo '        differs on ' . $markup . "\n";
		}
	}
}
$expect( "3000 random pieces of markup: the search's answer every time ($changed of them rewritten, $differ different)", 0 === $differ && $changed > 1000 );

/*
 * A site's worth: 96 pages, a dozen spellings each (permalink_map()'s variants), on a 300 KB page with 400 links. The search took
 * half a second a page here; one lookup a link must take a small part of that.
 */
$big = array();
for ( $p = 0; $p < 96; $p++ ) {
	foreach ( array( "/page-$p", "/page-$p/", "https://old.example/page-$p", "https://old.example/page-$p/", "page-$p.dc.html", "/area/page-$p" ) as $spelling ) {
		$big[ $spelling ] = "https://site.test/page-$p/";
	}
}
$big  = $ordered( $big );
$page = '';
for ( $l = 0; $l < 400; $l++ ) {
	$page .= '<div class="card"><p>' . str_repeat( 'Lorem ipsum dolor sit amet. ', 25 ) . '</p><a href="/page-' . ( $l % 120 ) . ( $l % 3 ? '' : '#more' ) . '">More</a></div>';
}
$t0      = microtime( true );
$by_each = $each( $page, $big );
$t_each  = microtime( true ) - $t0;
$t0      = microtime( true );
$by_one  = $lookup( $page, $big );
$t_one   = microtime( true ) - $t0;
printf( "        %d spellings, %d KB: the search %.0f ms, one lookup a link %.0f ms\n", count( $big ), (int) ( strlen( $page ) / 1024 ), $t_each * 1000, $t_one * 1000 );
$expect( 'a site of 96 pages: the same answer', $by_each === $by_one && $by_one !== $page );
$expect( 'and at least five times faster than the search', $t_one * 5 < $t_each );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
