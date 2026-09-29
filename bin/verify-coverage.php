<?php
/**
 * Did every dimension of every imported design come across?
 *
 * The read-only half of bin/design-coverage.php: that one imports archives and
 * measures them, this one measures the pages an import already wrote, so it
 * costs nothing to run inside the verification pack.
 *
 * Each conversion record holds the compile result the page was written from, so
 * both sides of every dimension are available after the fact — the design's own
 * CSS and rendered HTML on one side, the page's markup, stylesheet and script
 * on the other. Keyframes, animation declarations, design tokens, `@font-face`
 * and hosted font stylesheets, inline SVG with the shapes and gradients inside
 * it, `.svg` files, icons, images, videos, the motion runtime's markers and
 * state classes, and the behaviour script.
 *
 * Fails when a page holds fewer items than its design on a dimension where
 * every item must appear. Rows a purge legitimately shrinks are informational
 * and cannot fail; rows that need the design's sources report unknown unless
 * the compile result carried the counts.
 *
 * Run: wp eval-file bin/verify-coverage.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DXAI_UI\Content\Content_Types;
use DXAI_UI\Verification\Design_Coverage;

/*
 * The conversion records, by post type rather than by a title search:
 * `post_type => 'any'` leaves out a type registered with
 * `exclude_from_search`, which this one is — so the search matched nothing and
 * the suite reported "ok" over zero pages.
 */
$records = get_posts(
	array(
		'post_type'      => Content_Types::CONVERSION,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$coverage = new Design_Coverage();
$rows     = array();
$short    = array();
$measured = 0;
$unknown  = 0;

foreach ( $records as $record ) {
	$json = json_decode( (string) get_post_field( 'post_content', (int) $record ), true );
	if ( ! is_array( $json ) ) {
		continue;
	}
	$result  = is_array( $json['result'] ?? null ) ? $json['result'] : null;
	$created = is_array( $json['created'] ?? null ) ? $json['created'] : array();
	$page_id = (int) ( $created['page_id'] ?? 0 );
	if ( $result === null || $page_id === 0 || get_post_status( $page_id ) === false ) {
		continue;
	}

	$report = $coverage->measure( $result, $page_id );
	update_post_meta( $page_id, Design_Coverage::META, $report );
	++$measured;
	$unknown += (int) $report['unknown'];

	foreach ( $report['rows'] as $row ) {
		$name = (string) $row['dimension'];
		$rows[ $name ] ??= array( 'design' => 0, 'page' => 0, 'short' => 0 );
		$rows[ $name ]['design'] += (int) ( $row['source'] ?? 0 );
		$rows[ $name ]['page']   += (int) $row['page'];
		if ( ! $row['short'] ) {
			continue;
		}
		$rows[ $name ]['short'] += 1;
		$short[] = sprintf(
			'#%d %s: %s %d/%d%s',
			$page_id,
			substr( (string) get_post_field( 'post_title', $page_id ), 0, 28 ),
			$name,
			(int) $row['page'],
			(int) $row['source'],
			$row['missing'] === array() ? '' : ' (' . implode( ', ', array_slice( $row['missing'], 0, 6 ) ) . ')'
		);
	}
}

foreach ( $short as $line ) {
	echo 'SHORT ' . $line . PHP_EOL;
}

ksort( $rows );
foreach ( $rows as $name => $sum ) {
	printf(
		'%-24s design=%-7d page=%-7d %s' . PHP_EOL,
		$name,
		$sum['design'],
		$sum['page'],
		$sum['short'] === 0 ? 'ok' : $sum['short'] . ' page(s) short'
	);
}

printf( 'pages=%d unknown_rows=%d short_rows=%d' . PHP_EOL, $measured, $unknown, count( $short ) );
echo 'verify-coverage: ' . ( $short === array() ? 'ok' : 'FAIL' ) . PHP_EOL;
