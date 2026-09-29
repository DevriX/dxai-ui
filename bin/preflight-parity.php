<?php
/**
 * Our scoped reset must not give an element a property Tailwind's preflight
 * does not give it.
 *
 * The designs are authored against Tailwind's preflight, and the geometry
 * oracle is built with the real Tailwind CLI, so any extra declaration here is
 * a divergence between the page and the design by construction.
 *
 * One cost it already had: both branches folded `svg` into the `max-width:
 * 100%` rule, which Tailwind applies to `img, video` only. A lucide icon
 * arrives as `<i data-lucide="chevron-down" width="14" height="14">` and the
 * runtime turns it into an `<svg>` with those attributes; with `max-width:
 * 100%` inside a flex button it lays out 0px wide, because an attribute width
 * is not a used width and the percentage resolves against a containing block
 * sized by its own children. ARA's chevron measured `w 9→0`, every nav item
 * came out 14px narrower, and the logo took the slack — 22 box differences at
 * 768px, with nothing in the report naming a reset rule.
 *
 * The expectations below are the real CLI's bytes, not a reading of the docs:
 *
 *     img,svg,video,canvas,audio,iframe,embed,object{vertical-align:middle;display:block}
 *     img,video{max-width:100%;height:auto}
 *
 * Pass an oracle's oracle.css to check against that build instead of the
 * recorded pair, which is how this stays honest across a Tailwind upgrade.
 *
 * usage: bin/wp-php.sh bin/preflight-parity.php [path/to/oracle.css]
 * exit:  0 no element takes a property Tailwind withholds, 1 one does
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

/** The properties Tailwind's preflight sets, per element. */
$expected = array(
	'img'    => array( 'vertical-align', 'display', 'max-width', 'height' ),
	'svg'    => array( 'vertical-align', 'display' ),
	'video'  => array( 'vertical-align', 'display', 'max-width', 'height' ),
	'canvas' => array( 'vertical-align', 'display' ),
	'audio'  => array( 'vertical-align', 'display' ),
	'iframe' => array( 'vertical-align', 'display' ),
	'embed'  => array( 'vertical-align', 'display' ),
	'object' => array( 'vertical-align', 'display' ),
);

/*
 * When an oracle's CSS is to hand, read the pair out of it instead. Tailwind
 * ships these as two rules with two element lists; parse both and rebuild the
 * per-element property sets, so an upstream change is picked up rather than
 * silently contradicted by the table above.
 */
$css_path = (string) ( $argv[1] ?? '' );
if ( $css_path !== '' && is_readable( $css_path ) ) {
	$css = (string) file_get_contents( $css_path );
	/*
	 * Every bare element-list rule, then keep the ones naming img or svg. The
	 * earlier version anchored each match on the PREVIOUS rule's `}` and
	 * consumed it, so Tailwind's second rule — `img,video{max-width:100%;
	 * height:auto}`, which follows the first immediately — never matched, and
	 * the check reported img and video as taking properties Tailwind withholds
	 * when Tailwind grants them.
	 */
	if ( preg_match_all( '/([a-z][a-z0-9,]*)\{([^}]*)\}/i', $css, $m, PREG_SET_ORDER ) ) {
		$found = array();
		foreach ( $m as $rule ) {
			$elements = array_values( array_filter( array_map( 'trim', explode( ',', strtolower( $rule[1] ) ) ) ) );
			if ( ! in_array( 'img', $elements, true ) && ! in_array( 'svg', $elements, true ) ) {
				continue;
			}
			foreach ( $elements as $element ) {
				foreach ( explode( ';', $rule[2] ) as $declaration ) {
					$name = strtolower( trim( (string) strstr( $declaration, ':', true ) ) );
					if ( $name !== '' ) {
						$found[ $element ][ $name ] = true;
					}
				}
			}
		}
		if ( $found !== array() ) {
			$expected = array_map( 'array_keys', $found );
			printf( "read the preflight pair from %s\n", basename( $css_path ) );
		}
	}
}

/* Both branches of scoped_preflight(): with and without a design that ships
 * its own `.rv-` resets. */
$purger = new DXAI_UI\Compiler\Tailwind_Purger();
$method = new ReflectionMethod( $purger, 'scoped_preflight' );
$method->setAccessible( true );

$branches = array(
	'design ships .rv- resets' => '.rv-root { color: red }',
	'no design resets'         => '',
);

/*
 * The generated reset is not the only one. assets/css/frontend-isolate.css is
 * a STATIC copy enqueued by Assets, Pattern_Block, Design_Blocks and
 * Converter_Controller, and it is the one the front end actually loads — so
 * correcting scoped_preflight() alone changed nothing on the page. Both are
 * checked here for exactly that reason.
 */
$resets = array();
foreach ( $branches as $label => $design_css ) {
	$resets[ $label ] = (string) $method->invoke( $purger, '.dxai-ui', $design_css );
}
$isolate = DXAI_UI_DIR . 'assets/css/frontend-isolate.css';
if ( is_readable( $isolate ) ) {
	// Flatten to one selector-list-per-line so the same parser reads it: the
	// static file is authored across multiple lines.
	$resets['assets/css/frontend-isolate.css'] = (string) preg_replace(
		array( '/\/\*[\s\S]*?\*\//', '/\s*\n\s*/', '/\}/' ),
		array( '', ' ', "}\n" ),
		(string) file_get_contents( $isolate )
	);
} else {
	print "WARNING: assets/css/frontend-isolate.css not readable — the static reset is unchecked\n";
}

$fail = 0;
foreach ( $resets as $label => $reset ) {
	printf( "\n--- %s\n", $label );

	// Which properties does our reset set on each element?
	$ours = array();
	foreach ( explode( "\n", $reset ) as $line ) {
		$brace = strpos( $line, '{' );
		if ( $brace === false ) {
			continue;
		}
		$selectors = strtolower( substr( $line, 0, $brace ) );
		$body      = trim( (string) substr( $line, $brace + 1 ), " {}\t" );
		foreach ( explode( ',', $selectors ) as $selector ) {
			$selector = trim( str_replace( '.dxai-ui', '', $selector ) );
			if ( ! isset( $expected[ $selector ] ) ) {
				continue;
			}
			foreach ( explode( ';', $body ) as $declaration ) {
				$name = strtolower( trim( (string) strstr( $declaration, ':', true ) ) );
				if ( $name !== '' ) {
					$ours[ $selector ][] = $name;
				}
			}
		}
	}

	foreach ( $expected as $element => $allowed ) {
		$set   = array_values( array_unique( $ours[ $element ] ?? array() ) );
		$extra = array_diff( $set, $allowed );
		if ( $extra !== array() ) {
			++$fail;
			printf(
				"  FAIL  %-7s takes %s, which Tailwind withholds from it\n",
				$element,
				implode( ', ', $extra )
			);
			continue;
		}
		printf( "  ok    %-7s %s\n", $element, $set === array() ? '(nothing)' : implode( ', ', $set ) );
	}
}

printf( "\n%s\n", $fail === 0 ? 'no element takes a property Tailwind withholds' : $fail . ' divergence(s)' );
exit( $fail === 0 ? 0 : 1 );
