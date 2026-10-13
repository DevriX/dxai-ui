<?php
/**
 * Builds the team's layouts in data/block-library from the pages of the team's live sites and the team's own exports: the arrangement
 * of their blocks, and the words of theirs that are every page's (Pages\Layout_Builder).
 *
 *   wp eval-file bin/build-layouts.php --user=1 <pages dir> [<export.xml> …] [dry] [only=<role>] [max=<n per role>]
 *
 * <pages dir> holds one JSON file per published page of the team's sites (site, host, id, title, slug, content), as
 * scratchpad/tpl/sql_pages.cjs writes them from a database dump; an export is a WordPress export (WXR) of the team's own showcase page.
 * This is a developer tool: the plugin ships what it writes (data/block-library/blocks/*.html and the entries in library.json), not the
 * pages it reads.
 *
 * @package DXAI_UI
 */

// (No strict_types declaration: WP-CLI's eval-file evals the file, where it may not come first.)
use DXAI_UI\Pages\Layout_Builder;

$dry     = in_array( 'dry', $args, true );
$only    = '';
$max     = 8;
$dir     = '';
$exports = array();
foreach ( $args as $a ) {
	if ( ! is_string( $a ) ) {
		continue;
	}
	if ( str_starts_with( $a, 'only=' ) ) {
		$only = substr( $a, 5 );
	} elseif ( str_starts_with( $a, 'max=' ) ) {
		$max = max( 1, (int) substr( $a, 4 ) );
	} elseif ( $a !== 'dry' && is_dir( $a ) ) {
		$dir = rtrim( $a, '/\\' );
	} elseif ( preg_match( '/\.xml$/i', $a ) === 1 && is_readable( $a ) ) {
		$exports[] = $a;
	}
}
if ( $dir === '' && $exports === array() ) {
	fwrite( STDERR, "usage: wp eval-file bin/build-layouts.php --user=1 <pages dir> [<export.xml> …] [dry] [only=<role>] [max=<n>]\n" );
	exit( 2 );
}
// The team's sites in the dump (the others are the plugin's own test sites).
$team  = array( '3', '4', '7', '8', '9', '11', '12', '13', '14', '15', '16' );
$pages = array();
foreach ( $dir !== '' ? glob( $dir . '/*.json' ) : array() as $file ) {
	$p = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( is_array( $p ) ) {
		$pages[] = $p;
	}
}
foreach ( $exports as $file ) {
	foreach ( Layout_Builder::read_wxr( $file ) as $p ) {
		$pages[] = $p;
		$team[]  = (string) $p['site'];
	}
}
$result = Layout_Builder::build(
	$pages,
	array(
		'out_dir' => dirname( __DIR__ ) . '/data/block-library',
		'team'    => array_values( array_unique( $team ) ),
		'dry'     => $dry,
		'only'    => $only,
		'max'     => $max,
	)
);
echo implode( "\n", $result['lines'] ), "\n";
exit( $result['errors'] !== array() && $result['written'] ? 1 : 0 );
