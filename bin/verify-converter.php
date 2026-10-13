<?php
/**
 * Exercise process-source and save-pattern without calling an LLM.
 *
 * Run: wp eval-file bin/verify-converter.php --user=1
 *
 * Compiles a pasted component, saves a synced pattern from markup and reads the library back, then removes what it made
 * (bin/verify-cleanup.php). Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require DXAI_UI_DIR . 'bin/verify-cleanup.php';
$dxai_snapshot = dxai_verify_snapshot();

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

$process = new WP_REST_Request( 'POST', '/dxai-ui/v1/process-source' );
$process->set_param( 'source_type', 'lovable-code' );
$process->set_param( 'code', 'export default function Hero(){ return <section className="flex flex-col gap-6 p-8 bg-slate-900 text-white"><h1 className="text-4xl font-bold">Hello</h1></section>; }' );
$process->set_param( 'title', 'Hero snippet' );
$processed = rest_do_request( $process );
$kind      = (string) ( $processed->get_data()['source']['kind'] ?? '' );
$expect( 'a pasted component compiles (process-source answers 200)', 200 === $processed->get_status(), 'status ' . $processed->get_status() );
$expect( 'and is read as pasted code', $kind !== '', 'kind "' . $kind . '"' );

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
$saved   = rest_do_request( $save );
$pattern = $saved->get_data()['pattern'] ?? array();
$expect( 'markup is saved as a synced pattern (save-pattern answers 200 with an id)', 200 === $saved->get_status() && (int) ( $pattern['id'] ?? 0 ) > 0, 'status ' . $saved->get_status() . ', id ' . (int) ( $pattern['id'] ?? 0 ) );
$expect( 'the pattern carries the compiled styles of its classes', ! empty( $pattern['css'] ) );

$list = rest_do_request( new WP_REST_Request( 'GET', '/dxai-ui/v1/patterns' ) );
$ids  = array_map( static fn( $p ) => (int) ( $p['id'] ?? 0 ), (array) ( $list->get_data()['patterns'] ?? array() ) );
$expect( 'and the library lists it', in_array( (int) ( $pattern['id'] ?? 0 ), $ids, true ), count( $ids ) . ' patterns listed' );

dxai_verify_cleanup( $dxai_snapshot );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
