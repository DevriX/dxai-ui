<?php
/**
 * Rebuild live-menu pages with DeepSeek restyle + refreshed site CSS.
 *
 * Usage: php bin/re-restyle-live-pages.php <wp-root> [home-page-id]
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? '';
$home_id = isset( $argv[2] ) ? (int) $argv[2] : 0;
if ( $wp_root === '' ) {
	fwrite( STDERR, "usage: php bin/re-restyle-live-pages.php <wp-root> [home-id]\n" );
	exit( 1 );
}

require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

if ( function_exists( 'set_time_limit' ) ) {
	set_time_limit( 0 );
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

$children = get_posts(
	array(
		'post_type'      => 'page',
		'posts_per_page' => 40,
		'post_status'    => 'publish',
		'meta_key'       => '_dxai_ui_from_live_menu',
		'meta_value'     => '1',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

echo 'home_id=' . $home_id . PHP_EOL;
echo 'children=' . count( $children ) . PHP_EOL;

$home_css = (string) get_post_meta( $home_id, '_dxai_ui_css_url', true );
// Prefer original pattern CSS if site css already combined — look for pattern-{id}.css
$pattern = trailingslashit( wp_upload_dir()['basedir'] ) . 'dxai-ui/pattern-' . $home_id . '.css';
$source_css_url = is_readable( $pattern )
	? trailingslashit( wp_upload_dir()['baseurl'] ) . 'dxai-ui/pattern-' . $home_id . '.css'
	: $home_css;

$site = DXAI_UI\Structures\Live_Content_Shell::persist_site_css( $home_id, $source_css_url );
if ( is_wp_error( $site ) ) {
	fwrite( STDERR, 'css_err=' . $site->get_error_message() . PHP_EOL );
	exit( 1 );
}
// Keep Home on its original pattern sheet — only children use site CSS.
echo 'site_css=' . $site . PHP_EOL;

// Home BODY only (skip header/footer chrome) — clean design context for DeepSeek.
$home_content = (string) get_post_field( 'post_content', $home_id );
$body_html    = preg_replace( '/<!--\s*wp:template-part\b[^>]*-->/i', '', $home_content ) ?? $home_content;
$guide        = DXAI_UI\Structures\Live_Page_Restyler::home_guide(
	array(
		array(
			'type'        => 'section',
			'source_html' => substr( $body_html, 0, 18000 ),
		),
	),
	is_readable( $pattern ) ? (string) file_get_contents( $pattern ) : '',
	(string) get_the_title( $home_id )
);
echo 'guide_colors=' . count( $guide['allowed_colors'] ?? array() ) . ' recipes=' . count( $guide['style_recipes'] ?? array() ) . PHP_EOL;

$restyler = new DXAI_UI\Structures\Live_Page_Restyler();
$mapper   = new DXAI_UI\Compiler\Gutenberg_Mapper();
$parts    = new DXAI_UI\Structures\Template_Part_Factory();
$header_ref = '';
$footer_ref = '';
$home_content = (string) get_post_field( 'post_content', $home_id );
$part_key     = '';
if ( preg_match( '/"slug":"(dxai-header-[^"]+)"/', $home_content, $hm ) ) {
	$part_key   = preg_replace( '/^dxai-header-/', '', $hm[1] ) ?? '';
	$header_ref = $parts->reference_markup( 'header', $part_key );
}
if ( preg_match( '/"slug":"(dxai-footer-[^"]+)"/', $home_content, $fm ) ) {
	$key        = preg_replace( '/^dxai-footer-/', '', $fm[1] ) ?? '';
	$footer_ref = $parts->reference_markup( 'footer', $key !== '' ? $key : $part_key );
}
if ( $header_ref === '' ) {
	$header_ref = $parts->reference_markup( 'header', $part_key );
}
if ( $footer_ref === '' ) {
	$footer_ref = $parts->reference_markup( 'footer', $part_key );
}
$home_wrap  = (string) get_post_meta( $home_id, '_dxai_ui_wrapper_class', true );
$home_fonts = get_post_meta( $home_id, '_dxai_ui_font_urls', true );
$home_fonts = is_array( $home_fonts ) ? $home_fonts : array();
$home_js    = (string) get_post_meta( $home_id, '_dxai_ui_js_url', true );
$static     = (bool) get_post_meta( $home_id, '_dxai_ui_static_html', true );

$fetcher = new DXAI_UI\Connectors\Live_Page_Fetcher();
$ok      = 0;
$fail    = 0;

foreach ( $children as $child ) {
	$path = '/' . ltrim( (string) get_post_meta( $child->ID, '_dxai_ui_source_route', true ), '/' );
	if ( $path === '/' ) {
		$path = '/' . str_replace( 'dxai-', '', (string) $child->post_name );
	}
	$url = 'https://h2oaway.com' . ( $path === '/' ? '/' : $path . '/' );
	echo 'restyle ' . $child->post_title . ' ' . $url . PHP_EOL;

	$fetched = $fetcher->fetch( $url );
	if ( is_wp_error( $fetched ) ) {
		echo '  fetch_err=' . $fetched->get_error_message() . PHP_EOL;
		++$fail;
		continue;
	}
	$html = DXAI_UI\Structures\Live_Content_Shell::prepare_html( (string) ( $fetched['html'] ?? '' ) );
	$imgs = is_array( $fetched['images'] ?? null ) ? $fetched['images'] : array();
	$local_imgs = array();
	foreach ( $imgs as $img ) {
		$ing = DXAI_UI\Media\Sideloader::from_url( $img );
		if ( ! is_wp_error( $ing ) && ! empty( $ing['url'] ) ) {
			$html         = str_replace( $img, (string) $ing['url'], $html );
			$local_imgs[] = (string) $ing['url'];
		}
	}

	$styled = $restyler->restyle(
		array(
			'title'  => (string) $child->post_title,
			'path'   => $path,
			'html'   => $html,
			'images' => $local_imgs,
		),
		$guide
	);
	if ( is_wp_error( $styled ) ) {
		echo '  restyle_err=' . $styled->get_error_message() . PHP_EOL;
		$body = $restyler->pass1( $html );
	} else {
		$body = (string) $styled['markup'];
		echo '  engine=' . (string) ( $styled['engine'] ?? '' ) . PHP_EOL;
	}
	if ( trim( $body ) === '' ) {
		++$fail;
		continue;
	}

	$content = trim( implode( "\n", array_filter( array( $header_ref, $body, $footer_ref ) ) ) );
	$wrapped = $mapper->wrap_scope( $content, $home_id, $home_wrap );
	wp_update_post(
		array(
			'ID'           => $child->ID,
			'post_content' => wp_slash( $wrapped ),
		)
	);
	update_post_meta( $child->ID, '_dxai_ui_css_url', DXAI_UI\Support\Upload_Paths::for_storage( (string) $site ) );
	update_post_meta( $child->ID, '_dxai_ui_wrapper_class', $home_wrap );
	update_post_meta( $child->ID, '_dxai_ui_font_urls', $home_fonts );
	if ( $home_js !== '' ) {
		update_post_meta( $child->ID, '_dxai_ui_js_url', DXAI_UI\Support\Upload_Paths::for_storage( $home_js ) );
	}
	if ( $static ) {
		update_post_meta( $child->ID, '_dxai_ui_static_html', '1' );
	}
	++$ok;
}

echo "done ok={$ok} fail={$fail}\n";
exit( $ok > 0 ? 0 : 2 );
