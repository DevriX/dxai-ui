<?php
/**
 * One-shot installer check: the plugin is active, its REST routes are there and the two it needs first answer.
 *
 * Run: wp eval-file bin/verify-bootstrap.php --user=1
 *
 * Prints what it finds (plugin, PHP, WordPress, the routes) and checks it. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User && ! is_user_logged_in() ) {
	wp_set_current_user( $user->ID );
}

echo 'php=' . PHP_VERSION . PHP_EOL;
echo 'wp=' . get_bloginfo( 'version' ) . PHP_EOL;
$active = is_plugin_active( 'dxai-ui/dxai-ui.php' ) || is_plugin_active( basename( dirname( __DIR__ ) ) . '/dxai-ui.php' );
$expect( 'the plugin is active', $active );

$routes = array_values( array_filter( array_keys( rest_get_server()->get_routes() ), static fn( string $route ): bool => str_contains( $route, 'dxai-ui' ) ) );
echo 'routes=' . count( $routes ) . PHP_EOL;
$expect( 'its REST routes are registered', count( $routes ) > 10, count( $routes ) . ' routes' );

$settings = rest_do_request( new WP_REST_Request( 'GET', '/dxai-ui/v1/settings' ) );
$data     = $settings->get_data();
echo 'active_engine=' . ( $data['settings']['active_engine'] ?? '' ) . PHP_EOL;
$expect( 'GET /dxai-ui/v1/settings answers 200 with the model catalog', 200 === $settings->get_status() && isset( $data['catalog'] ), 'status ' . $settings->get_status() );

$patterns = rest_do_request( new WP_REST_Request( 'GET', '/dxai-ui/v1/patterns' ) );
$expect( 'GET /dxai-ui/v1/patterns answers 200', 200 === $patterns->get_status(), 'status ' . $patterns->get_status() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
