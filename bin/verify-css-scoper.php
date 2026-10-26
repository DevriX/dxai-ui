<?php
/**
 * A design's stylesheet under its page's scope (Compiler\Css_Scoper): the document's own elements — `html`, `body` — stand for the
 * scope wrapper, alone or before what is inside them, at the top of the sheet and inside @media and @supports. And a design that
 * never ran under Tailwind gets no utility for a class name its own stylesheets never style (Compiler\Tailwind_Purger).
 * Run: wp eval-file bin/verify-css-scoper.php
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Css_Scoper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $what . ( ! $ok && $detail !== '' ? '  — ' . $detail : '' ) . "\n";
};
$scope = '.dxai-ui.dxai-ui--7';
Css_Scoper::set_root_classes( '' );
$css = static fn( string $sheet ): string => preg_replace( '/\s+/', ' ', Css_Scoper::apply( $scope, $sheet ) );

$cases = array(
	'body alone is the scope'                          => array( 'body { color: red; }', '.dxai-ui.dxai-ui--7 { color: red; }' ),
	'html alone is the scope'                          => array( 'html { font-size: 16px; }', '.dxai-ui.dxai-ui--7 { font-size: 16px; }' ),
	'a class inside body: under the scope'             => array( 'body .card { margin-left: 20px; }', '.dxai-ui.dxai-ui--7 .card, .dxai-ui.dxai-ui--7.card { margin-left: 20px; }' ),
	'inside html and body'                             => array( 'html body .card { margin: 0; }', '.dxai-ui.dxai-ui--7 .card, .dxai-ui.dxai-ui--7.card { margin: 0; }' ),
	'a child of body: a child of the scope'            => array( 'body > .card { margin: 0; }', '.dxai-ui.dxai-ui--7 > .card { margin: 0; }' ),
	'a tag inside body'                                => array( 'body h2 { line-height: 1.2; }', '.dxai-ui.dxai-ui--7 h2 { line-height: 1.2; }' ),
	'in a media query, as at the top'                  => array( '@media screen and (max-width: 768px) { body .border_gray { margin-left: 20px; } }', '@media screen and (max-width: 768px){.dxai-ui.dxai-ui--7 .border_gray, .dxai-ui.dxai-ui--7.border_gray { margin-left: 20px; }}' ),
	'in @supports, as at the top'                      => array( '@supports (display: grid) { body .grid { display: grid; } }', '@supports (display: grid){.dxai-ui.dxai-ui--7 .grid, .dxai-ui.dxai-ui--7.grid { display: grid; }}' ),
	'a list mixing body and a class'                   => array( 'body .a, .b { color: blue; }', '.dxai-ui.dxai-ui--7 .a, .dxai-ui.dxai-ui--7.a, .dxai-ui.dxai-ui--7 .b, .dxai-ui.dxai-ui--7.b { color: blue; }' ),
	'a class that only starts with body is a class'    => array( '.bodytext span { margin: 0; }', '.dxai-ui.dxai-ui--7 .bodytext span, .dxai-ui.dxai-ui--7.bodytext span { margin: 0; }' ),
	'a tag that only starts with body is not body'     => array( 'bodyx .y { margin: 0; }', '.dxai-ui.dxai-ui--7 bodyx .y { margin: 0; }' ),
);
foreach ( $cases as $what => list( $in, $want ) ) {
	$got = trim( (string) $css( $in ) );
	$expect( $what, $got === $want, 'got ' . $got );
}
// What scoped `body .x` used to be: a selector no element of the page can match.
$expect( 'no scoped rule names body after the scope', ! str_contains( (string) $css( "body .a { x: 1 } @media (min-width: 1px) { html body > .b { x: 2 } body ~ .c { x: 3 } }" ), $scope . ' body' ) );

// A design that never ran under Tailwind (Tailwind_Purger::without_preflight(): a Claude Design package, a static HTML site) gets no
// utility for a class name its own stylesheets never style: in its author's browser the name did nothing. Charter Construction:
// Bootstrap's `collapse in` on an open accordion panel (visibility: collapse hid it), a pasted ChatGPT answer's `text-base pb-10`, and
// WordPress's `size-full` on pictures (height: 100%).
echo "\nA design that never ran under Tailwind\n";
$markup   = '<div class="panel-collapse collapse in"><div class="text-base pb-10 own-card md:flex">x</div><img class="size-full flex" alt=""></div>';
$own_css  = '.own-card { color: red; } .flex { display: flex; } @media (min-width: 768px) { .md\\:flex { gap: 1px; } }';
$utility  = static fn( string $sheet, string $name, string $body ): bool => str_contains( $sheet, '.dxai-ui.dxai-ui--7 .' . $name . ' { ' . $body . ' }' );
$static   = new DXAI_UI\Compiler\Tailwind_Purger();
$static->without_preflight();
$sheet    = $static->compile( $markup, $own_css, 7 );
$expect( 'no utility for a name its stylesheets never style: collapse, size-full, text-base, pb-10', ! $utility( $sheet, 'collapse', 'visibility:collapse;' ) && ! $utility( $sheet, 'size-full', 'width:100%;height:100%;' ) && ! str_contains( $sheet, '.text-base {' ) && ! str_contains( $sheet, '.pb-10 {' ), $sheet );
$expect( 'a name its stylesheets style keeps its utility, escaped or not (flex, md:flex)', $utility( $sheet, 'flex', 'display:flex;' ) && $utility( $sheet, 'md\\:flex', 'display:flex;' ) );
$tailwind = ( new DXAI_UI\Compiler\Tailwind_Purger() )->compile( $markup, $own_css, 7 );
$expect( 'a design that ran under Tailwind still gets every utility in its markup', $utility( $tailwind, 'collapse', 'visibility:collapse;' ) && $utility( $tailwind, 'pb-10', 'padding-bottom:2.5rem;' ) && $utility( $tailwind, 'size-full', 'width:100%;height:100%;' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
