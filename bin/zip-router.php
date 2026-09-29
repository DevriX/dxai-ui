<?php
/**
 * Zip_Router agrees with the verification pack, on every corpus archive.
 *
 * The admin and the command line used to sniff archives separately, which is
 * how the same ZIP could compile two different ways depending on where it was
 * dropped. Both call Zip_Router now, so this is where its answer is pinned:
 * first against hand-written entry lists for the shapes that matter, then
 * against every real archive in Documents/Lovable, compared with the rule
 * bin/verify-import.cjs uses to choose an oracle. A disagreement there means a
 * design is measured one way and compiled another.
 *
 * usage: bin/wp-php.sh bin/zip-router.php [zip-dir]
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Connectors\Zip_Extractor;
use DXAI_UI\Connectors\Zip_Router;

$fails = 0;
$ran   = 0;

/**
 * @param array<int, string> $entries
 */
$check = function ( array $entries, string $want, string $label ) use ( &$fails, &$ran ): void {
	++$ran;
	$got = Zip_Router::kind( $entries );
	if ( $got !== $want ) {
		++$fails;
		echo "FAIL {$label}: want={$want} got={$got}" . PHP_EOL;
		return;
	}
	echo "ok   {$label} => {$got}" . PHP_EOL;
};

$check(
	array( 'site/src/pages/Index.tsx', 'site/src/App.tsx', 'site/tailwind.config.ts', 'site/package.json' ),
	Zip_Router::LOVABLE,
	'Lovable/Vite source'
);
$check(
	array( 'Ledger Close.dc.html', 'support.js' ),
	Zip_Router::DC,
	'Claude Design export'
);
$check(
	array( 'index.html', 'styles/main.css', 'app.js' ),
	Zip_Router::GENERATED,
	'static HTML export'
);
$check(
	array( 'app/src/App.vue', 'app/src/main.ts', 'app/index.html' ),
	Zip_Router::GENERATED,
	'Vue source (no TSX)'
);
// A built export carries the compiled bundle and no components: the source
// compiler has nothing to split, so it belongs to the HTML connector.
$check(
	array( 'dist/index.html', 'dist/assets/index-a1b2.js', 'dist/assets/Index-c3d4.tsx' ),
	Zip_Router::GENERATED,
	'dist-only export'
);
$check(
	array( 'node_modules/react-day-picker/src/Calendar.tsx', 'index.html', 'main.css' ),
	Zip_Router::GENERATED,
	'vendored TSX only'
);
// Whatever the pack measures the TSX way, the router compiles the TSX way.
$check(
	array( 'src/pages/Index.tsx', 'docs/Spec.dc.html' ),
	Zip_Router::LOVABLE,
	'both markers: React wins, as in verify-import.cjs'
);

/*
 * The real corpus. `kind()` has to answer for each archive, and for the two
 * oracle kinds it has to answer what bin/verify-import.cjs answers — that
 * rule, verbatim from its line 394, is a `.dc.html` present and no TSX/JSX.
 */
$dir  = $argv[1] ?? 'C:/Users/DevriX/Documents/Lovable';
$zips = glob( rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/*.zip' ) ?: array();
echo PHP_EOL . 'corpus=' . count( $zips ) . ' dir=' . $dir . PHP_EOL;

foreach ( $zips as $zip ) {
	++$ran;
	$name    = basename( (string) $zip );
	$entries = Zip_Extractor::entries( (string) $zip );
	if ( $entries === array() ) {
		++$fails;
		echo "FAIL {$name}: unreadable archive" . PHP_EOL;
		continue;
	}

	$kind = Zip_Router::kind( $entries );

	$pack_dc = false;
	$pack_ts = false;
	foreach ( $entries as $entry ) {
		if ( preg_match( '/\.dc\.html$/i', (string) $entry ) === 1 ) {
			$pack_dc = true;
		}
		if ( preg_match( '/\.(?:tsx|jsx)$/i', (string) $entry ) === 1 ) {
			$pack_ts = true;
		}
	}
	$pack = ( $pack_dc && ! $pack_ts ) ? Zip_Router::DC : ( $pack_ts ? Zip_Router::LOVABLE : Zip_Router::GENERATED );

	if ( $kind !== $pack ) {
		++$fails;
		echo "FAIL {$name}: router={$kind} pack={$pack}" . PHP_EOL;
		continue;
	}

	echo "ok   {$name} => {$kind}" . PHP_EOL;
}

echo PHP_EOL . 'zip-router: ' . ( $ran - $fails ) . '/' . $ran . ' pass' . PHP_EOL;
if ( $fails > 0 ) {
	exit( 1 );
}
