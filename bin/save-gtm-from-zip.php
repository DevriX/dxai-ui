<?php
/**
 * Compile GTM Strategy Hub ZIP and save as a WordPress page.
 * Run: wp eval-file bin/save-gtm-from-zip.php
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$zip = 'C:\\Users\\DevriX\\Downloads\\GTM Strategy Hub.zip';
if ( ! is_readable( $zip ) ) {
	echo "zip_missing=1\n";
	return;
}

$source = ( new DXAI_UI\Connectors\Lovable_Connector() )->import_zip(
	array(
		'name'     => 'GTM Strategy Hub.zip',
		'tmp_name' => $zip,
		'error'    => 0,
		'size'     => filesize( $zip ),
	)
);
if ( is_wp_error( $source ) ) {
	echo 'import_error=' . $source->get_error_message() . PHP_EOL;
	return;
}

$compiled = ( new DXAI_UI\Compiler\Source_Compiler() )->compile( $source );
$compiled['scope'] = 'page';
echo 'compiler=' . ( $compiled['compiler'] ?? '' ) . PHP_EOL;
echo 'structures=' . count( $compiled['structures'] ?? array() ) . PHP_EOL;
echo 'css=' . strlen( (string) ( $compiled['custom_css'] ?? '' ) ) . PHP_EOL;
echo 'js=' . strlen( (string) ( $compiled['custom_js'] ?? '' ) ) . PHP_EOL;
echo 'fonts=' . count( $compiled['design_assets']['fonts'] ?? array() ) . PHP_EOL;
echo 'images=' . count( $compiled['design_assets']['images'] ?? array() ) . PHP_EOL;

if ( empty( $compiled['structures'] ) ) {
	echo "save_skipped=1\n";
	return;
}

$existing = get_post( 130 );
if ( $existing instanceof WP_Post && 'page' === $existing->post_type ) {
	$slug = sanitize_title( (string) $compiled['block_title'] );
	$slug = str_starts_with( $slug, 'dxai-' ) ? $slug : 'dxai-' . $slug;
	wp_update_post(
		array(
			'ID'         => 130,
			'post_title' => (string) $compiled['block_title'],
			'post_name'  => $slug,
		)
	);
}

$created = ( new DXAI_UI\Structures\Structure_Repository() )->save( $compiled );
if ( is_wp_error( $created ) ) {
	echo 'save_error=' . $created->get_error_message() . PHP_EOL;
	return;
}

$page_id = (int) ( $created['page_id'] ?? 0 );
echo 'page_id=' . $page_id . PHP_EOL;
echo 'page_url=' . ( $page_id ? get_permalink( $page_id ) : '' ) . PHP_EOL;
echo 'edit_url=' . ( $page_id ? get_edit_post_link( $page_id, 'raw' ) : '' ) . PHP_EOL;
echo 'template=' . (string) get_post_meta( $page_id, '_wp_page_template', true ) . PHP_EOL;
echo 'css_url=' . (string) get_post_meta( $page_id, '_dxai_ui_css_url', true ) . PHP_EOL;
echo 'js_url=' . (string) get_post_meta( $page_id, '_dxai_ui_js_url', true ) . PHP_EOL;
