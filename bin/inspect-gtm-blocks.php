<?php
/**
 * Inspect GTM native-block conversion quality.
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
echo 'slash_amp=' . ( str_contains( $c, '\u0026' ) ? '1' : '0' ) . "\n";
echo 'broken_amp=' . ( str_contains( $c, 'u0026' ) && ! str_contains( $c, '\u0026' ) ? '1' : '0' ) . "\n";
echo 'hasInner=' . substr_count( $c, '"hasInner":true' ) . "\n";
echo 'svc_row=' . substr_count( $c, 'rv-svc-row' ) . "\n";
echo 'pricing=' . ( str_contains( $c, 'Pricing' ) ? '1' : '0' ) . "\n";

$idx = strpos( $c, 'Pricing' );
if ( $idx !== false ) {
	echo "----- pricing context -----\n";
	echo substr( $c, max( 0, $idx - 180 ), 420 ) . "\n";
}

$idx = strpos( $c, 'rv-svc-row' );
if ( $idx !== false ) {
	echo "----- svc-row context -----\n";
	echo substr( $c, max( 0, $idx - 80 ), 900 ) . "\n";
}

/**
 * @param array<int, array<string, mixed>> $blocks
 * @param list<string>                     $html
 */
function dxai_collect_html( array $blocks, array &$html ): void {
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? '' ) === 'core/html' ) {
			$sample = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			$tag    = '';
			if ( preg_match( '/<([a-z0-9]+)/i', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
				$tag = strtolower( $m[1] );
			}
			$class = '';
			if ( preg_match( '/class="([^"]*)"/', (string) ( $block['innerHTML'] ?? '' ), $m ) ) {
				$class = $m[1];
			}
			$html[] = $tag . ' | ' . substr( $class, 0, 80 ) . ' | ' . substr( $sample, 0, 60 );
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			dxai_collect_html( $block['innerBlocks'], $html );
		}
	}
}

$html = array();
dxai_collect_html( parse_blocks( $p->post_content ), $html );
echo "----- html blocks (" . count( $html ) . ") -----\n";
foreach ( $html as $row ) {
	echo $row . "\n";
}
