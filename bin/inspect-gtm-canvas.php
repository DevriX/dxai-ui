<?php
/**
 * Print canvas DOM checks for the GTM page.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$id     = 343;
$canvas = (string) get_post_meta( $id, '_dxai_ui_canvas_html', true );
$open   = substr( $canvas, 0, 500 );
echo "open=\n{$open}\n----\n";
echo 'logo_src=';
if ( preg_match( '/<img[^>]+alt="DevriX"[^>]*>/', $canvas, $m ) ) {
	echo $m[0] . "\n";
} else {
	echo "missing\n";
}
echo 'section_count=' . preg_match_all( '/<section\b/', $canvas ) . "\n";
echo 'header_count=' . preg_match_all( '/<header\b/', $canvas ) . "\n";
echo 'footer_count=' . preg_match_all( '/<footer\b/', $canvas ) . "\n";
echo 'sticky=' . ( str_contains( $canvas, 'rv-stickycta' ) ? '1' : '0' ) . "\n";
echo 'wp_group=' . ( str_contains( $canvas, 'wp-block-group' ) ? '1' : '0' ) . "\n";
echo 'font_urls=' . wp_json_encode( get_post_meta( $id, '_dxai_ui_font_urls', true ) ) . "\n";
