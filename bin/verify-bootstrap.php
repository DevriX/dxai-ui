<?php
/**
 * One-shot installer check. Run: wp eval-file bin/verify-bootstrap.php
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

echo 'plugin_active=' . ( is_plugin_active( 'dxai-ui/dxai-ui.php' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'php=' . PHP_VERSION . PHP_EOL;
echo 'wp=' . get_bloginfo( 'version' ) . PHP_EOL;

$routes = array_keys( rest_get_server()->get_routes() );
foreach ( $routes as $route ) {
	if ( str_contains( $route, 'dxai-ui' ) ) {
		echo 'route=' . $route . PHP_EOL;
	}
}

$request  = new WP_REST_Request( 'GET', '/dxai-ui/v1/settings' );
$response = rest_do_request( $request );
echo 'settings_status=' . $response->get_status() . PHP_EOL;
$data = $response->get_data();
echo 'has_catalog=' . ( isset( $data['catalog'] ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'active_engine=' . ( $data['settings']['active_engine'] ?? '' ) . PHP_EOL;

$request  = new WP_REST_Request( 'GET', '/dxai-ui/v1/patterns' );
$response = rest_do_request( $request );
echo 'patterns_status=' . $response->get_status() . PHP_EOL;
