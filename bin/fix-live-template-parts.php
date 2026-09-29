<?php
/**
 * Fix corrupted dxai template-part slugs on live-menu pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? '';
$home_id = isset( $argv[2] ) ? (int) $argv[2] : 0;
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

if ( $home_id < 1 ) {
	exit( 1 );
}

$home = (string) get_post_field( 'post_content', $home_id );
preg_match( '/"slug":"(dxai-header-[^"]+)"/', $home, $h );
preg_match( '/"slug":"(dxai-footer-[^"]+)"/', $home, $f );
$header_slug = $h[1] ?? '';
$footer_slug = $f[1] ?? '';
echo "header_slug={$header_slug}\nfooter_slug={$footer_slug}\n";
if ( $header_slug === '' || $footer_slug === '' ) {
	fwrite( STDERR, "slugs_missing\n" );
	exit( 1 );
}

$theme = get_stylesheet();
$header_ref = sprintf(
	'<!-- wp:template-part %s /-->',
	wp_json_encode(
		array(
			'slug'  => $header_slug,
			'theme' => $theme,
			'area'  => 'header',
		),
		JSON_UNESCAPED_SLASHES
	)
);
$footer_ref = sprintf(
	'<!-- wp:template-part %s /-->',
	wp_json_encode(
		array(
			'slug'  => $footer_slug,
			'theme' => $theme,
			'area'  => 'footer',
		),
		JSON_UNESCAPED_SLASHES
	)
);

$children = get_posts(
	array(
		'post_type'      => 'page',
		'posts_per_page' => 50,
		'post_status'    => 'publish',
		'meta_key'       => '_dxai_ui_from_live_menu',
		'meta_value'     => '1',
	)
);

$fixed = 0;
foreach ( $children as $child ) {
	$content = (string) $child->post_content;
	// Strip any template-part blocks (broken or not).
	$content = preg_replace( '/<!-- wp:template-part\b[^>]*\/-->\s*/', '', $content ) ?? $content;
	// Ensure wrap group exists.
	if ( ! str_contains( $content, 'dxai-ui--' . $home_id ) ) {
		continue;
	}
	// Insert header after opening group div, footer before closing group.
	if ( preg_match( '/(<div class="wp-block-group alignfull dxai-ui dxai-ui--' . $home_id . '[^"]*">)/', $content, $m ) ) {
		$content = preg_replace(
			'/(<div class="wp-block-group alignfull dxai-ui dxai-ui--' . $home_id . '[^"]*">)/',
			'$1' . "\n" . $header_ref . "\n",
			$content,
			1
		) ?? $content;
	}
	if ( str_contains( $content, '<!-- /wp:group -->' ) ) {
		// Insert footer before the last closing group of the outer wrap.
		$pos = strrpos( $content, '<!-- /wp:group -->' );
		if ( $pos !== false ) {
			$content = substr( $content, 0, $pos ) . $footer_ref . "\n" . substr( $content, $pos );
		}
	}
	wp_update_post(
		array(
			'ID'           => $child->ID,
			'post_content' => wp_slash( $content ),
		)
	);
	++$fixed;
	echo 'fixed #' . $child->ID . ' ' . $child->post_title . PHP_EOL;
}
echo "fixed={$fixed}\n";
