<?php
/**
 * Run the post-import audit over every generated page.
 *
 * The same Import_Audit the admin runs when an import finishes, so the CLI and
 * the panel cannot disagree about whether a conversion is sound.
 *
 * Run: wp eval-file bin/verify-audit.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$audit = new DXAI_UI\Verification\Import_Audit();
$pages = get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'meta_query'     => array(
			array(
				'key'     => '_dxai_ui_generated_page',
				'compare' => 'EXISTS',
			),
		),
	)
);

if ( $pages === array() ) {
	echo "audit_pages=0\n";
	return;
}

$failed = 0;
foreach ( $pages as $page ) {
	$result = $audit->page( (int) $page->ID );
	if ( ! empty( $result['ok'] ) ) {
		printf( "  ok   %s\n", substr( (string) $page->post_title, 0, 48 ) );
		continue;
	}
	++$failed;
	printf( "  FAIL %s\n", substr( (string) $page->post_title, 0, 48 ) );
	foreach ( $result['findings'] as $finding ) {
		printf( "         %-22s %s\n", (string) $finding['check'], (string) $finding['detail'] );
	}
}

printf( "audit_pages=%d audit_failed=%d\n", count( $pages ), $failed );
if ( $failed > 0 ) {
	echo "FAIL\n";
}
