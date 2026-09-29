<?php
/**
 * Does Jsx_Compiler evaluate an expression to the value JavaScript would?
 *
 * This is a SURFACE check, not a corpus one. The seven design ZIPs exercise
 * whatever their authors happened to write; hundreds of arbitrary Lovable
 * projects will not. So the matrix below is a spread of JS expression shapes
 * with their real values, and every one is evaluated in all three positions a
 * const can occupy, because the three take different code paths and disagreed:
 *
 *   inline in JSX   -> the expression parser directly
 *   prelude const   -> bind_prelude() extracts the right-hand side first
 *   module const    -> bind_consts() extracts it, same extractor
 *
 * Two live defects were found by exactly this comparison, both silent —
 * unevaluated() reported nothing and the page simply carried a wrong value:
 *
 *   1. `Math.round(x)` evaluated to the STRING 'Math'. parse_postfix() sends
 *      every `.name(` to call_method(), which knows array and string methods
 *      and returns its receiver for anything else; the implemented Math path
 *      (member() -> 'Math:round' -> call_value()) was unreachable for a call.
 *      GTM Strategy Hub shipped `style="width:Math%"` — invalid, so the
 *      progress bar rendered 0px wide — and the visible text `Math%` four
 *      times where the design shows a percentage.
 *
 *   2. `(num / total) * 100` evaluated to 0.25 instead of 25 in a const, while
 *      being correct inline. extract_assignment() stopped at the matching
 *      bracket when a right-hand side STARTED with one, so `* 100` was never
 *      handed to the evaluator. The same truncation cut
 *      `const cls = (open ? "a" : "b") + c` down to its parenthesised head.
 *
 * Neither was visible to the geometry check: bin/design-oracle.php builds its
 * design side with this same compiler, so a wrong value lands on both sides and
 * the diff reports 0px. They surfaced only against the design's real React
 * build. This file is the cheap check that keeps them from coming back.
 *
 * usage: bin/wp-php.sh bin/jsx-expression-parity.php [--verbose]
 * exit:  0 every expression evaluates correctly in every position, 1 otherwise
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

$verbose = in_array( '--verbose', $argv, true );

/*
 * [ expression, expected string value ].
 *
 * Expected values are what JavaScript produces, written out rather than
 * computed, so this file states the contract instead of asking the code under
 * test what it thinks. `num` is 1 and `total` is 4 in every case; `d` is
 * { num: 1, total: 4 }; `items` is [3, 1, 2].
 */
$cases = array(
	/*
	 * Strict equality, which was PHP identity and therefore compared the
	 * numeric TYPE as well. This evaluator has two number types where
	 * JavaScript has one — an index or a literal is an int, every arithmetic
	 * result is a float — so `1 + 1 === 2` was FALSE and with it every
	 * `i === active` in the corpus: no active tab, no rail marker, no shown
	 * slide, and both arms evaluating cleanly so nothing was ever reported.
	 */
	array( '1 + 1 === 2', 'true' ),
	array( '2 === 1 + 1', 'true' ),
	array( 'Math.round(0) === 0', 'true' ),
	array( 'Math.min(2, Math.max(0, Math.round(0))) === 0', 'true' ),
	array( 'num - 1 === 0', 'true' ),
	array( '(num / total) * 4 === 1', 'true' ),
	array( 'num !== 1', 'false' ),
	array( 'num + 1 !== 2', 'false' ),
	// And the pairs JavaScript keeps unequal, which a blanket numeric
	// comparison would have broken: no coercion across types.
	array( '"1" === 1', 'false' ),
	array( 'true === 1', 'false' ),
	array( 'null === 0', 'false' ),
	array( '"a" === "a"', 'true' ),
	// String() is a conversion, not an attribute: a false must read "false".
	array( 'String(num === 2)', 'false' ),
	array( 'String(num === 1)', 'true' ),
	array( 'String(1 + 1 === 2)', 'true' ),

	// Arithmetic, including the continuation-past-a-bracket shapes.
	array( '1 + 2', '3' ),
	array( '(1 / 4) * 100', '25' ),
	array( '(num / total) * 100', '25' ),
	array( '100 * (num / total)', '25' ),
	array( '(num + total) * 2 - 1', '9' ),
	array( '(1 + 1) * (2 + 2)', '8' ),

	// Math, which is a namespace rather than an object.
	array( 'Math.round(4.6)', '5' ),
	array( 'Math.round((num / total) * 100)', '25' ),
	array( 'Math.floor(7.9)', '7' ),
	array( 'Math.ceil(7.1)', '8' ),
	array( 'Math.abs(-3)', '3' ),
	array( 'Math.max(2, 9)', '9' ),
	array( 'Math.min(2, 9)', '2' ),
	array( 'Math.pow(2, 3)', '8' ),

	// A ternary whose condition is parenthesised — the other truncation shape.
	array( '(num < total) ? "under" : "over"', 'under' ),
	array( '(num > total) ? "over" : "under"', 'under' ),
	array( '(num < total ? "a" : "b") + "!"', 'a!' ),

	// Member access and calls continuing past a bracket.
	array( '[1, 2, 3].length', '3' ),
	array( '[1, 2, 3].join("-")', '1-2-3' ),
	array( '("abc").toUpperCase()', 'ABC' ),
	array( '((num / total) * 100).toFixed(1)', '25.0' ),
	array( 'd.num + d.total', '5' ),
	array( 'items.length', '3' ),

	// Strings and template literals.
	array( '`${num}/${total}`', '1/4' ),
	array( '`${Math.round((num / total) * 100)}%`', '25%' ),
	array( '"a" + "b"', 'ab' ),
	array( '[num, total].join(":")', '1:4' ),

	// Logical operators and nullish coalescing.
	array( 'num && total', '4' ),
	array( 'num || total', '1' ),
	array( '(num === 1) ? "one" : "other"', 'one' ),
	array( '(num !== 1) ? "other" : "one"', 'one' ),
);

$positions = array(
	'inline'  => static function ( string $expr ): string {
		return "export function P({ num = 1, total = 4 }) {\n"
			. "  const d = { num: 1, total: 4 };\n"
			. "  const items = [3, 1, 2];\n"
			. "  return (\n    <span data-v={" . $expr . "} />\n  );\n}\n";
	},
	'prelude' => static function ( string $expr ): string {
		return "export function P({ num = 1, total = 4 }) {\n"
			. "  const d = { num: 1, total: 4 };\n"
			. "  const items = [3, 1, 2];\n"
			. "  const v = " . $expr . ";\n"
			. "  return (\n    <span data-v={v} />\n  );\n}\n";
	},
	'module'  => static function ( string $expr ): string {
		return "const num = 1;\nconst total = 4;\n"
			. "const d = { num: 1, total: 4 };\n"
			. "const items = [3, 1, 2];\n"
			. "const v = " . $expr . ";\n\n"
			. "export function P() {\n  return (\n    <span data-v={v} />\n  );\n}\n";
	},
);

$page = "import { P } from \"@/components/P\";\n\n"
	. "export default function Page() {\n  return (\n    <div>\n      <P num={1} total={4} />\n    </div>\n  );\n}\n";

$fail    = 0;
$total_n = 0;
$rows    = array();

foreach ( $cases as [ $expr, $want ] ) {
	$got = array();
	foreach ( $positions as $name => $build ) {
		++$total_n;
		$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
		try {
			$sections = $compiler->compile_file( $page, array(), array( 'src/components/P.tsx' => $build( $expr ) ) );
			$html     = implode( "\n", array_column( $sections, 'html' ) );
		} catch ( Throwable $e ) {
			$got[ $name ] = 'threw';
			++$fail;
			continue;
		}
		preg_match( '/data-v="([^"]*)"/', $html, $m );
		$value        = html_entity_decode( $m[1] ?? '(absent)', ENT_QUOTES );
		$got[ $name ] = $value;
		if ( $value !== $want ) {
			++$fail;
		}
	}

	$ok = count( array_unique( array_merge( array_values( $got ), array( $want ) ) ) ) === 1;
	if ( ! $ok || $verbose ) {
		$rows[] = sprintf(
			"  %-40s want %-8s inline=%-10s prelude=%-10s module=%-10s %s",
			$expr,
			var_export( $want, true ),
			var_export( $got['inline'], true ),
			var_export( $got['prelude'], true ),
			var_export( $got['module'], true ),
			$ok ? 'ok' : 'WRONG'
		);
	}
}

if ( $rows !== array() ) {
	print implode( "\n", $rows ) . "\n\n";
}
printf( "%d of %d evaluations correct (%d expressions x 3 positions)\n", $total_n - $fail, $total_n, count( $cases ) );
exit( $fail === 0 ? 0 : 1 );
