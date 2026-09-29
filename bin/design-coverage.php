<?php
/**
 * Full-coverage check for a Lovable export: did all of it come across?
 *
 * Imports an archive the way the plugin does, then counts every dimension of
 * the design on both sides — keyframes and animation declarations, transitions,
 * design tokens, `@font-face` and hosted font stylesheets, inline SVG and the
 * shapes, gradients, clips and masks inside it, `.svg` files, lucide icons,
 * images, videos, the motion runtime's markers and state classes, the JSX
 * handlers that drive them, and the page's own behaviour script.
 *
 * It exists because every other check in this repo is blind to a whole class of
 * loss. A page can be pixel-identical in a screenshot at three widths, open
 * cleanly in the editor, pass every block-parity assertion — and never animate,
 * because one `@keyframes` block did not survive the purge. This is the number
 * for that.
 *
 * A row where the page holds fewer items than the design is a finding, and the
 * missing items are named. Rows where a lower number is legitimate — purged
 * rules, unused breakpoints, one handler needing several markers — are marked
 * informational and cannot fail. Rows that need the design's own source files
 * to count the source side say `unknown` when run without them; this script
 * always has them, because it does the import itself.
 *
 * usage: bin/wp-php.sh bin/design-coverage.php ["<zip>"...]        every corpus archive by default
 *        DXAI_COVERAGE_KEEP=1 …   leave the imported pages in place
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Connectors\Zip_Extractor;
use DXAI_UI\Connectors\Zip_Router;
use DXAI_UI\Structures\Structure_Repository;
use DXAI_UI\Verification\Design_Coverage;

$zips = array_slice( $argv, 1 );
if ( $zips === array() ) {
	$zips = glob( 'C:/Users/DevriX/Documents/Lovable/*.zip' ) ?: array();
}

/*
 * Import as an administrator, which is what a real import is.
 *
 * With no current user, wp_insert_post() runs the content through kses —
 * parse_blocks, filter_block_kses and serialize_block over the whole document —
 * because user 0 has no unfiltered_html capability. On a 170 KB block document
 * that asked for another 136 MB and died at a 1 GB limit, so this script could
 * not import what the plugin imports every day. An administrator skips that
 * pass, exactly as the wizard's own request does.
 */
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( $admin === array() ) {
	echo 'no administrator to import as' . PHP_EOL;
	exit( 1 );
}
wp_set_current_user( (int) $admin[0] );

$coverage = new Design_Coverage();
$totals   = array();
$failed   = 0;
$measured = 0;

foreach ( $zips as $zip_path ) {
	$zip_path = str_replace( '\\', '/', (string) $zip_path );
	$name     = basename( $zip_path );
	if ( ! is_readable( $zip_path ) ) {
		echo "MISSING {$name}" . PHP_EOL;
		++$failed;
		continue;
	}

	$doc = Zip_Router::import( array( 'tmp_name' => $zip_path, 'name' => $name ) );
	if ( is_wp_error( $doc ) ) {
		// A media-only archive is not a design export; see bin/admin-vs-cli.php.
		$web = 0;
		foreach ( Zip_Extractor::entries( $zip_path ) as $entry ) {
			if ( preg_match( '/\.(?:html?|css|scss|jsx?|tsx?|mjs|vue|svelte|astro)$/i', (string) $entry ) === 1 ) {
				++$web;
			}
		}
		if ( $web === 0 ) {
			echo "SKIP {$name}: not a design export" . PHP_EOL;
			continue;
		}
		echo "FAIL {$name}: import " . $doc->get_error_message() . PHP_EOL;
		++$failed;
		continue;
	}

	$compiler = new Source_Compiler();
	if ( ! $compiler->can_compile( $doc ) ) {
		echo "SKIP {$name}: not a source compile (kind={$doc->kind})" . PHP_EOL;
		continue;
	}

	$result = $compiler->compile( $doc );
	$saved  = ( new Structure_Repository() )->save( $result );
	if ( is_wp_error( $saved ) ) {
		echo "FAIL {$name}: save " . $saved->get_error_message() . PHP_EOL;
		++$failed;
		continue;
	}

	$page_id = (int) ( $saved['page_id'] ?? 0 );
	if ( $page_id === 0 ) {
		echo "FAIL {$name}: no page" . PHP_EOL;
		++$failed;
		continue;
	}

	/*
	 * The design's own sources, so the rows that can only be counted from the
	 * code someone wrote — lucide icons, JSX handlers — are counted rather than
	 * reported unknown. A Claude Design export has no components; its markup is
	 * the template, which the `sources` key carries instead.
	 */
	$payload = $doc->payload;
	$sources = array();
	foreach ( array( 'components', 'sources', 'styles' ) as $key ) {
		foreach ( is_array( $payload[ $key ] ?? null ) ? $payload[ $key ] : array() as $path => $body ) {
			if ( is_string( $body ) ) {
				$sources[ (string) $path ] = $body;
			}
		}
	}

	$report = $coverage->measure( $result, $page_id, $sources );
	update_post_meta( $page_id, Design_Coverage::META, $report );
	++$measured;

	$short = array();
	foreach ( $report['rows'] as $row ) {
		$totals[ $row['dimension'] ] ??= array( 'source' => 0, 'page' => 0, 'short' => 0 );
		$totals[ $row['dimension'] ]['source'] += (int) ( $row['source'] ?? 0 );
		$totals[ $row['dimension'] ]['page']   += (int) $row['page'];
		if ( $row['short'] ) {
			$totals[ $row['dimension'] ]['short'] += 1;
			$short[] = $row['dimension'] . ' ' . $row['page'] . '/' . $row['source']
				. ( $row['missing'] === array() ? '' : ' (' . implode( ', ', array_slice( $row['missing'], 0, 6 ) ) . ')' );
		}
	}

	printf(
		'%-34s page=%-6d score=%-4s %s' . PHP_EOL,
		$name,
		$page_id,
		$report['score'] === null ? 'n/a' : $report['score'] . '%',
		$short === array() ? 'all dimensions carried' : 'SHORT: ' . implode( ' | ', $short )
	);

	if ( $short !== array() ) {
		++$failed;
	}

	if ( getenv( 'DXAI_COVERAGE_VERBOSE' ) ) {
		foreach ( $report['rows'] as $row ) {
			printf(
				'    %-24s %6s -> %-6d %s%s' . PHP_EOL,
				$row['dimension'],
				$row['source'] === null ? '?' : (string) $row['source'],
				$row['page'],
				$row['informational'] ? '(informational) ' : '',
				$row['note']
			);
		}
	}
}

echo PHP_EOL . 'by dimension, across ' . $measured . ' design(s):' . PHP_EOL;
foreach ( $totals as $dimension => $sum ) {
	printf(
		'  %-24s design=%-7d page=%-7d %s' . PHP_EOL,
		$dimension,
		$sum['source'],
		$sum['page'],
		$sum['short'] === 0 ? 'ok' : $sum['short'] . ' design(s) short'
	);
}

echo PHP_EOL . 'design-coverage: ' . ( $failed === 0 ? 'every dimension carried on every design' : $failed . ' design(s) lost something' ) . PHP_EOL;
if ( $failed > 0 ) {
	exit( 1 );
}
