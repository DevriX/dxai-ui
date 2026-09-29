<?php
/**
 * Recompile pattern CSS for the GTM page after scoper changes.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$id     = 343;
$canvas = (string) get_post_meta( $id, '_dxai_ui_canvas_html', true );
$css    = DXAI_UI\Compiler\Design_Css::prepare( (string) file_get_contents( DXAI_UI_DIR . '.tmp-gtm-hub/src/styles.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$sheet  = ( new DXAI_UI\Compiler\Tailwind_Purger() )->compile( $canvas, $css, $id );
$url    = ( new DXAI_UI\Compiler\Tailwind_Purger() )->persist( $id, $sheet );
echo 'css_url=' . ( is_wp_error( $url ) ? $url->get_error_message() : $url ) . PHP_EOL;
echo 'css_len=' . strlen( $sheet ) . PHP_EOL;
echo 'root_self=' . ( str_contains( $sheet, '.dxai-ui.dxai-ui--343.rv-root {' ) || str_contains( $sheet, '.dxai-ui.dxai-ui--343.rv-root{' ) ? '1' : '0' ) . PHP_EOL;
echo 'root_child=' . ( str_contains( $sheet, '.dxai-ui.dxai-ui--343.rv-root > section' ) ? '1' : '0' ) . PHP_EOL;
echo 'root_a=' . ( str_contains( $sheet, '.dxai-ui.dxai-ui--343.rv-root a' ) ? '1' : '0' ) . PHP_EOL;
echo 'bare_scope_bg=' . ( preg_match( '/\.dxai-ui\.dxai-ui--343 \{[^}]*background:\s*var\(--rv-black\)/', $sheet ) ? '1' : '0' ) . PHP_EOL;
