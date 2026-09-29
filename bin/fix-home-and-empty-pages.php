<?php
/**
 * Restore Home CSS + empty live-menu pages to header/footer chrome only.
 *
 * Usage: php bin/fix-home-and-empty-pages.php <wp-root> [home-id]
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? '';
$home_id = isset( $argv[2] ) ? (int) $argv[2] : 0;
if ( $wp_root === '' ) {
	fwrite( STDERR, "usage: php bin/fix-home-and-empty-pages.php <wp-root> [home-id]\n" );
	exit( 1 );
}

require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

if ( $home_id < 1 ) {
	$homes = get_posts(
		array(
			'post_type'      => 'page',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_key'       => '_dxai_ui_generated_page',
			'meta_value'     => '1',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_dxai_ui_from_live_menu',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);
	$home_id = isset( $homes[0] ) ? (int) $homes[0]->ID : 0;
}

if ( $home_id < 1 ) {
	fwrite( STDERR, "home_missing\n" );
	exit( 1 );
}

$uploads = wp_upload_dir();
$pattern_url = trailingslashit( (string) $uploads['baseurl'] ) . 'dxai-ui/pattern-' . $home_id . '.css';
$pattern_path = trailingslashit( (string) $uploads['basedir'] ) . 'dxai-ui/pattern-' . $home_id . '.css';

if ( ! is_readable( $pattern_path ) ) {
	fwrite( STDERR, "pattern_css_missing={$pattern_path}\n" );
	exit( 1 );
}

// 1) Restore Home to its original compiled sheet (never the live site sheet).
update_post_meta( $home_id, '_dxai_ui_css_url', DXAI_UI\Support\Upload_Paths::for_storage( $pattern_url ) );
echo 'home_css_restored=' . $pattern_url . PHP_EOL;

$home_content = (string) get_post_field( 'post_content', $home_id );
preg_match( '/"slug":"(dxai-header-[^"]+)"/', $home_content, $h );
preg_match( '/"slug":"(dxai-footer-[^"]+)"/', $home_content, $f );
$header_slug = $h[1] ?? '';
$footer_slug = $f[1] ?? '';
if ( $header_slug === '' || $footer_slug === '' ) {
	fwrite( STDERR, "home_template_parts_missing\n" );
	exit( 1 );
}

$theme = get_stylesheet();
$header_json = wp_json_encode(
	array(
		'slug'  => $header_slug,
		'theme' => $theme,
		'area'  => 'header',
	),
	JSON_UNESCAPED_SLASHES
);
$footer_json = wp_json_encode(
	array(
		'slug'  => $footer_slug,
		'theme' => $theme,
		'area'  => 'footer',
	),
	JSON_UNESCAPED_SLASHES
);

$header_ref = sprintf( '<!-- wp:template-part %s /-->', is_string( $header_json ) ? $header_json : '{}' );
$footer_ref = sprintf( '<!-- wp:template-part %s /-->', is_string( $footer_json ) ? $footer_json : '{}' );

$home_wrap = (string) get_post_meta( $home_id, '_dxai_ui_wrapper_class', true );
$class     = trim( 'dxai-ui dxai-ui--' . $home_id . ' ' . $home_wrap );
$encoded   = wp_json_encode(
	array(
		'align'     => 'full',
		'className' => $class,
	),
	JSON_UNESCAPED_SLASHES
);

$empty_body = sprintf(
	"<!-- wp:group %s -->\n" .
	"<div class=\"wp-block-group alignfull %s\">\n" .
	"%s\n" .
	"%s\n" .
	"</div>\n" .
	'<!-- /wp:group -->',
	is_string( $encoded ) ? $encoded : '{"align":"full"}',
	esc_attr( $class ),
	$header_ref,
	$footer_ref
);

// Rebuild site CSS for children from clean pattern sheet.
$site = DXAI_UI\Structures\Live_Content_Shell::persist_site_css( $home_id, $pattern_url );
$site_url = is_wp_error( $site ) ? $pattern_url : $site;
echo 'site_css=' . $site_url . PHP_EOL;

$children = get_posts(
	array(
		'post_type'      => 'page',
		'posts_per_page' => 50,
		'post_status'    => 'any',
		'meta_key'       => '_dxai_ui_from_live_menu',
		'meta_value'     => '1',
	)
);

$emptied = 0;
foreach ( $children as $child ) {
	wp_update_post(
		array(
			'ID'           => $child->ID,
			'post_content' => wp_slash( $empty_body ),
		)
	);
	update_post_meta( $child->ID, '_dxai_ui_css_url', DXAI_UI\Support\Upload_Paths::for_storage( is_string( $site_url ) ? $site_url : $pattern_url ) );
	update_post_meta( $child->ID, '_dxai_ui_wrapper_class', $home_wrap );
	update_post_meta( $child->ID, '_wp_page_template', DXAI_UI\Theme\Blank_Template::SLUG );
	if ( get_post_meta( $home_id, '_dxai_ui_static_html', true ) ) {
		update_post_meta( $child->ID, '_dxai_ui_static_html', '1' );
	}
	$fonts = get_post_meta( $home_id, '_dxai_ui_font_urls', true );
	if ( is_array( $fonts ) ) {
		update_post_meta( $child->ID, '_dxai_ui_font_urls', $fonts );
	}
	$js = (string) get_post_meta( $home_id, '_dxai_ui_js_url', true );
	if ( $js !== '' ) {
		update_post_meta( $child->ID, '_dxai_ui_js_url', DXAI_UI\Support\Upload_Paths::for_storage( $js ) );
	}
	++$emptied;
	echo 'emptied #' . $child->ID . ' ' . $child->post_title . PHP_EOL;
}

echo "home_id={$home_id}\n";
echo "emptied={$emptied}\n";
echo "fix=ok\n";
