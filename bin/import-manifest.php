<?php
/**
 * What the last import produced, as JSON — for bin/verify-import.cjs.
 *
 * The verification chain needs page ids, their permalinks and the template
 * parts they reference. Reading them from the site beats hardcoding slugs,
 * which drift with every design title.
 *
 * Run: wp eval-file bin/import-manifest.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pages = array();
foreach (
	get_posts(
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
	) as $page
) {
	$pages[] = array(
		'id'     => (int) $page->ID,
		'title'  => (string) $page->post_title,
		'url'    => (string) get_permalink( $page->ID ),
		// A full URL whichever form the location is stored in — new imports
		// store it relative to uploads (see DXAI_UI\Support\Upload_Paths).
		'css'    => DXAI_UI\Support\Upload_Paths::for_meta( (int) $page->ID, '_dxai_ui_css_url' )['url'],
		// The upload this page came from, recorded at import. Without it the
		// verifier cannot tell which design to measure against.
		'source' => (string) get_post_meta( $page->ID, '_dxai_ui_source_zip', true ),
		// Which route of that upload, for a design that has more than one.
		'route'  => (string) get_post_meta( $page->ID, '_dxai_ui_source_route', true ),
	);
}

$parts = array();
foreach (
	get_posts(
		array(
			'post_type'      => 'wp_template_part',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		)
	) as $part
) {
	$terms = get_the_terms( $part->ID, 'wp_theme' );
	$theme = ( $terms && ! is_wp_error( $terms ) ) ? (string) $terms[0]->name : (string) get_stylesheet();
	$parts[] = $theme . '//' . (string) $part->post_name;
}

echo wp_json_encode(
	array(
		'pages' => $pages,
		'parts' => $parts,
	)
) . PHP_EOL;
