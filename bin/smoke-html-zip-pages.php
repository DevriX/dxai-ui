<?php
/**
 * Smoke: multi-HTML ZIP route discovery + slug mapping (no WordPress DB).
 *
 * Usage: php bin/smoke-html-zip-pages.php
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 2 );
}

$root = dirname( __DIR__ );
if ( ! defined( 'DXAI_UI_DIR' ) ) {
	define( 'DXAI_UI_DIR', $root . '/' );
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';

		return trim( $title, '-' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( wp_strip_all_tags( $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return trim( preg_replace( '/<[^>]*>/', '', $text ) ?? '' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/** @return mixed */
	function wp_parse_url( string $url, int $component = -1 ) {
		return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
	}
}

require_once $root . '/src/Autoloader.php';
DXAI_UI\Autoloader::register();

use DXAI_UI\Connectors\Html_Zip_Pages;
use DXAI_UI\Connectors\Site_Origin;

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

$assert( Html_Zip_Pages::slug_from_path( 'index.html' ) === '/', 'index.html → /' );
$assert( Html_Zip_Pages::slug_from_path( 'about.html' ) === '/about', 'about.html → /about' );
$assert( Html_Zip_Pages::slug_from_path( 'about/index.html' ) === '/about', 'about/index.html → /about' );
$assert( Html_Zip_Pages::slug_from_path( 'services/plumbing.html' ) === '/services/plumbing', 'nested html path' );
$assert( Html_Zip_Pages::slug_from_path( 'dist/index.html' ) === '/', 'dist/index.html → /' );
$assert( Html_Zip_Pages::slug_from_path( 'public/contact/index.html' ) === '/contact', 'public/contact/index.html' );

$sources = array(
	'index.html'           => '<html><head><title>Home Co</title></head><body><h1>Home</h1></body></html>',
	'about.html'           => '<html><head><title>About Us</title></head><body><h1>About</h1></body></html>',
	'contact/index.html'   => '<html><head><title>Contact</title></head><body><h1>Hi</h1></body></html>',
	'partials/header.html' => '<html><body>nav</body></html>',
);
$pages = Html_Zip_Pages::from_sources( $sources );
$slugs = array_column( $pages, 'slug' );
$assert( count( $pages ) === 3, 'partials excluded; 3 routes' );
$assert( ( $pages[0]['slug'] ?? '' ) === '/', 'home is first' );
$assert( in_array( '/about', $slugs, true ), 'has /about' );
$assert( in_array( '/contact', $slugs, true ), 'has /contact' );
$assert( Html_Zip_Pages::is_html_page( 'about.html', $sources['about.html'] ), 'detect html page' );
$assert( ! Html_Zip_Pages::is_html_page( 'x.tsx', 'export default function(){ return (<div/>); }' ), 'tsx not html' );

// Prefer-ZIP path matching uses Site_Origin::path_of
$assert( Site_Origin::path_of( '/about/' ) === '/about', 'path_of strips slash' );
$assert( Site_Origin::path_of( 'https://ex.com/about' ) === '/about', 'path_of from url' );

echo "\n{$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
