<?php
/**
 * Refresh GTM page motion JS after runtime changes.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$id = 343;
$js = DXAI_UI\Compiler\Motion_Runtime::javascript();
$url = ( new DXAI_UI\Compiler\Tailwind_Purger() )->persist_js( $id, $js );
echo 'js_url=' . ( is_wp_error( $url ) ? $url->get_error_message() : (string) $url ) . PHP_EOL;
echo 'js_len=' . strlen( $js ) . PHP_EOL;
echo 'has_not_label=' . ( str_contains( $js, 'p:not(.rv-hero-split-label)' ) ? '1' : '0' ) . PHP_EOL;
