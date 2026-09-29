<?php
/**
 * `cn()` against tailwind-merge's own documented behaviour.
 *
 * Every shadcn file joins its classes with `cn()`, which is
 * `twMerge(clsx(inputs))` — and twMerge DROPS a class a later one overrides.
 * We joined instead, so the list we emitted was longer than the one the real
 * app renders, and which of two conflicting classes won was decided by our
 * sheet's ordering rather than by the design.
 *
 * The cases below are the behaviour upstream specifies, plus the shapes the
 * shadcn kit actually produces. Each is a one-line assertion, because the risk
 * here is not "fails to merge" — that is what the code did before and it costs
 * a duplicate class. The risk is merging something that should NOT merge,
 * which DELETES a class the design meant to keep, and no geometry check can
 * tell that from a design that never had it.
 *
 * usage: php bin/class-merge.php
 * exit:  0 every case matches, 1 any case differs
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
require_once $root . '/src/Compiler/Tailwind/Candidate.php';
require_once $root . '/src/Compiler/Tailwind/Class_Merge.php';

use DXAI_UI\Compiler\Tailwind\Class_Merge;

/** [ input, expected, why ] */
$cases = array(
	// The spacing shorthand relation, which is one-directional.
	array( 'px-2 py-1 p-3', 'p-3', 'a later shorthand removes the narrower ones' ),
	array( 'p-3 px-2', 'p-3 px-2', 'a later narrower class does NOT remove the shorthand' ),
	array( 'p-5 p-2 p-4', 'p-4', 'last of the same group wins' ),
	array( 'mt-4 my-2', 'my-2', 'my covers mt' ),
	array( 'my-2 mt-4', 'my-2 mt-4', 'and not the other way round' ),
	array( 'gap-x-4 gap-2', 'gap-2', 'gap covers both axes' ),
	array( 'gap-2 gap-x-4', 'gap-2 gap-x-4', 'one axis does not cover the pair' ),

	// Font size versus colour, which is the one that bites in the kit.
	array( 'text-sm text-base', 'text-base', 'two font sizes conflict' ),
	array( 'text-sm text-red-500', 'text-sm text-red-500', 'a size and a colour do not' ),
	array( 'text-muted-foreground text-primary', 'text-primary', 'two colours conflict' ),
	array( 'text-center text-sm', 'text-center text-sm', 'alignment is its own group' ),
	array( 'text-left text-center', 'text-center', 'two alignments conflict' ),

	// Weight versus family.
	array( 'font-bold font-display', 'font-bold font-display', 'weight and family are separate' ),
	array( 'font-medium font-bold', 'font-bold', 'two weights conflict' ),

	// Radius and border, per side.
	array( 'rounded-t-sm rounded-lg', 'rounded-lg', 'the shorthand covers every corner' ),
	array( 'rounded-lg rounded-t-sm', 'rounded-lg rounded-t-sm', 'a side does not cover the shorthand' ),
	array( 'border-x-4 border-2', 'border-2', 'the width shorthand covers the sides' ),
	array( 'border-2 border-x-4', 'border-2 border-x-4', 'and not the reverse' ),
	array( 'border-border border-primary', 'border-primary', 'two border colours conflict' ),
	array( 'border-2 border-primary', 'border-2 border-primary', 'a width and a colour do not' ),

	// Colours and sizes that share a prefix.
	array( 'bg-red-500 bg-blue-300', 'bg-blue-300', 'two background colours conflict' ),
	array( 'bg-cover bg-primary', 'bg-cover bg-primary', 'a background size and a colour do not' ),
	array( 'shadow-lg shadow-red-500', 'shadow-lg shadow-red-500', 'a shadow size and its colour do not' ),
	array( 'shadow-sm shadow-lg', 'shadow-lg', 'two shadow sizes conflict' ),

	// Sizing.
	array( 'w-4 h-4 size-6', 'size-6', 'size covers both axes' ),
	array( 'size-6 w-4', 'size-6 w-4', 'and not the reverse' ),
	array( 'grid-cols-2 grid-cols-3', 'grid-cols-3', 'same group' ),

	// Keyword groups.
	array( 'block flex', 'flex', 'two displays conflict' ),
	array( 'flex flex-col', 'flex flex-col', 'display and direction are separate' ),
	array( 'absolute relative', 'relative', 'two positions conflict' ),
	array( 'overflow-hidden overflow-auto', 'overflow-auto', 'two overflows conflict' ),

	// Variants partition everything.
	array( 'hover:block hover:inline', 'hover:inline', 'same variant, same group' ),
	array( 'block hover:inline', 'block hover:inline', 'different variants never conflict' ),
	array( 'md:px-2 md:p-4', 'md:p-4', 'the relation holds inside a variant' ),
	array( 'px-2 md:p-4', 'px-2 md:p-4', 'and does not cross one' ),
	array( 'hover:bg-primary/90 hover:bg-accent/90', 'hover:bg-accent/90', 'an opacity modifier does not change the group' ),

	// Negation.
	array( 'inset-x-px -inset-1', '-inset-1', 'a negative class is in its positive group' ),

	/*
	 * A design's own token. Upstream validates the value against the DEFAULT
	 * scale before it assigns a group, so `p-gutter` — where `gutter` is the
	 * project's own spacing key — is not in the padding group and survives. We
	 * must not be cleverer than upstream here: grouping it would delete a class
	 * the real app renders.
	 */
	array( 'p-gutter p-4', 'p-gutter p-4', 'a custom spacing token is not on the default scale' ),
	array( 'p-gutter pt-0', 'p-gutter pt-0', 'and still does not conflict with a side' ),
	array( 'max-w-measure max-w-2xl', 'max-w-measure max-w-2xl', 'a custom max-width token likewise' ),

	// Classes we do not recognise pass through untouched, duplicates included,
	// which is what upstream does too.
	array( 'eyebrow band eyebrow', 'eyebrow band eyebrow', 'unknown classes are never touched' ),
	array( 'rule-list', 'rule-list', 'a design class survives alone' ),

	/*
	 * The `-offset-` families. Found by a fixture, not by reasoning: the
	 * shadcn Input carries all three of these and `ring-offset-2` was deleting
	 * `ring-ring`, so the focus ring lost its colour.
	 */
	array(
		'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
		'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
		'a ring width, a ring colour and a ring offset are three groups',
	),
	array( 'ring-offset-2 ring-offset-background', 'ring-offset-2 ring-offset-background', 'offset width and offset colour differ' ),
	array( 'ring-offset-2 ring-offset-4', 'ring-offset-4', 'two offset widths conflict' ),
	array( 'outline-offset-2 outline-primary', 'outline-offset-2 outline-primary', 'outline offset is not the outline colour' ),
	array( 'bg-gradient-to-r bg-primary', 'bg-gradient-to-r bg-primary', 'a gradient is not a background colour' ),

	// The real shadcn Button, size lg. `text-sm` must go and nothing else.
	array(
		'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium'
		. ' transition-colors disabled:pointer-events-none disabled:opacity-50'
		. ' bg-primary text-primary-foreground hover:bg-primary/90 h-12 px-8 text-base',
		'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium'
		. ' transition-colors disabled:pointer-events-none disabled:opacity-50'
		. ' bg-primary text-primary-foreground hover:bg-primary/90 h-12 px-8 text-base',
		'the kit Button at size lg drops its base font size',
	),
);

$failed = 0;
foreach ( $cases as [ $input, $expected, $why ] ) {
	$got = Class_Merge::merge( $input );
	if ( $got === $expected ) {
		printf( "  ok    %s\n", $why );
		continue;
	}
	++$failed;
	printf( "  FAIL  %s\n        in   %s\n        want %s\n        got  %s\n", $why, $input, $expected, $got );
}

printf( "\n%d of %d cases match\n", count( $cases ) - $failed, count( $cases ) );
echo $failed === 0 ? "class-merge: ok\n" : "class-merge: FAIL\n";
exit( $failed === 0 ? 0 : 1 );
