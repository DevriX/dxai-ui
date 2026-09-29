<?php
/**
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? 'C:\\Users\\DevriX\\Local Sites\\DXAI-UI\\app\\public';
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

foreach ( array( 88263 => 'contact', 88258 => 'about', 88029 => 'services' ) as $id => $name ) {
	$c = (string) get_post_field( 'post_content', $id );
	echo "=== $name #$id ===\n";
	echo 'len=' . strlen( $c ) . ' imgs=' . ( substr_count( $c, '<img' ) + substr_count( $c, 'wp:image' ) ) . PHP_EOL;
	echo 'has_tailwind=' . ( preg_match( '/\b(?:text-|bg-|py-|px-|max-w-|rounded-|font-|flex|grid)\b/', $c ) ? '1' : '0' ) . PHP_EOL;
	echo 'has_live=' . ( str_contains( $c, 'dxai-live-content' ) ? '1' : '0' ) . PHP_EOL;
	echo 'has_header=' . ( str_contains( $c, 'dxai-header' ) ? '1' : '0' ) . PHP_EOL;
	// Show body snippet without template parts.
	$body = preg_replace( '/<!-- wp:template-part[\s\S]*?\/-->/', '', $c ) ?? $c;
	echo substr( trim( $body ), 0, 450 ) . "\n\n";
}
