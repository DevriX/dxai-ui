<?php
/**
 * A JavaScript string literal's escapes reach the page as characters.
 *
 * `{i < words.length - 1 ? " " : ""}` is how a design writes a
 * non-breaking space between the words of a heading reveal. read_quoted()
 * returned the raw slice between the quotes, so what shipped was the six
 * characters \, u, 0, 0, A, 0 — eighteen of them visible on DevriX Elevate's
 * front page, one after every word of its headline. Nothing could see it: the
 * geometry oracle builds its design side with this same compiler, so both
 * sides carried the same six characters and agreed at 0px. It took a diff
 * against the design's real Vite build to find.
 *
 * So the decoder is pinned here, character by character.
 *
 * usage: bin/wp-php.sh bin/jsx-escapes.php
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Jsx_Compiler;

$decode = new ReflectionMethod( Jsx_Compiler::class, 'decode_js_escapes' );
$decode->setAccessible( true );

$cases = array(
	array( ' ', "\u{00A0}", 'a lone escaped nbsp' ),
	array( 'a b', "a\u{00A0}b", 'an nbsp between words' ),
	array( '360 ', "360\u{00A0}", 'the shape DevriX Elevate ships' ),
	array( '\u{1F600}', "\u{1F600}", 'a code point in braces' ),
	array( '—', "\u{2014}", 'an em dash' ),
	array( '\x41', 'A', 'a hex escape' ),
	array( 'line\nbreak', "line\nbreak", 'a newline' ),
	array( 'tab\there', "tab\there", 'a tab' ),
	array( 'a\rb', "a\rb", 'a carriage return' ),
	array( 'quote\"in', 'quote"in', 'an escaped quote' ),
	array( "it\\'s", "it's", 'an escaped apostrophe' ),
	array( 'back\\\\slash', 'back\\slash', 'an escaped backslash' ),
	array( 'plain', 'plain', 'nothing to decode' ),
	/*
	 * JavaScript rejects an unknown escape's backslash and keeps the character
	 * — `\q` is `q` — and a truncated `\u00A` is a SyntaxError it never gets to
	 * run, so the same rule is the honest answer for malformed input.
	 */
	array( '\q', 'q', 'an unknown escape keeps its character' ),
	array( '\u00A', 'u00A', 'a truncated escape follows the unknown-escape rule' ),
	// A line continuation contributes nothing at all.
	array( "one\\\ntwo", 'onetwo', 'a backslash before a newline' ),
);

$fails = 0;
foreach ( $cases as $case ) {
	list( $in, $want, $label ) = $case;
	$got = $decode->invoke( null, (string) $in );
	if ( $got !== $want ) {
		++$fails;
		printf( "FAIL %-46s want %s got %s\n", $label, (string) wp_json_encode( $want ), (string) wp_json_encode( $got ) );
		continue;
	}
	printf( "ok   %s\n", $label );
}

echo PHP_EOL . 'jsx-escapes: ' . ( count( $cases ) - $fails ) . '/' . count( $cases ) . ' pass' . PHP_EOL;
if ( $fails > 0 ) {
	exit( 1 );
}
