<?php
/**
 * Dump GTM page markup sample.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$p = get_post( 343 );
if ( ! $p instanceof WP_Post ) {
	echo "missing\n";
	return;
}
$c = $p->post_content;
echo 'nav_blocks=' . substr_count( $c, 'wp:navigation' ) . PHP_EOL;
echo 'header=' . ( str_contains( $c, 'rv-header' ) ? '1' : '0' ) . PHP_EOL;
echo 'hero=' . ( str_contains( $c, 'rv-hero-full' ) ? '1' : '0' ) . PHP_EOL;
echo 'faq=' . ( str_contains( $c, 'wp:dxai-ui/details' ) ? '1' : '0' ) . PHP_EOL;
echo 'cta=' . ( str_contains( $c, 'rv-cta' ) ? '1' : '0' ) . PHP_EOL;
echo 'tag_header=' . ( str_contains( $c, '"tagName":"header"' ) ? '1' : '0' ) . PHP_EOL;
echo 'tag_section=' . substr_count( $c, '"tagName":"section"' ) . PHP_EOL;
if ( preg_match_all( '/<!-- wp:navigation[^>]*-->/', $c, $m ) ) {
	foreach ( $m[0] as $row ) {
		echo $row . PHP_EOL;
	}
}
echo "----- first 2000 -----\n";
echo substr( $c, 0, 2000 ) . PHP_EOL;
