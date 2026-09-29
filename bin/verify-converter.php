<?php
/**
 * Exercise process-source and save-pattern without calling an LLM.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

$process = new WP_REST_Request( 'POST', '/dxai-ui/v1/process-source' );
$process->set_param( 'source_type', 'lovable-code' );
$process->set_param( 'code', 'export default function Hero(){ return <section className="flex flex-col gap-6 p-8 bg-slate-900 text-white"><h1 className="text-4xl font-bold">Hello</h1></section>; }' );
$process->set_param( 'title', 'Hero snippet' );
$processed = rest_do_request( $process );
echo 'process_status=' . $processed->get_status() . PHP_EOL;
echo 'process_kind=' . ( $processed->get_data()['source']['kind'] ?? '' ) . PHP_EOL;

$markup = '<!-- wp:group {"className":"flex flex-col gap-6 p-8 bg-slate-900 text-white","layout":{"type":"constrained"}} -->'
	. '<div class="wp-block-group flex flex-col gap-6 p-8 bg-slate-900 text-white">'
	. '<!-- wp:heading {"className":"text-4xl font-bold"} -->'
	. '<h2 class="wp-block-heading text-4xl font-bold">Hello</h2>'
	. '<!-- /wp:heading -->'
	. '</div><!-- /wp:group -->';

$save = new WP_REST_Request( 'POST', '/dxai-ui/v1/save-pattern' );
$save->set_param( 'block_title', 'DXAI UI Smoke Pattern' );
$save->set_param( 'gutenberg_markup', $markup );
$save->set_param( 'custom_css', '' );
$save->set_param( 'synced', true );
$saved = rest_do_request( $save );
echo 'save_status=' . $saved->get_status() . PHP_EOL;
$pattern = $saved->get_data()['pattern'] ?? array();
echo 'pattern_id=' . ( $pattern['id'] ?? 0 ) . PHP_EOL;
echo 'pattern_css=' . ( empty( $pattern['css'] ) ? 'missing' : 'set' ) . PHP_EOL;

$list = rest_do_request( new WP_REST_Request( 'GET', '/dxai-ui/v1/patterns' ) );
echo 'library_count=' . count( $list->get_data()['patterns'] ?? array() ) . PHP_EOL;

dxai_verify_cleanup( $dxai_snapshot );
