<?php
/**
 * Diagnose Home CSS + block balance.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

$wp_root = $argv[1] ?? '';
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

$home_id = (int) ( $argv[2] ?? 88022 );
$base    = trailingslashit( wp_upload_dir()['basedir'] ) . 'dxai-ui/';
echo 'pattern_exists=' . ( is_readable( $base . 'pattern-' . $home_id . '.css' ) ? '1' : '0' ) . PHP_EOL;
echo 'pattern_bytes=' . ( is_readable( $base . 'pattern-' . $home_id . '.css' ) ? (string) filesize( $base . 'pattern-' . $home_id . '.css' ) : '0' ) . PHP_EOL;
echo 'site_exists=' . ( is_readable( $base . 'site-' . $home_id . '.css' ) ? '1' : '0' ) . PHP_EOL;
echo 'site_bytes=' . ( is_readable( $base . 'site-' . $home_id . '.css' ) ? (string) filesize( $base . 'site-' . $home_id . '.css' ) : '0' ) . PHP_EOL;
echo 'home_meta_css=' . (string) get_post_meta( $home_id, '_dxai_ui_css_url', true ) . PHP_EOL;

$content = (string) get_post_field( 'post_content', $home_id );
$opens   = preg_match_all( '/<!-- wp:[a-z0-9-\/]+/i', $content );
$closes  = preg_match_all( '/<!-- \/wp:/i', $content );
$self    = preg_match_all( '/<!-- wp:[^>]+\/-->/', $content );
echo "opens={$opens} closes={$closes} self_closing={$self}\n";
echo 'balance_delta=' . ( (int) $opens - (int) $self - (int) $closes ) . PHP_EOL;

$blocks = parse_blocks( $content );
echo 'top_level=' . count( $blocks ) . PHP_EOL;
foreach ( $blocks as $i => $b ) {
	$name = (string) ( $b['blockName'] ?? 'NULL_FREEFORM' );
	$html = trim( (string) ( $b['innerHTML'] ?? '' ) );
	echo $i . ' ' . $name . ' html_len=' . strlen( $html ) . PHP_EOL;
}
