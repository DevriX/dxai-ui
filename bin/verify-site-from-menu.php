<?php
/**
 * Verify site-from-menu crawl against the H2O Away ZIP.
 *
 * Usage:
 *   php bin/verify-site-from-menu.php <wp-root> [zip-path]
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_root = $argv[1] ?? '';
$zip     = $argv[2] ?? 'C:\\Users\\DevriX\\Downloads\\H2oaway homepage redesign (1).zip';

if ( $wp_root === '' ) {
	fwrite( STDERR, "usage: php bin/verify-site-from-menu.php <wp-root> [zip]\n" );
	exit( 1 );
}

$wp_load = rtrim( $wp_root, "/\\" ) . DIRECTORY_SEPARATOR . 'wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load missing: {$wp_load}\n" );
	exit( 1 );
}
if ( ! is_readable( $zip ) ) {
	fwrite( STDERR, "zip missing: {$zip}\n" );
	exit( 1 );
}

require $wp_load;

if ( function_exists( 'get_user_by' ) ) {
	$user = get_user_by( 'login', 'admin' );
	if ( $user instanceof WP_User ) {
		wp_set_current_user( $user->ID );
	}
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if ( function_exists( 'set_time_limit' ) ) {
	set_time_limit( 0 );
}

$source = DXAI_UI\Connectors\Zip_Router::import(
	array(
		'name'     => basename( $zip ),
		'tmp_name' => $zip,
		'error'    => 0,
		'size'     => filesize( $zip ),
	)
);
if ( is_wp_error( $source ) ) {
	fwrite( STDERR, 'import_error=' . $source->get_error_message() . PHP_EOL );
	exit( 1 );
}

$compiled = ( new DXAI_UI\Compiler\Source_Compiler() )->compile( $source );
$compiled['scope']                 = 'site';
$compiled['create_missing_pages']  = true;
$compiled['source_name']           = basename( $zip );

$trees = array();
foreach ( is_array( $compiled['structures'] ?? null ) ? $compiled['structures'] : array() as $s ) {
	if ( ! is_array( $s ) ) {
		continue;
	}
	$type = (string) ( $s['type'] ?? '' );
	$tree = is_array( $s['menu_tree'] ?? null ) ? $s['menu_tree'] : array();
	echo 'structure=' . $type . ' menu_items=' . count( $s['menu_items'] ?? array() ) . ' menu_tree=' . count( $tree ) . PHP_EOL;
	if ( in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
		$trees = array_merge( $trees, $tree );
	}
}

$origin = DXAI_UI\Connectors\Site_Origin::from_menu_tree( $trees );
$cands  = DXAI_UI\Connectors\Site_Origin::candidates( $trees, $origin );
echo 'origin=' . $origin . PHP_EOL;
echo 'candidates=' . count( $cands ) . PHP_EOL;
foreach ( array_slice( $cands, 0, 15 ) as $c ) {
	echo '  candidate ' . ( $c['path'] ?? '' ) . ' | ' . ( $c['label'] ?? '' ) . PHP_EOL;
}

$created = ( new DXAI_UI\Structures\Structure_Repository() )->save( $compiled );
if ( is_wp_error( $created ) ) {
	fwrite( STDERR, 'save_error=' . $created->get_error_message() . PHP_EOL );
	exit( 1 );
}

$page_id = (int) ( $created['page_id'] ?? 0 );
echo 'home_id=' . $page_id . PHP_EOL;
echo 'home_url=' . ( $page_id ? get_permalink( $page_id ) : '' ) . PHP_EOL;
echo 'pages_created=' . count( $created['pages_created'] ?? array() ) . PHP_EOL;
echo 'pages_restyled=' . (int) ( $created['pages_restyled'] ?? 0 ) . PHP_EOL;
echo 'restyle_engine=' . (string) ( $created['restyle_engine'] ?? '' ) . PHP_EOL;
echo 'pages_skipped=' . count( $created['pages_skipped'] ?? array() ) . PHP_EOL;
echo 'crawl_errors=' . count( $created['crawl_errors'] ?? array() ) . PHP_EOL;

foreach ( is_array( $created['pages_created'] ?? null ) ? $created['pages_created'] : array() as $row ) {
	$id = (int) ( $row['id'] ?? 0 );
	echo '  page ' . ( $row['path'] ?? '' ) . ' => #' . $id . ' ' . ( $id ? get_permalink( $id ) : '' ) . PHP_EOL;
}
foreach ( is_array( $created['crawl_errors'] ?? null ) ? $created['crawl_errors'] : array() as $err ) {
	echo '  error ' . ( $err['url'] ?? '' ) . ' :: ' . ( $err['message'] ?? '' ) . PHP_EOL;
}

// Spot-check header template part for rewritten Contact link.
$header_id = (int) ( $created['header_id'] ?? 0 );
if ( $header_id > 0 ) {
	$content = (string) get_post_field( 'post_content', $header_id );
	$has_abs = str_contains( $content, 'https://h2oaway.com/' );
	$has_wp  = (bool) preg_match( '#href="[^"]*(?:\?page_id=|/dxai-)#', $content );
	echo 'header_still_has_h2oaway=' . ( $has_abs ? '1' : '0' ) . PHP_EOL;
	echo 'header_has_wp_link=' . ( $has_wp ? '1' : '0' ) . PHP_EOL;
}

$sample = $created['pages_created'][0] ?? null;
if ( is_array( $sample ) ) {
	$sid = (int) ( $sample['id'] ?? 0 );
	$sc  = (string) get_post_field( 'post_content', $sid );
	echo 'sample_path=' . ( $sample['path'] ?? '' ) . PHP_EOL;
	echo 'sample_has_live_shell=' . ( str_contains( $sc, 'dxai-live-content' ) ? '1' : '0' ) . PHP_EOL;
	echo 'sample_has_header_ref=' . ( str_contains( $sc, 'dxai-header' ) ? '1' : '0' ) . PHP_EOL;
	echo 'sample_css=' . (string) get_post_meta( $sid, '_dxai_ui_css_url', true ) . PHP_EOL;
	echo 'sample_scope_home=' . ( str_contains( $sc, 'dxai-ui--' . $page_id ) ? '1' : '0' ) . PHP_EOL;
	echo 'home_css=' . (string) get_post_meta( $page_id, '_dxai_ui_css_url', true ) . PHP_EOL;
	echo 'shared_site_css=' . ( (string) get_post_meta( $sid, '_dxai_ui_css_url', true ) === (string) get_post_meta( $page_id, '_dxai_ui_css_url', true ) ? '1' : '0' ) . PHP_EOL;
}

$ok = $page_id > 0
	&& count( $created['pages_created'] ?? array() ) >= 3
	&& (int) ( $created['pages_restyled'] ?? 0 ) >= 1
	&& ( ! empty( $sample ) && str_contains( (string) get_post_field( 'post_content', (int) $sample['id'] ), 'dxai-live-content' ) );
echo 'verify=' . ( $ok ? 'ok' : 'fail' ) . PHP_EOL;
exit( $ok ? 0 : 2 );
