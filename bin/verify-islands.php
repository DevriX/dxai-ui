<?php
/**
 * How much of every converted page is raw HTML nobody can edit.
 *
 * A `dxai-ui/html` block is the converter's fallback: markup it could not turn
 * into a block, stored verbatim. The page renders exactly right — that is the
 * point of the fallback — so neither the geometry oracle nor block validity can
 * see it, and it cost nothing measurable until the goal became editable
 * blocks. Then it turned out to be 932 of 5396 blocks across 23 pages, 17%,
 * and nobody had a number for it.
 *
 * This suite is that number. It reports on every run, and fails only when
 * `DXAI_ISLAND_BUDGET` names a ceiling and the count is above it — a suite
 * that fails for a known reason stops being read, and the count is not zero
 * yet. Set the ceiling once it is, and it only goes down from there.
 *
 * Run: wp eval-file bin/verify-islands.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ceiling = getenv( 'DXAI_ISLAND_BUDGET' );
$budget  = $ceiling === false || $ceiling === '' ? null : (int) $ceiling;

$pages = get_posts(
	array(
		'post_type'      => array( 'page', 'wp_template_part' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$rows    = array();
$islands = 0;
$blocks  = 0;

foreach ( $pages as $id ) {
	$content = (string) get_post_field( 'post_content', (int) $id );
	if ( ! str_contains( $content, '<!-- wp:dxai-ui/' ) && ! str_contains( $content, '<!-- wp:group' ) ) {
		// Not a converted page: a site's own pages must not move the number.
		continue;
	}
	/*
	 * Opening delimiters only. A block comment pair matches the same name
	 * twice, and counting both reported every figure at double.
	 */
	$mine  = preg_match_all( '/<!-- wp:dxai-ui\/html\b/', $content );
	$total = preg_match_all( '/<!-- wp:[a-z0-9-]+\/?[a-z0-9-]*/', $content );

	$islands += (int) $mine;
	$blocks  += (int) $total;
	if ( (int) $mine > 0 ) {
		$rows[] = array(
			'id'      => (int) $id,
			'islands' => (int) $mine,
			'blocks'  => (int) $total,
			'title'   => substr( (string) get_the_title( (int) $id ), 0, 40 ),
		);
	}
}

usort(
	$rows,
	static function ( array $a, array $b ): int {
		return $b['islands'] <=> $a['islands'];
	}
);

foreach ( array_slice( $rows, 0, 10 ) as $row ) {
	printf( "  %4d islands / %4d blocks  #%d %s\n", $row['islands'], $row['blocks'], $row['id'], $row['title'] );
}

$share = $blocks > 0 ? round( $islands * 100 / $blocks, 1 ) : 0.0;
printf(
	"islands=%d blocks=%d share=%s%% pages_with_islands=%d budget=%s\n",
	$islands,
	$blocks,
	$share,
	count( $rows ),
	null === $budget ? 'none' : (string) $budget
);

if ( null !== $budget && $islands > $budget ) {
	printf( "FAIL: %d raw-HTML island(s) over the budget of %d\n", $islands - $budget, $budget );
	exit( 1 );
}

echo "verify-islands: ok\n";
