<?php
/**
 * Verify admin menus, enqueue, and plugin links.
 * Run: wp eval-file bin/verify-admin-ui.php
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

do_action( 'admin_menu' );

global $menu, $submenu;
foreach ( $menu as $item ) {
	$slug = (string) ( $item[2] ?? '' );
	if ( str_contains( $slug, 'dxai' ) ) {
		echo 'menu=' . wp_strip_all_tags( (string) $item[0] ) . '|' . $slug . PHP_EOL;
	}
}
if ( isset( $submenu['dxai-ui'] ) ) {
	foreach ( $submenu['dxai-ui'] as $sub ) {
		echo 'submenu=' . wp_strip_all_tags( (string) $sub[0] ) . '|' . $sub[2] . PHP_EOL;
	}
}
if ( isset( $submenu['options-general.php'] ) ) {
	foreach ( $submenu['options-general.php'] as $sub ) {
		if ( str_contains( (string) $sub[2], 'dxai' ) ) {
			echo 'settings_menu=' . wp_strip_all_tags( (string) $sub[0] ) . '|' . $sub[2] . PHP_EOL;
		}
	}
}

$_GET['page'] = 'dxai-ui';
do_action( 'admin_enqueue_scripts', 'toplevel_page_dxai-ui' );
$scripts = wp_scripts();
echo 'script_enqueued=' . ( wp_script_is( 'dxai-ui-admin', 'enqueued' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'script_src=' . ( isset( $scripts->registered['dxai-ui-admin'] ) ? (string) $scripts->registered['dxai-ui-admin']->src : 'missing' ) . PHP_EOL;
echo 'has_build=' . ( str_contains( (string) ( $scripts->registered['dxai-ui-admin']->src ?? '' ), 'assets/build/index.js' ) ? 'yes' : 'no' ) . PHP_EOL;

$dash = new DXAI_UI\Admin\Dashboard();
ob_start();
$dash->render();
$html = ob_get_clean();
echo 'has_app_root=' . ( str_contains( $html, 'id="dxai-ui-app"' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'has_php_fallback=' . ( str_contains( $html, 'dxai-ui-php-fallback' ) ? 'yes' : 'no' ) . PHP_EOL;

$plugin = get_plugin_data( DXAI_UI_FILE );
echo 'plugin_version=' . (string) ( $plugin['Version'] ?? '' ) . PHP_EOL;

$actions = apply_filters( 'plugin_action_links_' . plugin_basename( DXAI_UI_FILE ), array() );
echo 'action_links=' . wp_strip_all_tags( implode( ',', $actions ) ) . PHP_EOL;
