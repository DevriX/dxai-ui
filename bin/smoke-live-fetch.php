<?php
/**
 * Smoke: live scrape extract / origin / challenge detection without network.
 *
 * Bootstraps the plugin autoloader with light WordPress shims (no MySQL).
 *
 * Usage: php bin/smoke-live-fetch.php
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 2 );
}

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'DXAI_UI_DIR' ) ) {
	define( 'DXAI_UI_DIR', $root . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return trim( preg_replace( '/<[^>]*>/', '', $text ) ?? '' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://dxai-ui.local' . $path;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.domainFound
		return $text;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: $url;
	}
}

require_once $root . '/src/Autoloader.php';
DXAI_UI\Autoloader::register();

use DXAI_UI\Connectors\Live_Page_Fetcher;
use DXAI_UI\Connectors\Site_Origin;
use DXAI_UI\Pages\Html_Main;

$fail = 0;
$ok   = 0;

$assert = static function ( bool $cond, string $msg ) use ( &$fail, &$ok ): void {
	if ( $cond ) {
		echo "OK  {$msg}\n";
		++$ok;
	} else {
		echo "FAIL {$msg}\n";
		++$fail;
	}
};

$fixtures = $root . '/fixtures/live-scrape';
$fetcher  = new Live_Page_Fetcher();

$webflow = (string) file_get_contents( $fixtures . '/webflow-about.html' );
$out     = $fetcher->extract( $webflow, 'https://studio.example/' );
$assert( str_contains( $out['html'], 'About Our Studio' ), 'webflow extract keeps hero title' );
$assert( str_contains( $out['html'], 'Our story' ), 'webflow extract keeps section copy' );
$assert( ! str_contains( $out['html'], 'w-nav' ), 'webflow extract drops site navbar chrome' );

$hero = (string) file_get_contents( $fixtures . '/hero-in-header.html' );
$out  = $fetcher->extract( $hero, 'https://example.com/' );
$assert( str_contains( $out['html'], 'Water damage restoration' ), 'hero-in-header keeps page hero' );
$assert( ! str_contains( strtolower( $out['html'] ), 'masthead' ), 'hero-in-header drops site masthead' );

$nitro = (string) file_get_contents( $fixtures . '/nitro-lazy.html' );
$out   = $fetcher->extract( $nitro, 'https://example.com/gallery/' );
$assert( in_array( 'https://cdn.example.com/photo-1.jpg', $out['images'], true ), 'nitro-lazy-src collected' );
$assert( in_array( 'https://cdn.example.com/photo-2.jpg', $out['images'], true ), 'data-src collected' );
$assert( in_array( 'https://cdn.example.com/banner.jpg', $out['images'], true ), 'data-bg collected' );

$spa = (string) file_get_contents( $fixtures . '/spa-root.html' );
$assert( Html_Main::is_spa_shell( $spa ), 'spa-root detected as SPA shell' );
$assert( ! Html_Main::is_spa_shell( $webflow ), 'webflow is not SPA shell' );

$cf = (string) file_get_contents( $fixtures . '/cloudflare-challenge.html' );
$assert( Html_Main::is_challenge( $cf ), 'cloudflare challenge detected' );
$assert( ! Html_Main::is_challenge( $webflow ), 'webflow is not challenge' );

$origin = 'https://example.com';
$assert( Site_Origin::absolutize( '/about', $origin ) === 'https://example.com/about', 'root-relative /about' );
$assert( Site_Origin::absolutize( 'services', $origin ) === 'https://example.com/services', 'relative services' );
$assert( Site_Origin::absolutize( '#/contact', $origin ) === 'https://example.com/contact', 'hash route #/contact' );
$assert( Site_Origin::absolutize( 'https://example.com/#/blog', $origin ) === 'https://example.com/blog', 'absolute hash route' );
$assert( Site_Origin::absolutize( 'https://example.com/?page_id=12', $origin ) === 'https://example.com/?page_id=12', 'keeps query string' );
$assert( Site_Origin::absolutize( 'https://example.com/about#team', $origin ) === 'https://example.com/about', 'strips fragment only' );

$tree = array(
	array( 'label' => 'About', 'url' => '/about', 'children' => array() ),
	array( 'label' => 'Services', 'url' => 'services', 'children' => array() ),
	array( 'label' => 'Contact', 'url' => '#/contact', 'children' => array() ),
	array( 'label' => 'Home', 'url' => '/', 'children' => array() ),
);
$cands = Site_Origin::candidates( $tree, $origin );
$paths = array_column( $cands, 'path' );
$assert( in_array( '/about', $paths, true ), 'candidates include /about' );
$assert( in_array( '/services', $paths, true ), 'candidates include /services' );
$assert( in_array( '/contact', $paths, true ), 'candidates include hash /contact' );
$assert( ! in_array( '/', $paths, true ), 'candidates skip home' );

echo "\n{$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
