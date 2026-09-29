<?php
/**
 * What did the compiler fail to evaluate?
 *
 * Jsx_Compiler has reported this since it was written — note_unevaluated()
 * feeds unevaluated() and the static all_unevaluated(). Nothing read either.
 * Verified across the whole tree: the only matches for those names outside
 * Jsx_Compiler itself were comments. So every "unevaluated: 0" anyone has
 * quoted, this session included, came from a probe calling it directly; it has
 * never protected an import.
 *
 * That matters more than it sounds. The geometry check cannot see a JSX-stage
 * loss at all — bin/design-oracle.php builds its design side with this same
 * compiler, so the loss lands on both sides and reports 0px. The report is the
 * ONLY mechanism that can see the class of defect, and it was write-only.
 *
 * This reads it, per design, from the sources a project actually ships.
 * compile_file() is used rather than Source_Compiler::compile() on purpose:
 * the latter sideloads media as a side effect, and a reporting tool should not
 * leave 400 attachments behind.
 *
 * usage: bin/wp-php.sh bin/unevaluated-report.php [--max N] [project-src-dir]...
 *        with no directories, every .verify/react-oracle/<slug>/src is read
 * exit:  0 at or below --max reports (default: report only, never fail),
 *        1 above it
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

$repo = dirname( __DIR__ );
$max  = null;
$dirs = array();
$zips = array();

$args = array_slice( $argv, 1 );
for ( $i = 0; $i < count( $args ); $i++ ) {
	if ( $args[ $i ] === '--max' && isset( $args[ $i + 1 ] ) ) {
		$max = (int) $args[ ++$i ];
		continue;
	}
	if ( $args[ $i ] === '--zip' && isset( $args[ $i + 1 ] ) ) {
		$zips[] = (string) $args[ ++$i ];
		continue;
	}
	$dirs[] = (string) $args[ $i ];
}

/*
 * `--zip` runs the real import path, which is the only way to cover a design
 * whose sources have not been extracted — and four of the seven ZIPs report
 * nothing while three report 33 occurrences between them, so covering only the
 * extracted ones understates it badly.
 *
 * It costs media: Source_Compiler::compile() sideloads assets as a side
 * effect, so a run leaves attachments behind. Purge after
 * (bin/purge-and-import-real-zips.php) or accept the duplicates.
 */
if ( $zips !== array() ) {
	$kinds = array();
	$total = 0;
	foreach ( $zips as $zip ) {
		if ( ! is_readable( $zip ) ) {
			printf( "%-30s NOT READABLE\n", basename( $zip ) );
			continue;
		}
		$source = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip(
			array( 'name' => basename( $zip ), 'tmp_name' => $zip, 'error' => 0, 'size' => filesize( $zip ) )
		);
		if ( is_wp_error( $source ) ) {
			printf( "%-30s import failed: %s\n", basename( $zip ), $source->get_error_message() );
			++$total;
			continue;
		}
		$result = ( new DXAI_UI\Compiler\Source_Compiler() )->compile( $source );
		$rows   = is_array( $result['unevaluated'] ?? null ) ? $result['unevaluated'] : array();
		$n      = 0;
		foreach ( $rows as $row ) {
			$n                    += (int) $row['count'];
			$kinds[ $row['kind'] ] = ( $kinds[ $row['kind'] ] ?? 0 ) + (int) $row['count'];
		}
		$total += $n;
		printf( "%-30s %d kind(s), %d occurrence(s)\n", substr( basename( $zip ), 0, 29 ), count( $rows ), $n );
		foreach ( $rows as $row ) {
			printf( "    %-26s x%-3d %s\n", $row['kind'], $row['count'], substr( $row['source'], 0, 72 ) );
		}
	}

	print "\n--- by kind ---\n";
	arsort( $kinds );
	foreach ( $kinds as $kind => $count ) {
		printf( "  %-28s %d\n", $kind, $count );
	}
	printf( "\n%d unevaluated expression(s) total\n", $total );

	if ( $max === null ) {
		print "no --max given: reporting only\n";
		exit( 0 );
	}
	printf( "budget %d%s\n", $max, $total > $max ? ' — EXCEEDED' : '' );
	exit( $total > $max ? 1 : 0 );
}
if ( $dirs === array() ) {
	foreach ( (array) glob( $repo . '/.verify/react-oracle/*/src' ) as $candidate ) {
		if ( is_dir( (string) $candidate ) ) {
			$dirs[] = (string) $candidate;
		}
	}
}
if ( $dirs === array() ) {
	print "no project sources found — run bin/react-oracle.sh to extract one\n";
	exit( 0 );
}

$total = 0;
$kinds = array();

foreach ( $dirs as $root ) {
	$root = rtrim( $root, '/\\' );
	if ( ! is_dir( $root ) ) {
		continue;
	}

	$files = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $f ) {
		if ( ! $f instanceof SplFileInfo || ! $f->isFile() || preg_match( '/\.(tsx|jsx|ts|js)$/', $f->getFilename() ) !== 1 ) {
			continue;
		}
		$files[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( dirname( $root ) ) + 1 ) ) ] = (string) file_get_contents( $f->getPathname() );
	}

	// Every route the project ships, not just the first: a defect in a second
	// route is still a defect on an imported page.
	$routes = array();
	foreach ( $files as $path => $code ) {
		if ( preg_match( '#/(routes|pages)/[^/]+\.(tsx|jsx)$#', $path ) === 1 && ! str_contains( $path, '__root' ) ) {
			$routes[ $path ] = $code;
		}
	}
	if ( $routes === array() ) {
		continue;
	}

	$slug = basename( dirname( $root ) );
	foreach ( $routes as $path => $code ) {
		$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
		try {
			$compiler->compile_file( $code, array(), $files );
		} catch ( Throwable $e ) {
			printf( "%-24s %-34s threw: %s\n", $slug, basename( $path ), $e->getMessage() );
			++$total;
			continue;
		}
		$rows = $compiler->unevaluated();
		printf( "%-24s %-34s %d kind(s)\n", $slug, basename( $path ), count( $rows ) );
		foreach ( $rows as $row ) {
			$total += (int) $row['count'];
			$kinds[ $row['kind'] ] = ( $kinds[ $row['kind'] ] ?? 0 ) + (int) $row['count'];
			printf( "    %-22s x%-4d %s\n", $row['kind'], $row['count'], substr( $row['source'], 0, 84 ) );
		}
	}
}

print "\n--- by kind ---\n";
if ( $kinds === array() ) {
	print "  (nothing reported)\n";
}
arsort( $kinds );
foreach ( $kinds as $kind => $count ) {
	printf( "  %-24s %d\n", $kind, $count );
}
printf( "\n%d unevaluated expression(s) total\n", $total );

if ( $max === null ) {
	print "no --max given: reporting only\n";
	exit( 0 );
}
printf( "budget %d\n", $max );
exit( $total > $max ? 1 : 0 );
