<?php
/**
 * What a Tailwind v3 ("legacy Vite SPA") Lovable project loses in conversion.
 *
 * Every Lovable export on hand is a TanStack Start project whose design tokens
 * live in CSS `@theme` blocks. The classic Lovable template — `vite_react_shadcn_ts`,
 * which is what most projects made before the TanStack switch are — declares
 * them in `tailwind.config.ts` instead, and writes `@tailwind base;` plus
 * `@apply` in `src/index.css`. Nothing in the suite covered that shape, so this
 * check compiles the fixture at fixtures/legacy-vite-spa and reports every
 * class the engine cannot resolve.
 *
 * It also packs the fixture into the ZIP the rest of the suite imports, so
 * there is one builder and the archive cannot drift from the directory it was
 * made from. `--zip-only` stops after that, which is how bin/verify-import.cjs
 * gets the archive before its import stage runs.
 *
 *   bin/wp-php.sh bin/legacy-shape.php [<project-dir>] [--zip-only]
 *
 * Exits 1 when a utility the fixture uses resolves to nothing, when a token
 * resolves to the wrong value, or when the ZIP does not carry the config
 * through the connector and the compiler.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require __DIR__ . '/wp-boot.php';

$root      = dirname( __DIR__ );
$arguments = array_values( array_filter( array_slice( $argv, 1 ), static fn( $a ) => ! str_starts_with( (string) $a, '--' ) ) );
$zip_only  = in_array( '--zip-only', $argv, true );
$project   = $arguments[0] ?? $root . '/fixtures/legacy-vite-spa';
$zip_path  = $root . '/.verify/legacy-vite-spa.zip';

/**
 * Pack the fixture directory the way a Lovable export is packed: forward
 * slashes, no wrapper directory, node_modules and build output left out.
 */
function dxai_pack_fixture( string $dir, string $out ): int {
	wp_mkdir_p( dirname( $out ) );
	if ( file_exists( $out ) ) {
		unlink( $out );
	}

	$zip = new ZipArchive();
	if ( $zip->open( $out, ZipArchive::CREATE ) !== true ) {
		fwrite( STDERR, "legacy-shape: could not create {$out}\n" );
		exit( 2 );
	}

	$count    = 0;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
		if ( preg_match( '#(^|/)(node_modules|dist|\.git)/#', $relative ) === 1 ) {
			continue;
		}
		$zip->addFile( $file->getPathname(), $relative );
		++$count;
	}
	$zip->close();

	return $count;
}

$packed = dxai_pack_fixture( $project, $zip_path );
echo 'zip=' . $zip_path . ' files=' . $packed . "\n";
if ( $zip_only ) {
	exit( 0 );
}

$css    = (string) @file_get_contents( $project . '/src/index.css' );
$config = '';
foreach ( array( 'ts', 'js', 'cjs', 'mjs' ) as $ext ) {
	$candidate = $project . '/tailwind.config.' . $ext;
	if ( is_readable( $candidate ) ) {
		$config = (string) file_get_contents( $candidate );
		break;
	}
}

if ( $css === '' ) {
	fwrite( STDERR, "legacy-shape: no src/index.css under {$project}\n" );
	exit( 2 );
}

echo 'config=' . ( $config !== '' ? 'found' : 'MISSING' ) . "\n";

/*
 * The classes the fixture actually ships. Read from the sources rather than
 * from a compiled DOM so this check does not need an oracle build first.
 */
$markup = '';
$walk   = static function ( string $dir ) use ( &$walk, &$markup ): void {
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( $entry === '.' || $entry === '..' || $entry === 'node_modules' ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			$walk( $path );
			continue;
		}
		if ( preg_match( '/\.(tsx|jsx)$/', $entry ) ) {
			$markup .= (string) file_get_contents( $path ) . "\n";
		}
	}
};
$walk( $project . '/src' );

$theme  = DXAI_UI\Compiler\Tailwind\Theme::from_css( $css, $config );
$engine = new DXAI_UI\Compiler\Tailwind\Engine( $theme );

/*
 * `className="a b c"` and cva/cn string arguments both hold class lists, and
 * extract_classes() already reads a class attribute; feeding it the raw source
 * would miss the ones inside cva(). Quoted runs that look like class lists are
 * the practical superset.
 */
$single_word = array(
	'container', 'flex', 'grid', 'block', 'inline', 'hidden', 'contents', 'table',
	'relative', 'absolute', 'fixed', 'sticky', 'static', 'italic', 'underline',
	'uppercase', 'lowercase', 'capitalize', 'truncate', 'antialiased', 'invisible',
	'visible', 'isolate', 'transform', 'filter', 'transition', 'resize', 'appearance',
	'overflow',
);

$classes = array();
if ( preg_match_all( '/"([^"\n]{2,400})"|\'([^\'\n]{2,400})\'/', $markup, $matches ) ) {
	foreach ( array_merge( $matches[1], $matches[2] ) as $run ) {
		foreach ( preg_split( '/\s+/', (string) $run ) ?: array() as $class ) {
			$class = trim( $class );
			if ( $class === '' || ! DXAI_UI\Compiler\Tailwind\Engine::looks_like_utility( $class ) ) {
				continue;
			}
			// Import specifiers and cva variant keys read as utilities to a
			// scraper working on raw source. A module path always carries a
			// dot or an `@`, and the only legitimate slash in a class is an
			// opacity modifier, which is numeric.
			if ( str_contains( $class, '.' ) || str_contains( $class, '@' ) ) {
				continue;
			}
			if ( str_contains( $class, '/' ) && preg_match( '#/\d+(?:\.\d+)?$#', $class ) !== 1 ) {
				continue;
			}
			// `accent`, `default`, `from`, `to` are object keys, not classes.
			// A real single-word utility is one Tailwind actually ships.
			if ( ! preg_match( '/[-:\[]/', $class ) && ! in_array( $class, $single_word, true ) ) {
				continue;
			}
			$classes[ $class ] = true;
		}
	}
}

$sheet = $engine->compile( '<div class="' . implode( ' ', array_keys( $classes ) ) . '"></div>', '.dxai-ui' );

$unresolved = array();
foreach ( $engine->unresolved() as $class ) {
	$unresolved[] = $class;
}
sort( $unresolved );

echo 'classes=' . count( $classes ) . ' unresolved=' . count( $unresolved ) . "\n";
if ( $unresolved !== array() ) {
	echo "  " . implode( "\n  ", array_slice( $unresolved, 0, 60 ) ) . "\n";
}

/*
 * Resolving is not the same as resolving CORRECTLY. A v3 config maps
 * `bg-primary` onto `hsl(var(--primary))`; with the config ignored the engine
 * still emits a rule, using v4's own default palette or nothing at all — and
 * the page renders a colour the design never declared. So assert the value.
 */
$expect = array(
	'bg-primary'            => 'hsl(var(--primary))',
	'text-muted-foreground' => 'hsl(var(--muted-foreground))',
	'border-border'         => 'hsl(var(--border))',
	'bg-ledger-ink'         => '#12211c',
	'rounded-lg'            => 'var(--radius)',
	'py-band'               => '5.5rem',
	'max-w-measure'         => '34rem',
	'font-display'          => 'Fraunces',
	'animate-rise-in'       => 'rise-in',
	'shadow-card'           => 'hsl(var(--border))',
);

$wrong = array();
foreach ( $expect as $class => $needle ) {
	$one  = ( new DXAI_UI\Compiler\Tailwind\Engine( $theme ) )->compile( '<div class="' . $class . '"></div>', '.dxai-ui' );
	$body = '';
	if ( preg_match( '/\{([^{}]*)\}/', $one, $m ) ) {
		$body = trim( (string) $m[1] );
	}
	if ( ! str_contains( $one, $needle ) ) {
		$wrong[] = sprintf( '%-22s want %-28s got %s', $class, $needle, $body !== '' ? $body : '(nothing)' );
	}
}

echo 'tokens=' . count( $expect ) . ' wrong=' . count( $wrong ) . "\n";
foreach ( $wrong as $line ) {
	echo '  ' . $line . "\n";
}

/*
 * An `animation` entry names a keyframes block, and a v3 config declares both.
 * Registering the name without the block emits `animation: rise-in .5s both`
 * against a `@keyframes` that does not exist — the element renders at whatever
 * the animation's absent first frame would have been, which for the accordion
 * is a panel that never opens.
 */
$frames  = ( new DXAI_UI\Compiler\Tailwind\Engine( $theme ) )
	->compile( '<div class="animate-rise-in animate-accordion-down"></div>', '.dxai-ui' );
$missing_frames = array();
foreach ( array( 'rise-in', 'accordion-down' ) as $name ) {
	if ( ! preg_match( '/@keyframes\s+' . preg_quote( $name, '/' ) . '\b/', $frames ) ) {
		$missing_frames[] = $name;
	}
}
echo 'keyframes_missing=' . count( $missing_frames )
	. ( $missing_frames !== array() ? '  ' . implode( ' ', $missing_frames ) : '' ) . "\n";

/*
 * Everything above asks whether the engine CAN read a v3 config. Production
 * asks a different question: whether it ever gets one. The connector treats
 * `tailwind.config` as junk and no caller passes it, so the sheet a converted
 * page actually loads is built from the CSS alone — this is the same
 * measurement with the config withheld, which is what ships today.
 */
$bare   = DXAI_UI\Compiler\Tailwind\Theme::from_css( $css );
$as_shipped = array();
foreach ( $expect as $class => $needle ) {
	$one = ( new DXAI_UI\Compiler\Tailwind\Engine( $bare ) )->compile( '<div class="' . $class . '"></div>', '.dxai-ui' );
	if ( ! str_contains( $one, $needle ) ) {
		$as_shipped[] = $class;
	}
}
echo 'without_config_wrong=' . count( $as_shipped ) . '/' . count( $expect )
	. ( $as_shipped !== array() ? '  ' . implode( ' ', $as_shipped ) : '' ) . "\n";

/*
 * Everything above measures the engine in isolation. This measures the sheet a
 * converted page actually loads, which is the only artefact that matters:
 * `@apply` is how the classic template sets the page background, the body text
 * colour and the default border colour, and it is expanded by the purger, not
 * by `Design_Css::prepare()`. Asserting the prepared CSS instead reported a
 * failure that was not one and would have hidden a real one later.
 */
$prepared = DXAI_UI\Compiler\Design_Css::prepare( $css );
$sheet    = ( new DXAI_UI\Compiler\Tailwind_Purger() )->compile(
	'<div class="' . implode( ' ', array_keys( $classes ) ) . '"></div>',
	$prepared,
	0,
	'',
	$css,
	'',
	$config
);

$applies    = preg_match_all( '/@apply\b/', $sheet );
$directives = preg_match_all( '/@tailwind\s+[a-z]/i', $sheet );
$unexpanded = preg_match_all( '/@apply unresolved:/', $sheet );

echo 'sheet_bytes=' . strlen( $sheet ) . ' apply_left=' . (int) $applies
	. ' tailwind_directives_left=' . (int) $directives
	. ' apply_unresolved=' . (int) $unexpanded . "\n";

/*
 * The three declarations the template's `@apply` rules exist to produce. Their
 * absence is invisible to a utility-class audit — every class on the page can
 * resolve perfectly while the page itself renders on white.
 */
$body_rules = array(
	'background-color: hsl(var(--background))' => 'body background',
	'color: hsl(var(--foreground))'            => 'body text colour',
	'border-color: hsl(var(--border))'         => 'default border colour',
);
$lost = array();
foreach ( $body_rules as $needle => $label ) {
	if ( ! str_contains( $sheet, $needle ) ) {
		$lost[] = $label . ' (' . $needle . ')';
	}
}
echo 'apply_declarations_lost=' . count( $lost ) . "\n";
foreach ( $lost as $line ) {
	echo '  ' . $line . "\n";
}

/*
 * The wiring, which is a different question from the capability above and was
 * the half that was actually broken: the engine could read a v3 config all
 * along, and production never handed it one. The connector listed
 * `tailwind.config` as junk, `Source_Compiler` did not carry it, and
 * `Structure_Repository` did not pass it — three separate hops, each of which
 * looked fine on its own. So take the real path from a real archive.
 */
$doc = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip(
	array(
		'name'     => basename( $zip_path ),
		'tmp_name' => $zip_path,
		'error'    => 0,
		'size'     => (int) filesize( $zip_path ),
	)
);

$wiring = array();
if ( is_wp_error( $doc ) ) {
	$wiring[] = 'connector refused the fixture: ' . $doc->get_error_message();
	$compiled = array();
} else {
	$compiled = ( new DXAI_UI\Compiler\Source_Compiler() )->compile( $doc );
	if ( (string) ( $compiled['tailwind_config'] ?? '' ) === '' ) {
		$wiring[] = 'the compiler result carries no tailwind_config';
	}
	if ( count( $compiled['structures'] ?? array() ) === 0 ) {
		$wiring[] = 'the fixture compiled to no structures';
	}
}

/*
 * And the sheet those structures produce, built the way the repository builds
 * it. `container` is the one to watch: v4 has no `theme.container` at all, the
 * stock Lovable config centres it, pads it by 2rem and caps it at a single
 * breakpoint — and every section of a classic-template page sits in one.
 */
$page_sheet = '';
if ( $compiled !== array() ) {
	$markup = '';
	foreach ( $compiled['structures'] ?? array() as $structure ) {
		$markup .= (string) ( $structure['markup'] ?? $structure['gutenberg_markup'] ?? '' ) . "\n";
	}
	$page_sheet = ( new DXAI_UI\Compiler\Tailwind_Purger() )->compile(
		$markup,
		(string) ( $compiled['custom_css'] ?? '' ),
		0,
		'',
		(string) ( $compiled['design_css_raw'] ?? '' ),
		'',
		(string) ( $compiled['tailwind_config'] ?? '' )
	);

	$page_expect = array(
		'margin-inline:auto'        => 'container centring',
		'padding-inline:2rem'       => 'container gutter',
		'max-width:1240px'          => 'container cap from theme.container.screens',
		'hsl(var(--primary))'       => 'a token that only the config declares',
		'@keyframes accordion-down' => 'keyframes from the config',
		'#12211c'                   => 'a literal brand colour from the config',
	);
	foreach ( $page_expect as $needle => $label ) {
		if ( ! str_contains( $page_sheet, $needle ) ) {
			$wiring[] = $label . ' missing (' . $needle . ')';
		}
	}
}

echo 'wiring_broken=' . count( $wiring ) . ' page_sheet_bytes=' . strlen( $page_sheet ) . "\n";
foreach ( $wiring as $line ) {
	echo '  ' . $line . "\n";
}

/*
 * The form route, asserted on the things geometry cannot see.
 *
 * A form's label-to-control association is invisible to every pixel check: a
 * `<label>` with no `for` occupies exactly the same box as one with it. So it
 * is asserted directly — and so is the absence of a duplicate id, which is how
 * the first working version of this shipped (four controls all called
 * `-form-item`, which is worse than no id at all).
 */
$form_problems = array();
$contact       = '';
foreach ( (array) ( $compiled['pages'] ?? array() ) as $extra ) {
	if ( is_array( $extra ) && trim( (string) ( $extra['slug'] ?? '' ), '/' ) === 'contact' ) {
		$contact = (string) ( $extra['gutenberg_markup'] ?? '' );
		break;
	}
}

if ( $contact === '' ) {
	$form_problems[] = 'the contact route compiled to nothing';
} else {
	// Counts first: a form that renders no control cannot fail the rest.
	$controls = preg_match_all( '/<(?:input|textarea|select)\b/', $contact );
	$labels   = preg_match_all( '/<label\b/', $contact );
	if ( $controls < 4 ) {
		$form_problems[] = 'only ' . $controls . ' of 4 controls rendered';
	}
	if ( $labels < 4 ) {
		$form_problems[] = 'only ' . $labels . ' of 4 labels rendered';
	}

	// Every `for` must name an id that exists exactly once.
	preg_match_all( '/\bid="([^"]+)"/', $contact, $ids );
	$counts = array_count_values( $ids[1] );
	foreach ( $counts as $id => $times ) {
		if ( $times > 1 ) {
			$form_problems[] = 'id "' . $id . '" appears ' . $times . ' times';
		}
	}
	preg_match_all( '/\bfor="([^"]*)"/', $contact, $fors );
	foreach ( $fors[1] as $target ) {
		if ( $target === '' ) {
			$form_problems[] = 'a label has an empty for';
			continue;
		}
		if ( ! isset( $counts[ $target ] ) ) {
			$form_problems[] = 'for="' . $target . '" names no element on the page';
		}
	}
	foreach ( $fors[1] as $target ) {
		// And it must point at a control, not at any element that has an id.
		if ( $target !== '' && preg_match( '/<(?:input|textarea|select)\b[^>]*\bid="' . preg_quote( $target, '/' ) . '"/', $contact ) !== 1 ) {
			$form_problems[] = 'for="' . $target . '" does not name a control';
		}
	}

	// At rest the form has no errors, so it must carry no message element.
	if ( str_contains( $contact, 'text-destructive' ) ) {
		$form_problems[] = 'a validation message rendered on a form at rest';
	}
	// And an IDREF must never be empty.
	if ( preg_match( '/\b(?:aria-describedby|aria-labelledby|id|for)=""/', $contact ) === 1 ) {
		$form_problems[] = 'an empty id reference reached the markup';
	}
}

echo 'form_problems=' . count( $form_problems )
	. ' controls=' . ( $contact === '' ? 0 : preg_match_all( '/<(?:input|textarea|select)\b/', $contact ) )
	. ' labels=' . ( $contact === '' ? 0 : preg_match_all( '/<label\b/', $contact ) ) . "\n";
foreach ( $form_problems as $line ) {
	echo '  ' . $line . "\n";
}

$failed = count( $form_problems ) + count( $wiring ) + count( $unresolved ) + count( $wrong ) + count( $missing_frames )
	+ (int) $applies + (int) $directives + (int) $unexpanded + count( $lost );
echo ( $failed === 0 ? 'legacy-shape: ok' : 'legacy-shape: FAIL' ) . "\n";
exit( $failed === 0 ? 0 : 1 );
