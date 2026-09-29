<?php
/**
 * Inspect saved GTM pages.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<int, array<string, mixed>> $blocks
 * @param array<string, int>               $counts
 */
function dxai_count_blocks( array $blocks, array &$counts ): void {
	foreach ( $blocks as $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( $name !== '' ) {
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			dxai_count_blocks( $block['innerBlocks'], $counts );
		}
	}
}

$ids = array( 130, 343 );
$pages = get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => array( 'publish', 'draft', 'trash' ),
		'posts_per_page' => 20,
		'meta_key'       => '_dxai_ui_generated_page',
		'meta_value'     => '1',
	)
);
foreach ( $pages as $p ) {
	if ( $p instanceof WP_Post && ! in_array( $p->ID, $ids, true ) ) {
		$ids[] = $p->ID;
	}
}

foreach ( $ids as $id ) {
	$p = get_post( $id );
	if ( ! $p instanceof WP_Post ) {
		echo "id={$id} missing\n";
		continue;
	}
	$counts = array();
	dxai_count_blocks( parse_blocks( $p->post_content ), $counts );
	arsort( $counts );
	echo sprintf(
		"id=%d type=%s status=%s slug=%s title=%s content=%d template=%s canvas=%d\n",
		$id,
		$p->post_type,
		$p->post_status,
		$p->post_name,
		$p->post_title,
		strlen( $p->post_content ),
		(string) get_post_meta( $id, '_wp_page_template', true ),
		strlen( (string) get_post_meta( $id, '_dxai_ui_canvas_html', true ) )
	);
	echo 'has_ref=' . ( str_contains( $p->post_content, 'wp:block {"ref"' ) ? '1' : '0' ) . "\n";
	echo 'has_heading=' . ( ( $counts['core/heading'] ?? 0 ) > 0 ? '1' : '0' ) . "\n";
	echo 'has_paragraph=' . ( ( $counts['core/paragraph'] ?? 0 ) > 0 ? '1' : '0' ) . "\n";
	echo 'has_link=' . ( ( $counts['dxai-ui/link'] ?? 0 ) > 0 ? '1' : '0' ) . "\n";
	echo 'has_details=' . ( ( $counts['dxai-ui/details'] ?? 0 ) > 0 ? '1' : '0' ) . "\n";
	echo 'has_group=' . ( ( $counts['core/group'] ?? 0 ) > 0 ? '1' : '0' ) . "\n";
	echo 'html_blocks=' . (int) ( $counts['core/html'] ?? 0 ) . "\n";
	echo 'block_counts=' . wp_json_encode( $counts ) . "\n";
	echo 'permalink=' . get_permalink( $id ) . "\n";
	echo 'edit=' . get_edit_post_link( $id, 'raw' ) . "\n";
}

echo 'show_on_front=' . get_option( 'show_on_front' ) . "\n";
echo 'page_on_front=' . get_option( 'page_on_front' ) . "\n";
