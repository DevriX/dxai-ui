<?php
/**
 * The pages made for a design (Library › Pages in the team's style), measured against its Home — the data half.
 *
 * The rules are Team_Quality's (docs/PLAN-TEAM-PAGES.md, section 5). This half answers G1 (the header and footer are the
 * Home's), G7 (valid blocks), G8 (the pages are not copies) and G9 (nothing foreign) from the database and writes what
 * the browser half needs. The browser half (bin/team-quality.cjs) adds G2 colours, G3 fonts, G4 the frame, G5 reused
 * sections, G6 mobile, and prints the whole table. G10 (speed) is not measured yet.
 *
 *   bash bin/wp-php.sh bin/verify-team-quality.php [<home-id>] [out=<json-file>] [allow=G8,G5]
 *   node bin/team-quality.cjs <json-file> [allow=G8,G5]
 *
 * Without an id it measures every design on the site that has pages made for it (the verification pack runs it so) and
 * skips (exit 77) when there is none; the same gates on a Home made for the purpose are checked by verify-team-pages.
 * `allow` names gates that may fail without failing the run (a phase that has not reached them yet). Reads only; it
 * writes the JSON file (one Home) and nothing else. Exit code 1 when a gate that is not allowed fails, 2 for a wrong id.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Team_Quality;
use DXAI_UI\Structures\Design_Attach;

$home  = 0;
$out   = '';
$allow = array();
foreach ( $args as $a ) {
	if ( ctype_digit( (string) $a ) ) {
		$home = (int) $a;
	} elseif ( str_starts_with( (string) $a, 'out=' ) ) {
		$out = substr( (string) $a, 4 );
	} elseif ( str_starts_with( (string) $a, 'allow=' ) ) {
		$allow = array_values( array_filter( array_map( 'trim', explode( ',', substr( (string) $a, 6 ) ) ) ) );
	}
}
if ( $home > 0 && ! Design_Attach::is_design( $home ) ) {
	echo "usage: verify-team-quality.php [<home-id>] [out=<json>] [allow=G8,G5]  (the id of an imported design's Home)\n";
	exit( 2 );
}

// The Homes to measure, each with what was measured: the one named, else every design that has pages made for it.
$measured = array();
if ( $home > 0 ) {
	$measured[ $home ] = Team_Quality::measure( $home );
} else {
	foreach ( Design_Attach::designs() as $design ) {
		$m = Team_Quality::measure( (int) $design->ID );
		if ( $m['pages'] !== array() ) {
			$measured[ (int) $design->ID ] = $m;
		}
	}
	if ( $measured === array() ) {
		echo "SKIP: no design on this site has pages made for it (the gates on a Home made for the purpose are in verify-team-pages)\n";
		exit( 77 );
	}
}

$failed = array();
$pass   = 0;
foreach ( $measured as $id => $m ) {
	printf( "\nHome %d \"%s\": %d sections, %d pages made for it\n", $id, $m['home']['title'], count( $m['home']['sections'] ), count( $m['pages'] ) );
	$titles = array_column( $m['pages'], 'title', 'id' );
	foreach ( Team_Quality::GATES as $g => $name ) {
		$bad = $m['gates'][ $g ] ?? array();
		$ok  = $bad === array();
		printf( "  %s  %s — %s (%d of %d pages)\n", $g, $name, $ok ? 'ok' : ( in_array( $g, $allow, true ) ? 'FAIL (allowed)' : 'FAIL' ), count( $m['pages'] ) - count( $bad ), count( $m['pages'] ) );
		foreach ( $bad as $page => $why ) {
			printf( "      #%d %s: %s\n", $page, mb_substr( (string) $titles[ $page ], 0, 40 ), implode( '; ', $why ) );
		}
		if ( $ok || in_array( $g, $allow, true ) ) {
			++$pass;
		} else {
			$failed[] = "$id:$g";
		}
	}
}
if ( $out !== '' && count( $measured ) === 1 ) {
	$m          = reset( $measured );
	$m['site']  = untrailingslashit( home_url() );
	$m['allow'] = $allow;
	file_put_contents( $out, wp_json_encode( $m, JSON_UNESCAPED_SLASHES ) );
	echo "\nwrote $out\n";
}
echo "\n$pass passed, " . count( $failed ) . " failed\n";
exit( $failed === array() ? 0 : 1 );
